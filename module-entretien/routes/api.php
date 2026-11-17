<?php

use App\Http\Controllers\AlerteController;
use App\Http\Controllers\EntretienController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'entretien']));

Route::middleware('jwt')->group(function () {
    // Lecture : tous les roles (le chauffeur est limite a son vehicule dans le controller)
    Route::get('/entretiens', [EntretienController::class, 'index']);
    Route::get('/entretiens/{entretien}', [EntretienController::class, 'show']);
    Route::get('/vehicules/{vehiculeId}/entretiens', [EntretienController::class, 'historique']);
    Route::get('/alertes', [AlerteController::class, 'index']);

    // Ecriture : GESTIONNAIRE (l'administrateur passe toujours)
    Route::middleware('role:GESTIONNAIRE')->group(function () {
        Route::post('/entretiens', [EntretienController::class, 'store']);
        Route::put('/entretiens/{entretien}', [EntretienController::class, 'update']);
        Route::patch('/entretiens/{entretien}/demarrer', [EntretienController::class, 'demarrer']);
        Route::patch('/entretiens/{entretien}/terminer', [EntretienController::class, 'terminer']);
        Route::patch('/entretiens/{entretien}/annuler', [EntretienController::class, 'annuler']);
    });

    // Archivage : ADMINISTRATEUR uniquement
    Route::delete('/entretiens/{entretien}', [EntretienController::class, 'destroy'])
        ->middleware('role:ADMINISTRATEUR');
});
