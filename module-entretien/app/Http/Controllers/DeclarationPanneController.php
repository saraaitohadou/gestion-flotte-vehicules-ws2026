<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\DeclarationPanne;
use App\Models\Entretien;
use App\Models\EntretienHistorique;
use App\Http\Resources\DeclarationPanneResource;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use App\Services\ChauffeurClient;
use App\Services\VehiculeClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeclarationPanneController extends Controller
{
    public function __construct(
        private readonly VehiculeClient $vehicules,
        private readonly ChauffeurClient $chauffeurs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user  = AuthUser::fromRequest($request);
        $query = DeclarationPanne::query()->orderByDesc('id');

        // Un chauffeur ne voit que ses propres déclarations.
        if ($user->estChauffeur()) {
            $this->exigerIdentiteChauffeur($user);
            $query->where('chauffeur_id', $user->chauffeurId);
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->query('statut'));
        }
        if ($request->filled('vehicule_id')) {
            $vehiculeId = (int) $request->query('vehicule_id');
            $this->verifierAccesVehicule($user, $vehiculeId);
            $query->where('vehicule_id', $vehiculeId);
        }

        return response()->json(['data' => DeclarationPanneResource::collection($query->get())->resolve()]);
    }

    public function show(Request $request, DeclarationPanne $declaration): JsonResponse
    {
        $user = AuthUser::fromRequest($request);

        if ($user->estChauffeur()) {
            $this->exigerIdentiteChauffeur($user);

            if ((int) $declaration->chauffeur_id !== $user->chauffeurId) {
                throw new BusinessException('Accès refusé.', 403, 'ACCES_REFUSE');
            }
        }

        return response()->json(['data' => DeclarationPanneResource::make($declaration)->resolve()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = AuthUser::fromRequest($request);

        $regles = [
            'vehicule_id' => ['required', 'integer', 'min:1'],
            'categorie'   => ['required', 'string', Rule::in(array_keys(config('entretien.types.CORRECTIF', [])))],
            'description' => ['required', 'string'],
            'kilometrage' => ['nullable', 'integer', 'min:0'],
        ];

        // Un chauffeur ne peut jamais déclarer pour autrui : l'identité vient du JWT,
        // jamais du corps de requête. L'administrateur garde un droit de déclaration
        // pour un chauffeur explicitement désigné.
        if ($user->estChauffeur()) {
            $this->exigerIdentiteChauffeur($user);
            $chauffeurId = $user->chauffeurId;
        } else {
            $regles['chauffeur_id'] = ['required', 'integer', 'min:1'];
        }

        $data = $request->validate($regles);

        if ($user->estChauffeur()) {
            $data['chauffeur_id'] = $chauffeurId;
        }

        $this->verifierAccesVehicule($user, (int) $data['vehicule_id']);

        // 1. Vehicule : doit exister et ne pas etre HORS_SERVICE/ARCHIVE (EN_MISSION accepte)
        $this->vehicules->verifierUtilisable($data['vehicule_id']);

        // 2. Chauffeur : mode degrade si le service est injoignable (une panne est urgente)
        $verifie = true;
        try {
            $this->chauffeurs->verifierSelectionnable($data['chauffeur_id']);
        } catch (BusinessException $e) {
            if ($e->status !== 503) {
                throw $e;
            }
            $verifie = false;
        }

        $declaration = DeclarationPanne::create($data + [
            'chauffeur_verifie' => $verifie,
            'created_by'        => (string) $data['chauffeur_id'],
        ]);

        return response()->json(['data' => DeclarationPanneResource::make($declaration)->resolve()], 201);
    }

    public function prendreEnCharge(Request $request, DeclarationPanne $declaration): JsonResponse
    {
        $user = AuthUser::fromRequest($request);

        // Rejou de la même requête : on renvoie la ressource existante, sans jamais
        // créer un deuxième entretien pour la même déclaration.
        if ($declaration->statut === DeclarationPanne::TRAITEE && $declaration->entretien_id !== null) {
            return response()->json(['data' => DeclarationPanneResource::make($declaration)->resolve()]);
        }

        $this->verifierEnAttente($declaration);
        $kilometrage = $this->kilometrageDeclaration($declaration);

        $declarationTraitee = DB::transaction(function () use ($declaration, $user, $kilometrage) {
            // Verrou de ligne : deux gestionnaires ne peuvent pas traiter la même
            // déclaration en parallèle.
            $verrouillee = DeclarationPanne::whereKey($declaration->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouillee->statut === DeclarationPanne::TRAITEE && $verrouillee->entretien_id !== null) {
                return $verrouillee;
            }

            $this->verifierEnAttente($verrouillee);

            $entretien = Entretien::create([
                'vehicule_id'          => $verrouillee->vehicule_id,
                'declaration_panne_id' => $verrouillee->id,
                'type'                 => TypeEntretien::CORRECTIF,
                'categorie'            => $verrouillee->categorie,
                'description'          => $verrouillee->description,
                'date_entretien'       => now()->toDateString(),
                'kilometrage'          => $kilometrage,
                'statut'               => StatutEntretien::Planifie,
                'created_by'           => (string) $user->id,
            ]);

            EntretienHistorique::create([
                'entretien_id'   => $entretien->id,
                'ancien_statut'  => null,
                'nouveau_statut' => StatutEntretien::Planifie->value,
                'action_par'     => (string) $user->id,
            ]);

            // Update conditionnel : si l'état a changé malgré le verrou, on annule
            // la transaction plutôt que d'écraser une décision concurrente.
            $modifiees = DeclarationPanne::whereKey($verrouillee->id)
                ->where('statut', DeclarationPanne::DECLAREE)
                ->update([
                    'statut'       => DeclarationPanne::TRAITEE,
                    'entretien_id' => $entretien->id,
                    'traite_par'   => (string) $user->id,
                    'traite_at'    => now(),
                ]);

            if ($modifiees !== 1) {
                throw new BusinessException(
                    'La déclaration a été modifiée simultanément.',
                    409,
                    'DECLARATION_CONFLIT'
                );
            }

            return $verrouillee->fresh();
        });

        return response()->json(['data' => DeclarationPanneResource::make($declarationTraitee)->resolve()]);
    }

    public function rejeter(Request $request, DeclarationPanne $declaration): JsonResponse
    {
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:500']]);
        $user = AuthUser::fromRequest($request);

        $declarationRejetee = DB::transaction(function () use ($declaration, $data, $user) {
            $verrouillee = DeclarationPanne::whereKey($declaration->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->verifierEnAttente($verrouillee);

            $modifiees = DeclarationPanne::whereKey($verrouillee->id)
                ->where('statut', DeclarationPanne::DECLAREE)
                ->update([
                    'statut'      => DeclarationPanne::REJETEE,
                    'traite_par'  => (string) $user->id,
                    'traite_at'   => now(),
                    'motif_rejet' => $data['motif'] ?? null,
                ]);

            if ($modifiees !== 1) {
                throw new BusinessException(
                    'La déclaration a été modifiée simultanément.',
                    409,
                    'DECLARATION_CONFLIT'
                );
            }

            return $verrouillee->fresh();
        });

        return response()->json(['data' => DeclarationPanneResource::make($declarationRejetee)->resolve()]);
    }
    /**
     * Le km d'une déclaration est obligatoire et doit être au moins égal à celui
     * du véhicule. On ne remplace JAMAIS une valeur absente par 0 : cela
     * produirait un entretien invalide et un PATCH Véhicule refusé à la clôture.
     */
    private function kilometrageDeclaration(DeclarationPanne $declaration): int
    {
        $km = $declaration->kilometrage;

        if ($km === null || (int) $km <= 0) {
            throw new BusinessException(
                'Le kilométrage de la déclaration est requis pour créer un entretien.',
                422,
                'KILOMETRAGE_DECLARATION_MANQUANT',
                ['declaration_id' => $declaration->id]
            );
        }

        $vehicule   = $this->vehicules->find((int) $declaration->vehicule_id);
        $kmVehicule = $vehicule['kilometrage'] ?? null;

        if ($kmVehicule === null) {
            throw new BusinessException(
                'Le kilométrage du véhicule est indisponible : conversion impossible.',
                422,
                'KILOMETRAGE_VEHICULE_INDISPONIBLE',
                ['vehicule_id' => $declaration->vehicule_id]
            );
        }

        if ((int) $km < (int) $kmVehicule) {
            throw new BusinessException(
                "Le kilométrage déclaré ({$km}) est inférieur à celui du véhicule ({$kmVehicule}).",
                422,
                'KILOMETRAGE_INVALIDE',
                ['declaration_id' => $declaration->id, 'kilometrage_vehicule' => (int) $kmVehicule]
            );
        }

        return (int) $km;
    }

    /** Un token CHAUFFEUR doit porter l'identité du chauffeur (claim id_chauffeur ou sub numérique). */
    private function exigerIdentiteChauffeur(AuthUser $user): void
    {
        if ($user->chauffeurId === null) {
            throw new BusinessException(
                'Identité chauffeur absente du token.',
                403,
                'IDENTITE_CHAUFFEUR_MANQUANTE'
            );
        }
    }

    /** Si le JWT porte un véhicule, le chauffeur ne peut agir que sur celui-ci. */
    private function verifierAccesVehicule(AuthUser $user, int $vehiculeId): void
    {
        if ($user->estChauffeur()
            && $user->vehiculeId !== null
            && $user->vehiculeId !== $vehiculeId) {
            throw new BusinessException(
                'Vous ne pouvez consulter ou déclarer que votre propre véhicule.',
                403,
                'ACCES_REFUSE'
            );
        }
    }

    private function verifierEnAttente(DeclarationPanne $declaration): void
    {
        if ($declaration->statut !== DeclarationPanne::DECLAREE) {
            throw new BusinessException(
                "Declaration deja {$declaration->statut}.",
                422,
                'DECLARATION_DEJA_TRAITEE'
            );
        }
    }
}