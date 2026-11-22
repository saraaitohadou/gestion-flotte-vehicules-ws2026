<?php

namespace App\Http\Controllers;

use App\Enums\StatutEntretien;
use App\Exceptions\BusinessException;
use App\Http\Requests\StoreEntretienRequest;
use App\Http\Requests\UpdateEntretienRequest;
use App\Http\Resources\EntretienResource;
use App\Models\Alerte;
use App\Models\Entretien;
use App\Models\EntretienHistorique;
use App\Models\IntegrationOutbox;
use App\Services\EntretienService;
use App\Services\SynchronisationVehiculeService;
use App\Services\VehiculeClient;
use App\Support\AuthUser;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class EntretienController extends Controller
{
    public function __construct(
        private EntretienService $service,
        private VehiculeClient $vehicules,
        private SynchronisationVehiculeService $synchronisation,
    ) {}

    // ---------------- Lecture ----------------

    public function index(Request $request): AnonymousResourceCollection
    {
        $user       = AuthUser::fromRequest($request);
        $vehiculeId = $this->limiterAuVehicule($user, $request->integer('vehicule_id') ?: null);

        $query = Entretien::query()
            ->orderByDesc('date_entretien')->orderByDesc('id')
            ->when(! $request->boolean('inclure_archives'), fn ($q) => $q->whereNull('archive_at'))
            ->when($vehiculeId, fn ($q) => $q->where('vehicule_id', $vehiculeId))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', strtoupper((string) $request->input('statut'))))
            ->when($request->filled('type'), fn ($q) => $q->where('type', strtoupper((string) $request->input('type'))))
            ->when($request->filled('categorie'), fn ($q) => $q->where('categorie', strtoupper((string) $request->input('categorie'))));

        return EntretienResource::collection(
            $query->paginate(min(max($request->integer('per_page', 15), 1), 100))
        );
    }

    public function show(Request $request, Entretien $entretien): EntretienResource
    {
        $this->limiterAuVehicule(AuthUser::fromRequest($request), $entretien->vehicule_id);

        return new EntretienResource($entretien->load('historique'));
    }

    /** Historique complet (archives incluses). */
    public function historique(Request $request, int $vehiculeId): AnonymousResourceCollection
    {
        $this->limiterAuVehicule(AuthUser::fromRequest($request), $vehiculeId);

        return EntretienResource::collection(
            Entretien::where('vehicule_id', $vehiculeId)
                ->orderByDesc('date_entretien')->orderByDesc('id')
                ->get()
        );
    }

    // ---------------- Ecriture ----------------

    public function store(StoreEntretienRequest $request): JsonResponse
    {
        $user = AuthUser::fromRequest($request);
        $data = $request->validated();

        // Vehicule inexistant, archive, hors service OU EN_MISSION => refuse dès la création.
        $vehicule = $this->vehicules->verifierUtilisable(
            $data['vehicule_id'],
            VehiculeClient::STATUTS_INTERDITS_ENTRETIEN
        );
        $this->verifierKilometrage((int) $data['kilometrage'], $vehicule);

        $entretien = Entretien::create($data + [
            'statut'     => StatutEntretien::Planifie,
            'created_by' => $user->id,
        ]);

        $this->tracer($entretien, null, StatutEntretien::Planifie, $user);

        return (new EntretienResource($entretien))->response()->setStatusCode(201);
    }

    public function update(UpdateEntretienRequest $request, Entretien $entretien): EntretienResource
    {
        if ($entretien->estArchive()
            || ! in_array($entretien->statut, [StatutEntretien::Planifie, StatutEntretien::EnCours], true)) {
            throw new BusinessException(
                'Seul un entretien PLANIFIE ou EN_COURS (non archive) peut etre modifie.',
                409,
                'ENTRETIEN_NON_MODIFIABLE'
            );
        }

        $donnees = $request->validated();
        $ancienneCategorie = $entretien->categorie;

        // Un PUT ne peut pas baisser le compteur sous le kilométrage réel du véhicule.
        // Le service n'est interrogé que si le client modifie effectivement le km.
        if (array_key_exists('kilometrage', $donnees)) {
            $vehicule = $this->vehicules->find($entretien->vehicule_id);
            $this->verifierKilometrage((int) $donnees['kilometrage'], $vehicule);
        }

        // Changer de catégorie change la périodicité : l'échéance doit être recalculée
        // immédiatement (et non pas seulement à la prochaine clôture).
        if (array_key_exists('categorie', $donnees) && $donnees['categorie'] !== $ancienneCategorie) {
            $kmReference = $donnees['kilometrage'] ?? $entretien->kilometrage;
            [$dateEcheance, $kmEcheance] = $this->service->calculerEcheance(
                $entretien->replicate()->fill(['categorie' => $donnees['categorie']]),
                Carbon::parse($donnees['date_entretien'] ?? $entretien->date_entretien),
                $kmReference !== null ? (int) $kmReference : null
            );

            $donnees['date_prochaine_echeance'] = $dateEcheance;
            $donnees['km_prochaine_echeance']   = $kmEcheance;

            // L'ancienne échéance n'est plus d'actualité : on clôt ses alertes ouvertes.
            Alerte::where('vehicule_id', $entretien->vehicule_id)
                ->where('categorie', $ancienneCategorie)
                ->whereNull('resolue_at')
                ->update(['resolue_at' => now()]);
        }

        $entretien->update($donnees);

        return new EntretienResource($entretien);
    }

    public function demarrer(Request $request, Entretien $entretien): EntretienResource
    {
        $user = AuthUser::fromRequest($request);
        $data = $request->validate(['date_debut' => ['nullable', 'date']]);

        $this->verifierTransition($entretien, StatutEntretien::EnCours);

        // Pré-vol : si le service Véhicule est injoignable, rien n'est écrit (règle de disponibilité).
        $vehicule       = $this->vehicules->find($entretien->vehicule_id);
        $statutVehicule = strtoupper((string) ($vehicule['statut'] ?? 'DISPONIBLE'));

        // Règle §4.4 : EN_MAINTENANCE n'est atteignable que depuis DISPONIBLE.
        // Refus avant toute écriture locale et avant toute ligne d'outbox.
        if ($statutVehicule !== 'DISPONIBLE') {
            throw new BusinessException(
                "Le vehicule #{$entretien->vehicule_id} doit etre DISPONIBLE pour demarrer un entretien (statut actuel : {$statutVehicule}).",
                409,
                'VEHICULE_NON_DISPONIBLE',
                ['statut_vehicule' => $statutVehicule]
            );
        }

        $avant = $this->snapshot($entretien);

        // Écriture locale + outbox dans UNE transaction : aucun PATCH n'est envoyé avant le commit.
        $outbox = DB::transaction(function () use (&$entretien, $data, $user, $vehicule, $statutVehicule, $avant) {
            $e = $this->verrouiller($entretien->id);
            $this->verifierTransition($e, StatutEntretien::EnCours);
            $this->verifierPasDejaEnCours($e);

            $e->update([
                'statut'     => StatutEntretien::EnCours,
                'date_debut' => isset($data['date_debut']) ? Carbon::parse($data['date_debut']) : now(),
                'started_by' => $user->id,
            ]);

            $this->tracer($e, StatutEntretien::Planifie, StatutEntretien::EnCours, $user);
            $entretien = $e;

            return $this->synchronisation->planifier(
                $e,
                [['champ' => 'statut', 'valeur' => 'EN_MAINTENANCE']],
                [['champ' => 'statut', 'valeur' => $statutVehicule]],
                $avant,
                $this->snapshot($e),
                $vehicule
            );
        });

        $this->appliquerOuCompenser($outbox, $user);

        return new EntretienResource($entretien->fresh()->load('historique'));
    }

    public function terminer(Request $request, Entretien $entretien): EntretienResource
    {
        $user = AuthUser::fromRequest($request);
        $data = $request->validate([
            'date_fin'    => ['nullable', 'date'],
            'kilometrage' => ['nullable', 'integer', 'min:0'],
            'cout'        => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->verifierTransition($entretien, StatutEntretien::Termine);

        $dateFin = isset($data['date_fin']) ? Carbon::parse($data['date_fin']) : now();
        if ($entretien->date_debut && $dateFin->lt($entretien->date_debut)) {
            throw new BusinessException('date_fin ne peut pas preceder date_debut.', 422, 'DATE_FIN_INVALIDE');
        }

        // Pré-vol : état réel du véhicule (utile pour la compensation).
        $vehicule       = $this->vehicules->find($entretien->vehicule_id);
        $statutVehicule = strtoupper((string) ($vehicule['statut'] ?? 'DISPONIBLE'));
        $kmVehicule     = (int) ($vehicule['kilometrage'] ?? $entretien->kilometrage);
        $avant          = $this->snapshot($entretien);

        // Règle 7 : le compteur du véhicule est TOUJOURS mis à jour à la clôture,
        // avec le kilométrage fourni ou, à défaut, celui de l'entretien.
        $km = isset($data['kilometrage'])
            ? (int) $data['kilometrage']
            : max((int) $entretien->kilometrage, (int) ($vehicule['kilometrage'] ?? 0));
        $this->verifierKilometrage($km, $vehicule);

        $outbox = DB::transaction(function () use (&$entretien, $data, $dateFin, $km, $user, $vehicule, $statutVehicule, $kmVehicule, $avant) {
            $e = $this->verrouiller($entretien->id);
            $this->verifierTransition($e, StatutEntretien::Termine);

            [$dateEcheance, $kmEcheance] = $this->service->calculerEcheance($e, $dateFin, $km);

            $e->update([
                'statut'                  => StatutEntretien::Termine,
                'date_fin'                => $dateFin,
                'kilometrage'             => $km,
                'cout'                    => $data['cout'] ?? $e->cout,
                'date_prochaine_echeance' => $dateEcheance,
                'km_prochaine_echeance'   => $kmEcheance,
                'finished_by'             => $user->id,
            ]);

            // L'échéance est refaite : les anciennes alertes de ce véhicule/catégorie sont résolues.
            Alerte::where('vehicule_id', $e->vehicule_id)
                ->where('categorie', $e->categorie)
                ->whereNull('resolue_at')
                ->update(['resolue_at' => now()]);

            $this->tracer($e, StatutEntretien::EnCours, StatutEntretien::Termine, $user);
            $entretien = $e;

            return $this->synchronisation->planifier(
                $e,
                [
                    ['champ' => 'statut', 'valeur' => 'DISPONIBLE'],
                    ['champ' => 'kilometrage', 'valeur' => $km],
                ],
                [
                    ['champ' => 'kilometrage', 'valeur' => $kmVehicule],
                    ['champ' => 'statut', 'valeur' => $statutVehicule],
                ],
                $avant,
                $this->snapshot($e),
                $vehicule
            );
        });

        $this->appliquerOuCompenser($outbox, $user);

        return new EntretienResource($entretien->fresh()->load('historique'));
    }

    public function annuler(Request $request, Entretien $entretien): EntretienResource
    {
        $user = AuthUser::fromRequest($request);

        $this->verifierTransition($entretien, StatutEntretien::Annule);

        $avant          = $this->snapshot($entretien);
        $statutVehicule = null;
        $vehicule       = [];

        if ($entretien->statut === StatutEntretien::EnCours) {
            // Pré-vol : si le service est injoignable, aucune annulation n'est enregistrée.
            $vehicule       = $this->vehicules->find($entretien->vehicule_id);
            $statutVehicule = strtoupper((string) ($vehicule['statut'] ?? 'EN_MAINTENANCE'));
        }

        $outbox = DB::transaction(function () use (&$entretien, $user, $vehicule, $avant, $statutVehicule) {
            $e           = $this->verrouiller($entretien->id);
            $statutAvant = $e->statut;
            $this->verifierTransition($e, StatutEntretien::Annule);

            $e->update([
                'statut'       => StatutEntretien::Annule,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);

            $this->tracer($e, $statutAvant, StatutEntretien::Annule, $user);
            $entretien = $e;

            if ($statutAvant !== StatutEntretien::EnCours) {
                return null; // aucun véhicule à synchroniser
            }

            return $this->synchronisation->planifier(
                $e,
                [['champ' => 'statut', 'valeur' => 'DISPONIBLE']],
                [['champ' => 'statut', 'valeur' => $statutVehicule]],
                $avant,
                $this->snapshot($e),
                $vehicule ?? []
            );
        });

        if ($outbox !== null) {
            $this->appliquerOuCompenser($outbox, $user);
        }

        return new EntretienResource($entretien->fresh()->load('historique'));
    }

    /** Archivage (jamais de suppression physique). Route reservee a ADMINISTRATEUR. */
    public function destroy(Request $request, Entretien $entretien): EntretienResource
    {
        $user = AuthUser::fromRequest($request);

        if ($entretien->estArchive()) {
            throw new BusinessException('Entretien deja archive.', 409, 'DEJA_ARCHIVE');
        }

        if (! in_array($entretien->statut, [StatutEntretien::Termine, StatutEntretien::Annule], true)) {
            throw new BusinessException(
                'Seuls les entretiens TERMINE ou ANNULE peuvent etre archives.',
                409,
                'ARCHIVAGE_INTERDIT'
            );
        }

        $entretien->update([
            'archive_at'  => now(),
            'archived_by' => $user->id,
        ]);

        return new EntretienResource($entretien);
    }

    // ---------------- Helpers ----------------

    /** Applique la mutation ; en cas d'échec on compense puis on remonte une erreur explicite. */
    private function appliquerOuCompenser(IntegrationOutbox $outbox, AuthUser $user): void
    {
        try {
            $this->synchronisation->appliquer($outbox);
        } catch (BusinessException $e) {
            $annulee = $this->synchronisation->compenser($outbox, $user);
            $statut  = (string) IntegrationOutbox::whereKey($outbox->id)->value('statut');

            if ($annulee) {
                throw new BusinessException(
                    'Synchronisation avec le service Véhicule impossible : modification annulée.',
                    503,
                    'SYNCHRONISATION_VEHICULE_ECHOUEE',
                    ['cause' => $e->errorCode, 'outbox_id' => $outbox->id]
                );
            }

            if ($statut === IntegrationOutbox::COMPENSATION_IGNOREE) {
                throw new BusinessException(
                    'La compensation a été refusée : l’entretien a évolué entre-temps. Aucun état récent n’a été écrasé.',
                    409,
                    'COMPENSATION_IGNOREE',
                    ['cause' => $e->errorCode, 'outbox_id' => $outbox->id]
                );
            }

            if ($statut === IntegrationOutbox::ECHEC_DEFINITIF) {
                throw new BusinessException(
                    'Synchronisation Véhicule en échec définitif (dead-letter) : intervention manuelle requise.',
                    503,
                    'SYNCHRONISATION_TENTATIVES_EPUISEES',
                    ['cause' => $e->errorCode, 'outbox_id' => $outbox->id]
                );
            }

            // État du véhicule illisible : on ne devine pas, la mutation est rejouée
            // automatiquement par le CRON et l'écriture locale est conservée.
            throw new BusinessException(
                'Service Véhicule injoignable : synchronisation différée et rejouée automatiquement.',
                503,
                'SYNCHRONISATION_VEHICULE_EN_ATTENTE',
                ['cause' => $e->errorCode, 'outbox_id' => $outbox->id]
            );
        }
    }

    /** Verrouille la ligne pour éviter deux transitions concurrentes sur le même entretien. */
    private function verrouiller(int $id): Entretien
    {
        return Entretien::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function verifierPasDejaEnCours(Entretien $entretien): void
    {
        $dejaEnCours = Entretien::where('vehicule_id', $entretien->vehicule_id)
            ->where('statut', StatutEntretien::EnCours->value)
            ->where('id', '!=', $entretien->id)
            ->exists();

        if ($dejaEnCours) {
            throw new BusinessException(
                'Ce vehicule a deja un entretien EN_COURS.',
                409,
                'ENTRETIEN_DEJA_EN_COURS'
            );
        }
    }

    /** Snapshot BRUT (avant cast Eloquent) des champs modifiés par les transitions. */
    private function snapshot(Entretien $entretien): array
    {
        return array_intersect_key(
            $entretien->getAttributes(),
            array_flip([
                'statut', 'date_debut', 'date_fin', 'kilometrage', 'cout',
                'date_prochaine_echeance', 'km_prochaine_echeance',
                'started_by', 'finished_by', 'cancelled_by', 'cancelled_at',
            ])
        );
    }

    /** Chauffeur : uniquement son vehicule. Retourne l'id de vehicule a filtrer. */
    private function limiterAuVehicule(AuthUser $user, ?int $vehiculeId): ?int
    {
        if (! $user->estChauffeur()) {
            return $vehiculeId;
        }

        if ($user->vehiculeId === null || ($vehiculeId !== null && $vehiculeId !== $user->vehiculeId)) {
            throw new BusinessException(
                'Vous ne pouvez consulter que les entretiens de votre vehicule.',
                403,
                'ACCES_REFUSE'
            );
        }

        return $user->vehiculeId;
    }

    private function tracer(Entretien $entretien, ?StatutEntretien $ancien, StatutEntretien $nouveau, AuthUser $user): void
    {
        EntretienHistorique::create([
            'entretien_id'   => $entretien->id,
            'ancien_statut'  => $ancien?->value,
            'nouveau_statut' => $nouveau->value,
            'action_par'     => (string) $user->id,
        ]);
    }

    private function verifierTransition(Entretien $entretien, StatutEntretien $vers): void
    {
        if ($entretien->estArchive()) {
            throw new BusinessException('Entretien archive : aucune action possible.', 409, 'ENTRETIEN_ARCHIVE');
        }

        try {
            $this->service->changerStatut($entretien->statut, $vers);
        } catch (DomainException $e) {
            throw new BusinessException($e->getMessage(), 409, 'TRANSITION_INTERDITE');
        }
    }

    private function verifierKilometrage(int $km, array $vehicule): void
    {
        $kmVehicule = $vehicule['kilometrage'] ?? null;

        if ($kmVehicule !== null && $km < (int) $kmVehicule) {
            throw new BusinessException(
                "Le kilometrage ({$km}) est inferieur a celui du vehicule ({$kmVehicule}).",
                422,
                'KILOMETRAGE_INVALIDE'
            );
        }
    }

}
