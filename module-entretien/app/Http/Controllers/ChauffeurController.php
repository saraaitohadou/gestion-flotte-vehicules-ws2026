<?php

namespace App\Http\Controllers;

use App\Services\ChauffeurClient;
use Illuminate\Http\JsonResponse;

class ChauffeurController
{
    public function index(ChauffeurClient $chauffeurs): JsonResponse
    {
        return response()->json(['data' => $chauffeurs->lister()]);
    }
}
