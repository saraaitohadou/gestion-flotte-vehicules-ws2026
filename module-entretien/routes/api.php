<?php

use App\Http\Controllers\AlerteController;
use App\Http\Controllers\EntretienController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'entretien']));

Route::get('/entretiens', [EntretienController::class, 'index']);
Route::post('/entretiens', [EntretienController::class, 'store']);
Route::get('/entretiens/{entretien}', [EntretienController::class, 'show']);
Route::put('/entretiens/{entretien}', [EntretienController::class, 'update']);
Route::delete('/entretiens/{entretien}', [EntretienController::class, 'destroy']);
Route::patch('/entretiens/{entretien}/demarrer', [EntretienController::class, 'demarrer']);
Route::patch('/entretiens/{entretien}/terminer', [EntretienController::class, 'terminer']);
Route::patch('/entretiens/{entretien}/annuler', [EntretienController::class, 'annuler']);
Route::get('/vehicules/{vehiculeId}/entretiens', [EntretienController::class, 'historique']);
Route::get('/alertes', [AlerteController::class, 'index']);