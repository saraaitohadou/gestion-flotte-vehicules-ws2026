<?php

namespace App\Http\Controllers;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Exceptions\BusinessException;
use App\Http\Requests\StoreEntretienRequest;
use App\Http\Requests\UpdateEntretienRequest;
use App\Http\Resources\EntretienResource;
use App\Models\Alerte;
use App\Models\Entretien;
use App\Services\EntretienService;
use App\Services\VehiculeClient;
use App\Support\AuthUser;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EntretienController extends Controller
{
    public function __construct(
        private EntretienService $service,
        private VehiculeClient $vehicules,
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

        return new EntretienResource($entretien);
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

        // Vehicule inexistant, archive ou hors service => refuse
        $vehicule = $this->vehicules->verifierUtilisable($data['vehicule_id']);
        $this->verifierKilometrage((int) $data['kilometrage'], $vehicule);

        $entretien = Entretien::create($data + [
            'statut'     => StatutEntretien::Planifie,
            'created_by' => $user->id,
        ]);

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

        $entretien->update($request->validated());

        return new EntretienResource($entretien);
    }

    public function demarrer(Request $request, Entretien $entretien): EntretienResource
    {
        $user = AuthUser::fromRequest($request);
        $data = $request->validate(['date_debut' => ['nullable', 'date']]);

        $this->verifierTransition($entretien, StatutEntretien::EnCours);

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

        // Si le service Vehicule est injoignable : 503 et rien n'est enregistre
        $this->vehicules->changerStatut($entretien->vehicule_id, 'EN_MAINTENANCE');

        $entretien->update([
            'statut'     => StatutEntretien::EnCours,
            'date_debut' => isset($data['date_debut']) ? Carbon::parse($data['date_debut']) : now(),
            'started_by' => $user->id,
        ]);

        return new EntretienResource($entretien);
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

        $kmFourni = isset($data['kilometrage']);
        $km       = $kmFourni ? (int) $data['kilometrage'] : $entretien->kilometrage;

        if ($kmFourni) {
            $this->verifierKilometrage($km, $this->vehicules->find($entretien->vehicule_id));
        }

        $this->vehicules->changerStatut($entretien->vehicule_id, 'DISPONIBLE');
        if ($kmFourni) {
            $this->vehicules->changerKilometrage($entretien->vehicule_id, $km);
        }

        [$dateEcheance, $kmEcheance] = $this->calculerEcheance($entretien, $dateFin, $km);

        $entretien->update([
            'statut'                  => StatutEntretien::Termine,
            'date_fin'                => $dateFin,
            'kilometrage'             => $km,
            'cout'                    => $data['cout'] ?? $entretien->cout,
            'date_prochaine_echeance' => $dateEcheance,
            'km_prochaine_echeance'   => $kmEcheance,
            'finished_by'             => $user->id,
        ]);

        // L'echeance est refaite : les anciennes alertes de ce vehicule/categorie sont resolues
        Alerte::where('vehicule_id', $entretien->vehicule_id)
            ->where('categorie', $entretien->categorie)
            ->whereNull('resolue_at')
            ->update(['resolue_at' => now()]);

        return new EntretienResource($entretien);
    }

    public function annuler(Request $request, Entretien $entretien): EntretienResource
    {
        $user  = AuthUser::fromRequest($request);
        $avant = $entretien->statut;

        $this->verifierTransition($entretien, StatutEntretien::Annule);

        if ($avant === StatutEntretien::EnCours) {
            $this->vehicules->changerStatut($entretien->vehicule_id, 'DISPONIBLE');
        }

        $entretien->update([
            'statut'       => StatutEntretien::Annule,
            'cancelled_by' => $user->id,
        ]);

        return new EntretienResource($entretien);
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

    /** @return array{0: ?Carbon, 1: ?int} */
    private function calculerEcheance(Entretien $entretien, Carbon $dateFin, ?int $km): array
    {
        if ($entretien->type !== TypeEntretien::PREVENTIF) {
            return [null, null];
        }

        $regle = config('entretien.types.PREVENTIF.' . $entretien->categorie, []);

        $date  = isset($regle['mois']) ? $dateFin->copy()->addMonths((int) $regle['mois']) : null;
        $kmEch = (isset($regle['km']) && $km !== null) ? $km + (int) $regle['km'] : null;

        return [$date, $kmEch];
    }
}
