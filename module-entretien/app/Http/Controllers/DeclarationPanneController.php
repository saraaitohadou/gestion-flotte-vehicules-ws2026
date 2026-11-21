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
        $query = DeclarationPanne::query()->orderByDesc('id');

        if ($request->filled('statut')) {
            $query->where('statut', $request->query('statut'));
        }
        if ($request->filled('vehicule_id')) {
            $query->where('vehicule_id', (int) $request->query('vehicule_id'));
        }

        return response()->json(['data' => DeclarationPanneResource::collection($query->get())->resolve()]);
    }

    public function show(DeclarationPanne $declaration): JsonResponse
    {
        return response()->json(['data' => DeclarationPanneResource::make($declaration)->resolve()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicule_id'  => ['required', 'integer', 'min:1'],
            'chauffeur_id' => ['required', 'integer', 'min:1'],
            'categorie'    => ['required', 'string', Rule::in(array_keys(config('entretien.types.CORRECTIF', [])))],
            'description'  => ['required', 'string'],
            'kilometrage'  => ['nullable', 'integer', 'min:0'],
        ]);

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
        $this->verifierEnAttente($declaration);
        $user = AuthUser::fromRequest($request);

        DB::transaction(function () use ($declaration, $user) {
            $entretien = Entretien::create([
                'vehicule_id'    => $declaration->vehicule_id,
                'type'           => TypeEntretien::CORRECTIF,
                'categorie'      => $declaration->categorie,
                'description'    => $declaration->description,
                'date_entretien' => now()->toDateString(),
                'kilometrage'    => $declaration->kilometrage ?? 0,
                'statut'         => StatutEntretien::Planifie,
                'created_by'     => (string) $user->id,
            ]);

            EntretienHistorique::create([
                'entretien_id'   => $entretien->id,
                'ancien_statut'  => null,
                'nouveau_statut' => StatutEntretien::Planifie->value,
                'action_par'     => (string) $user->id,
            ]);

            $declaration->update([
                'statut'       => DeclarationPanne::TRAITEE,
                'entretien_id' => $entretien->id,
                'traite_par'   => (string) $user->id,
                'traite_at'    => now(),
            ]);
        });

        return response()->json(['data' => DeclarationPanneResource::make($declaration->fresh())->resolve()]);
    }

    public function rejeter(Request $request, DeclarationPanne $declaration): JsonResponse
    {
        $this->verifierEnAttente($declaration);
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:500']]);
        $user = AuthUser::fromRequest($request);

        $declaration->update([
            'statut'      => DeclarationPanne::REJETEE,
            'traite_par'  => (string) $user->id,
            'traite_at'   => now(),
            'motif_rejet' => $data['motif'] ?? null,
        ]);

        return response()->json(['data' => DeclarationPanneResource::make($declaration->fresh())->resolve()]);
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