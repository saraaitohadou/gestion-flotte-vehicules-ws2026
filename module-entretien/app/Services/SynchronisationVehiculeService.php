<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Entretien;
use App\Models\EntretienHistorique;
use App\Models\IntegrationOutbox;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Synchronisation fiable Entretien <-> Véhicule (saga avec compensation).
 *
 * 1. La modification locale et la ligne d'outbox sont écrites dans UNE SEULE transaction.
 * 2. Le PATCH vers le service Véhicule n'est envoyé QU'APRÈS le commit local.
 * 3. Chaque opération (statut, kilométrage) est suivie individuellement : l'état
 *    réel du véhicule est relu avant chaque nouvelle tentative, afin qu'un PATCH
 *    déjà appliqué (réponse perdue, 409 « même état ») ne soit jamais renvoyé.
 * 4. Au-delà d'un nombre maximal de tentatives, la ligne passe en ECHEC_DEFINITIF
 *    (dead-letter) et n'est plus rejouée indéfiniment.
 * 5. La compensation n'écrase jamais un état local plus récent : elle exige un
 *    compare-and-swap sur le snapshot `entretien_apres`, sous verrou de ligne.
 */
class SynchronisationVehiculeService
{
    public function __construct(private VehiculeClient $vehicules) {}

    /** À appeler impérativement DANS la transaction locale. */
    public function planifier(
        Entretien $entretien,
        array $operations,
        array $operationsInverses,
        array $entretienAvant,
        array $entretienApres = [],
        array $etatVehiculeAvant = [],
    ): IntegrationOutbox {
        return IntegrationOutbox::create([
            'entretien_id'          => $entretien->id,
            'vehicule_id'           => $entretien->vehicule_id,
            'action'                => IntegrationOutbox::ACTION_MAJ,
            'operations'            => $operations,
            'operations_inverses'   => $operationsInverses,
            'etat_vehicule_avant'   => $this->extraireEtat($etatVehiculeAvant, $operations),
            'operations_appliquees' => [],
            'entretien_avant'       => $entretienAvant,
            'entretien_apres'       => $entretienApres,
            'idempotency_key'       => (string) Str::uuid(),
            'tentatives_max'        => (int) config('entretien.synchronisation.tentatives_max', 3),
            'statut'                => IntegrationOutbox::EN_ATTENTE,
        ]);
    }

    /**
     * Applique la mutation sur le service Véhicule (hors transaction locale).
     *
     * Une clé d'idempotence distincte est utilisée pour CHAQUE opération. Même si
     * le service Véhicule ne l'exploite pas encore, l'état courant est toujours
     * lu au début d'une nouvelle tentative : les opérations déjà observées sont
     * ignorées, ce qui rend le rejou réellement idempotent.
     */
    public function appliquer(IntegrationOutbox $outbox): void
    {
        $max       = $outbox->tentativesMax();
        $tentative = (int) $outbox->tentatives + 1;

        if ($tentative > $max) {
            $outbox->update([
                'statut'                => IntegrationOutbox::ECHEC_DEFINITIF,
                'derniere_tentative_at' => now(),
                'dernier_erreur'        => "Limite de {$max} tentatives atteinte ; ligne en dead-letter.",
            ]);
            Log::error('Synchronisation Véhicule en échec définitif', [
                'outbox_id' => $outbox->id, 'entretien_id' => $outbox->entretien_id,
            ]);

            throw new BusinessException(
                'Synchronisation Véhicule abandonnée après le nombre maximal de tentatives.',
                503,
                'SYNCHRONISATION_TENTATIVES_EPUISEES'
            );
        }

        $outbox->update([
            'tentatives'            => $tentative,
            'derniere_tentative_at' => now(),
        ]);

        try {
            $appliquees = (array) ($outbox->operations_appliquees ?? []);

            // Premier essai : on se fie au pré-vol fait par le contrôleur (aucun GET
            // supplémentaire). Ensuite : on relit TOUJOURS l'état réel avant d'agir.
            $etatCourant = ($tentative > 1 || $appliquees !== [])
                ? $this->etatVehicule((int) $outbox->vehicule_id)
                : $this->normaliserEtat((array) ($outbox->etat_vehicule_avant ?? []));

            foreach ((array) $outbox->operations as $operation) {
                $champ  = (string) ($operation['champ'] ?? '');
                $cible  = $this->normaliserValeur($champ, $operation['valeur'] ?? null);

                if ($champ === '' || isset($appliquees[$champ])) {
                    continue;
                }

                // Opération déjà visible chez Véhicule : on la trace sans la renvoyer.
                if ($etatCourant !== null && $this->estApplique($etatCourant, $champ, $cible)) {
                    $appliquees[$champ] = true;
                    $this->persisterProgression($outbox, $appliquees);
                    continue;
                }

                try {
                    $this->patch((int) $outbox->vehicule_id, $champ, $cible, $this->cleOperation($outbox, $champ));
                } catch (BusinessException $e) {
                    // Véhicule renvoie 409 pour un PATCH « même état » : ce n'est pas
                    // un échec métier si l'état cible est effectivement atteint.
                    if ($e->errorCode === 'VEHICULE_MISE_A_JOUR_REFUSEE') {
                        $etatCourant = $this->etatVehicule((int) $outbox->vehicule_id);

                        if ($etatCourant !== null && $this->estApplique($etatCourant, $champ, $cible)) {
                            $appliquees[$champ] = true;
                            $this->persisterProgression($outbox, $appliquees);
                            continue;
                        }
                    }

                    throw $e;
                }

                $appliquees[$champ] = true;
                if ($etatCourant !== null) {
                    $etatCourant[$champ] = $cible;
                }
                $this->persisterProgression($outbox, $appliquees);
            }

            $outbox->update([
                'statut'                => IntegrationOutbox::APPLIQUE,
                'applique_at'           => now(),
                'operations_appliquees' => $appliquees,
                'dernier_erreur'        => null,
            ]);
        } catch (BusinessException $e) {
            // La dernière tentative autorisée a échoué : on ne laisse pas la ligne
            // revenir indéfiniment dans la file d'attente du CRON.
            if ($tentative >= $max) {
                $outbox->update([
                    'statut'         => IntegrationOutbox::ECHEC_DEFINITIF,
                    'dernier_erreur' => "Limite de {$max} tentatives atteinte ; ligne en dead-letter.",
                ]);
                Log::error('Synchronisation Véhicule en échec définitif', [
                    'outbox_id' => $outbox->id, 'entretien_id' => $outbox->entretien_id,
                ]);
            }

            throw $e;
        }
    }

