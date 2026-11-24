<?php

use App\Http\Controllers\CarburantController;
use App\Http\Controllers\ChauffeurController;
use App\Http\Controllers\DeclarationPanneController;
use App\Http\Controllers\EntretienController;
use App\Http\Controllers\GestionnaireController;
use App\Http\Controllers\VehiculeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'entretien']));

Route::post('/gestionnaires/login', [GestionnaireController::class, 'login']);

Route::middleware('jwt')->group(function () {

    // Lecture des entretiens : tous les roles (le chauffeur est limite a son vehicule par le controller)
    Route::get('/entretiens', [EntretienController::class, 'index']);
    Route::get('/entretiens/{entretien}', [EntretienController::class, 'show']);
    Route::get('/vehicules/{vehiculeId}/entretiens', [EntretienController::class, 'historique']);

    // Declarations : le chauffeur ne voit que les siennes, l'administrateur toutes.
    // Le gestionnaire n'a aucun acces a cette liste ni a son traitement.
    Route::middleware('role:CHAUFFEUR,ADMINISTRATEUR')->group(function () {
        Route::get('/declarations-panne', [DeclarationPanneController::class, 'index']);
        Route::get('/declarations-panne/{declaration}', [DeclarationPanneController::class, 'show']);
        Route::post('/declarations-panne', [DeclarationPanneController::class, 'store']);
    });

    // Gestion courante : ADMIN + GESTIONNAIRE
    Route::middleware('role:ADMINISTRATEUR,GESTIONNAIRE')->group(function () {
        Route::get('/vehicules', [VehiculeController::class, 'index']);
        // Etat authoritative du vehicule : permet a l'UI de verifier DEMARRER / TERMINER.
        Route::get('/vehicules/{vehicule}', [VehiculeController::class, 'show']);
        Route::get('/vehicules/{vehicule}/carburant', [CarburantController::class, 'stats']);
        Route::get('/chauffeurs', [ChauffeurController::class, 'index']);
        Route::put('/entretiens/{entretien}', [EntretienController::class, 'update']);
        Route::patch('/entretiens/{entretien}/annuler', [EntretienController::class, 'annuler']);
    });

    // DEMARRER / TERMINER : GESTIONNAIRE uniquement (retire a l'administrateur).
    Route::middleware('role:GESTIONNAIRE')->group(function () {
        Route::patch('/entretiens/{entretien}/demarrer', [EntretienController::class, 'demarrer']);
        Route::patch('/entretiens/{entretien}/terminer', [EntretienController::class, 'terminer']);
    });

    // Traitement des declarations : ADMINISTRATEUR uniquement.
    Route::middleware('role:ADMINISTRATEUR')->group(function () {
        Route::post('/entretiens', [EntretienController::class, 'store']);
        Route::patch('/declarations-panne/{declaration}/prendre-en-charge', [DeclarationPanneController::class, 'prendreEnCharge']);
        Route::patch('/declarations-panne/{declaration}/rejeter', [DeclarationPanneController::class, 'rejeter']);
        Route::delete('/entretiens/{entretien}', [EntretienController::class, 'destroy']);

        Route::prefix('gestionnaires')->group(function () {
            Route::get('/', [GestionnaireController::class, 'index']);
            Route::post('/', [GestionnaireController::class, 'store']);
            Route::patch('/{id}/actif', [GestionnaireController::class, 'basculer']);
            Route::patch('/{id}/mot-de-passe', [GestionnaireController::class, 'motDePasse']);
        });
    });
});
