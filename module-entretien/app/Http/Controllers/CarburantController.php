<?php

namespace App\Http\Controllers;

use App\Services\CarburantClient;
use Illuminate\Http\JsonResponse;

class CarburantController
{
    /** Statistiques carburant d'un vehicule, relayees depuis le service Carburant. */
    public function stats(int $vehicule, CarburantClient $carburant): JsonResponse
    {
        return response()->json(['data' => $carburant->statsVehicule($vehicule)]);
    }
}
