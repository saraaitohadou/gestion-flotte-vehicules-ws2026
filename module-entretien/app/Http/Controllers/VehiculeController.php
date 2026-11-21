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
}