<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Alerte;
use App\Models\Entretien;
use Carbon\Carbon;

/** Calcul quotidien des échéances et génération des alertes (appelé par le CRON). */
class EcheanceService
{
    public function __construct(private VehiculeClient $vehicules) {}

    /** @return array{verifies:int, alertes_proche:int, alertes_retard:int} */
    public function verifier(): array
    {
        $stats      = ['verifies' => 0, 'alertes_proche' => 0, 'alertes_retard' => 0];
        $aujourdhui = Carbon::today();
        $joursAvant = (int) config('entretien.alertes.jours_avant');
        $kmAvant    = (int) config('entretien.alertes.km_avant');
        $kmCache    = [];

        foreach (Entretien::derniersPreventifsAvecEcheance()->cursor() as $e) {
            $stats['verifies']++;
            $retard = [];
            $proche = [];

            if ($e->date_prochaine_echeance) {
                $jours = (int) $aujourdhui->diffInDays($e->date_prochaine_echeance->copy()->startOfDay(), false);
                if ($jours < 0) {
                    $retard[] = 'échéance du ' . $e->date_prochaine_echeance->toDateString() . ' dépassée de ' . abs($jours) . ' jour(s)';
                } elseif ($jours <= $joursAvant) {
                    $proche[] = "échéance dans {$jours} jour(s) (" . $e->date_prochaine_echeance->toDateString() . ')';
                }
            }

            if ($e->km_prochaine_echeance !== null) {
                $kmActuel = $this->kmActuel($e->vehicule_id, $kmCache);
                if ($kmActuel !== null) {
                    $restant = $e->km_prochaine_echeance - $kmActuel;
                    if ($restant < 0) {
                        $retard[] = "seuil de {$e->km_prochaine_echeance} km dépassé de " . abs($restant) . ' km';
                    } elseif ($restant <= $kmAvant) {
                        $proche[] = "plus que {$restant} km avant le seuil de {$e->km_prochaine_echeance} km";
                    }
                }
            }

            // Un entretien ne peut avoir QU'UNE alerte ouverte : EN_RETARD
            // l'emporte sur PROCHE, et toute alerte devenue sans objet est
            // résolue automatiquement lors d'un recalcul (date ou catégorie).
            $type    = $retard ? Alerte::EN_RETARD : ($proche ? Alerte::PROCHE : null);
            $raisons = $retard ?: $proche;

            $this->resoudreAlertesObsoletes($e, $type);

            if ($type !== null) {
                $this->creerAlerte($e, $type, $raisons);
                $stats[$type === Alerte::EN_RETARD ? 'alertes_retard' : 'alertes_proche']++;
            }
        }

        return $stats;
    }

    /** Ferme les alertes ouvertes qui ne correspondent plus à l'état recalculé. */
    private function resoudreAlertesObsoletes(Entretien $e, ?string $typeConserve): void
    {
        $requete = Alerte::where('entretien_id', $e->id)->whereNull('resolue_at');

        if ($typeConserve !== null) {
            $requete->where('type', '!=', $typeConserve);
        }

        $requete->update(['resolue_at' => now()]);
    }

    private function creerAlerte(Entretien $e, string $type, array $raisons): void
    {
        Alerte::updateOrCreate(
            ['entretien_id' => $e->id, 'type' => $type],
            [
                'vehicule_id'   => $e->vehicule_id,
                'categorie'     => $e->categorie,
                'message'       => "Véhicule #{$e->vehicule_id} - {$e->categorie} : " . implode(' ; ', $raisons),
                'echeance_date' => $e->date_prochaine_echeance?->toDateString(),
                'echeance_km'   => $e->km_prochaine_echeance,
                // Ne jamais réouvrir une alerte résolue manuellement : updateOrCreate
                // ne met à jour QUE le message/les échéances d'une alerte existante.
            ],
        );
    }

    /** Kilométrage actuel (null si le service Véhicule est injoignable : on ne bloque pas le CRON). */
    private function kmActuel(int $vehiculeId, array &$cache): ?int
    {
        if (! array_key_exists($vehiculeId, $cache)) {
            try {
                $donnees             = $this->vehicules->find($vehiculeId);
                $cache[$vehiculeId]  = isset($donnees['kilometrage']) ? (int) $donnees['kilometrage'] : null;
            } catch (BusinessException) {
                $cache[$vehiculeId] = null;
            }
        }

        return $cache[$vehiculeId];
    }
}
