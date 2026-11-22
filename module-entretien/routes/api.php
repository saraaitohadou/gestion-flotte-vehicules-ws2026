<?php

use App\Http\Controllers\AlerteController;
use App\Http\Controllers\ChauffeurController;
use App\Http\Controllers\DeclarationPanneController;
use App\Http\Controllers\EntretienController;
use App\Http\Controllers\GestionnaireController;
use App\Http\Controllers\VehiculeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'entretien']));

Route::post('/gestionnaires/login', [GestionnaireController::class, 'login']);

Route::middleware('jwt')->group(function () {

    // Lecture : tous les roles (le chauffeur est limite a son vehicule par le controller)
    Route::get('/entretiens', [EntretienController::class, 'index']);
    Route::get('/entretiens/{entretien}', [EntretienController::class, 'show']);
    Route::get('/vehicules/{vehiculeId}/entretiens', [EntretienController::class, 'historique']);
    Route::get('/declarations-panne', [DeclarationPanneController::class, 'index']);
    Route::get('/declarations-panne/{declaration}', [DeclarationPanneController::class, 'show']);

    // Gestion des entretiens : ADMIN + GESTIONNAIRE
    Route::middleware('role:ADMINISTRATEUR,GESTIONNAIRE')->group(function () {
        Route::get('/alertes', [AlerteController::class, 'index']);
        Route::get('/vehicules', [VehiculeController::class, 'index']);
        Route::get('/chauffeurs', [ChauffeurController::class, 'index']);
        Route::post('/entretiens', [EntretienController::class, 'store']);
        Route::put('/entretiens/{entretien}', [EntretienController::class, 'update']);
        Route::patch('/entretiens/{entretien}/demarrer', [EntretienController::class, 'demarrer']);
        Route::patch('/entretiens/{entretien}/terminer', [EntretienController::class, 'terminer']);
        Route::patch('/entretiens/{entretien}/annuler', [EntretienController::class, 'annuler']);
        Route::patch('/declarations-panne/{declaration}/prendre-en-charge', [DeclarationPanneController::class, 'prendreEnCharge']);
        Route::patch('/declarations-panne/{declaration}/rejeter', [DeclarationPanneController::class, 'rejeter']);
    });

    // Archivage : ADMIN seulement
    Route::delete('/entretiens/{entretien}', [EntretienController::class, 'destroy'])
        ->middleware('role:ADMINISTRATEUR');

    // Declaration de panne : CHAUFFEUR (+ ADMIN)
    Route::post('/declarations-panne', [DeclarationPanneController::class, 'store'])
        ->middleware('role:CHAUFFEUR,ADMINISTRATEUR');

    // Comptes gestionnaires : ADMIN seulement
    Route::middleware('role:ADMINISTRATEUR')->prefix('gestionnaires')->group(function () {
        Route::get('/', [GestionnaireController::class, 'index']);
        Route::post('/', [GestionnaireController::class, 'store']);
        Route::patch('/{id}/actif', [GestionnaireController::class, 'basculer']);
        Route::patch('/{id}/mot-de-passe', [GestionnaireController::class, 'motDePasse']);
    });
});