    /**
     * Restaure le véhicule puis annule la modification locale.
     *
     * @return bool true si la modification locale a été annulée, false si la
     *              compensation a été refusée/ajournée (outbox conservée).
     */
    public function compenser(IntegrationOutbox $outbox, AuthUser $user): bool
    {
        try {
            return DB::transaction(function () use ($outbox, $user) {
                $verrouillee = IntegrationOutbox::whereKey($outbox->id)->lockForUpdate()->firstOrFail();

                if (in_array($verrouillee->statut, [IntegrationOutbox::APPLIQUE, IntegrationOutbox::COMPENSE], true)) {
                    return true;
                }

                $entretien = Entretien::whereKey($verrouillee->entretien_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $apres = (array) ($verrouillee->entretien_apres ?? []);

                // Compare-and-swap : si une transition concurrente a déjà écrit
                // un état plus récent, on ne revient JAMAIS en arrière.
                if ($apres === [] || ! $this->etatCorrespond($entretien, $apres)) {
                    $verrouillee->update([
                        'statut'              => IntegrationOutbox::COMPENSATION_IGNOREE,
                        'compensation_erreur' => 'État local modifié depuis la mutation : compensation refusée.',
                    ]);
                    Log::warning('Compensation ignorée : état local évolué', [
                        'outbox_id' => $verrouillee->id, 'entretien_id' => $entretien->id,
                    ]);

                    return false;
                }

                $avant = (array) ($verrouillee->entretien_avant ?? []);
                if ($avant !== []) {
                    // Côté Véhicule : ne restaurer que ce qui a réellement été appliqué.
                    $vehicule   = $this->vehicules->find((int) $verrouillee->vehicule_id);
                    $aRestaurer = $this->inversesAppliques(
                        (array) ($verrouillee->operations ?? []),
                        (array) ($verrouillee->operations_inverses ?? []),
                        $vehicule
                    );

                    foreach ($aRestaurer as $inverse) {
                        $champ = (string) ($inverse['champ'] ?? '');
                        if ($champ === '') {
                            continue;
                        }

                        try {
                            $this->patch(
                                (int) $verrouillee->vehicule_id,
                                $champ,
                                $this->normaliserValeur($champ, $inverse['valeur'] ?? null),
                                $this->cleOperation($verrouillee, 'compensation-' . $champ)
                            );
                        } catch (BusinessException $e) {
                            // 409 = le véhicule est déjà dans l'état cible : rien à faire.
                            if ($e->errorCode !== 'VEHICULE_MISE_A_JOUR_REFUSEE') {
                                throw $e;
                            }
                        }
                    }

                    $entretien->update($avant);

                    EntretienHistorique::create([
                        'entretien_id'   => $entretien->id,
                        'ancien_statut'  => $apres['statut'] ?? null,
                        'nouveau_statut' => $avant['statut'] ?? null,
                        'action_par'     => 'system:compensation-' . ($user->id ?? 'inconnu'),
                    ]);
                }

                $verrouillee->update([
                    'statut'              => IntegrationOutbox::COMPENSE,
                    'compensation_erreur' => null,
                ]);

                return true;
            });
        } catch (BusinessException $e) {
            // État réel illisible : on ne devine PAS. Si la ligne est déjà en
            // dead-letter on la conserve ; sinon le CRON retente la mutation.
            $statutActuel = (string) IntegrationOutbox::whereKey($outbox->id)->value('statut');
            $terminal     = $statutActuel === IntegrationOutbox::ECHEC_DEFINITIF;

            IntegrationOutbox::whereKey($outbox->id)->update([
                'statut'              => $terminal ? IntegrationOutbox::ECHEC_DEFINITIF : IntegrationOutbox::EN_ATTENTE,
                'dernier_erreur'      => 'état véhicule illisible, nouvelle tentative (' . $e->errorCode . ')',
                'compensation_erreur' => $e->errorCode . ' - ' . $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Rejoue les synchronisations restées en attente (appelé par le CRON). */
    public function rejouerEnAttente(int $limit = 50): array
    {
        $stats = ['appliquees' => 0, 'echouees' => 0, 'echecs_definitifs' => 0];

        IntegrationOutbox::where('statut', IntegrationOutbox::EN_ATTENTE)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (IntegrationOutbox $outbox) use (&$stats) {
                try {
                    $this->appliquer($outbox->fresh());
                    $stats['appliquees']++;
                } catch (BusinessException) {
                    $statut = (string) IntegrationOutbox::whereKey($outbox->id)->value('statut');
                    if (in_array($statut, [IntegrationOutbox::ECHEC_DEFINITIF, IntegrationOutbox::COMPENSATION_IGNOREE], true)) {
                        $stats['echecs_definitifs']++;
                    } else {
                        $stats['echouees']++;
                    }
                }
            });

        return $stats;
    }

    private function persisterProgression(IntegrationOutbox $outbox, array $appliquees): void
    {
        $outbox->update(['operations_appliquees' => $appliquees]);
    }

    private function patch(int $vehiculeId, string $champ, mixed $valeur, string $cle): void
    {
        match ($champ) {
            'statut'      => $this->vehicules->changerStatut($vehiculeId, (string) $valeur, $cle),
            'kilometrage' => $this->vehicules->changerKilometrage($vehiculeId, (int) $valeur, $cle),
            default       => null,
        };
    }

    /** Une clé stable PAR opération : deux PATCH distincts n'ont jamais la même clé. */
    private function cleOperation(IntegrationOutbox $outbox, string $champ): string
    {
        return hash('sha256', $outbox->idempotency_key . '|' . $outbox->id . '|' . $champ);
    }

    private function etatVehicule(int $vehiculeId): ?array
    {
        try {
            return $this->normaliserEtat($this->vehicules->find($vehiculeId));
        } catch (BusinessException) {
            return null;
        }
    }

    /** Ne conserve que les champs réellement synchronisés. */
    private function normaliserEtat(array $vehicule): array
    {
        $etat = [];
        if (array_key_exists('statut', $vehicule)) {
            $etat['statut'] = strtoupper((string) $vehicule['statut']);
        }
        if (array_key_exists('kilometrage', $vehicule) && $vehicule['kilometrage'] !== null) {
            $etat['kilometrage'] = (int) $vehicule['kilometrage'];
        }

        return $etat;
    }

    private function extraireEtat(array $vehicule, array $operations): array
    {
        $champs = array_column($operations, 'champ');
        $etat   = $this->normaliserEtat($vehicule);

        return array_intersect_key($etat, array_flip($champs));
    }

    private function normaliserValeur(string $champ, mixed $valeur): mixed
    {
        if ($champ === 'statut') {
            return strtoupper((string) $valeur);
        }

        return (int) $valeur;
    }

    private function estApplique(array $etat, string $champ, mixed $cible): bool
    {
        if (! array_key_exists($champ, $etat)) {
            return false;
        }

        return $champ === 'statut'
            ? strtoupper((string) $etat[$champ]) === strtoupper((string) $cible)
            : (int) $etat[$champ] === (int) $cible;
    }

    /** Ne restaure que les champs dont la valeur cible est effectivement observée. */
    private function inversesAppliques(array $operations, array $inverses, array $vehicule): array
    {
        $parChamp = [];
        foreach ($inverses as $inverse) {
            $parChamp[(string) ($inverse['champ'] ?? '')] = $inverse;
        }

        $etat     = $this->normaliserEtat($vehicule);
        $resultat = [];
        foreach ($operations as $operation) {
            $champ = (string) ($operation['champ'] ?? '');
            if ($champ === '' || ! isset($parChamp[$champ])) {
                continue;
            }

            if ($this->estApplique($etat, $champ, $operation['valeur'] ?? null)) {
                $resultat[] = $parChamp[$champ];
            }
        }

        return $resultat;
    }

    /** Compare-and-swap sur le snapshot brut enregistré juste après la mutation. */
    private function etatCorrespond(Entretien $entretien, array $apres): bool
    {
        $attributs = $entretien->getAttributes();

        foreach ($apres as $champ => $valeur) {
            if (! array_key_exists($champ, $attributs) || (string) $attributs[$champ] !== (string) $valeur) {
                return false;
            }
        }

        return true;
    }
}
