<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\DeclarationPanne;
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

        return response()->json(['data' => $query->get()]);
    }

    public function show(DeclarationPanne $declaration): JsonResponse
    {
        return response()->json(['data' => $declaration]);
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

        return response()->json(['data' => $declaration], 201);
    }
}