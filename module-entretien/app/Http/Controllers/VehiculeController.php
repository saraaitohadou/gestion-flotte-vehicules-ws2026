<?php

namespace App\Http\Controllers;

use App\Services\VehiculeClient;
use Illuminate\Http\JsonResponse;

class VehiculeController
{
    public function index(VehiculeClient $vehicules): JsonResponse
    {
        return response()->json(['data' => $vehicules->lister()]);
    }

    /**
     * Etat reel du vehicule (statut + kilometrage) relays par le service Vehicule.
     * Le frontal verifie ainsi apres Demarrer/Terminer que la mutation a bien ete
     * appliquee, sans parler directement au service Vehicule.
     */
    public function show(int $vehicule, VehiculeClient $vehicules): JsonResponse
    {
        return response()->json(['data' => $vehicules->find($vehicule)]);
    }
}
