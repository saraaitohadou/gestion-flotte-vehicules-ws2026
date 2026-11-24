<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Models\Gestionnaire;
use App\Support\AuthUser;
use App\Support\Jwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class GestionnaireController
{
    private const DUREE = 12 * 3600;

    /** Public : POST /api/gestionnaires/login */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:60',
            'password' => 'required|string|max:200',
        ]);

        $cle = 'gest-login:' . strtolower($data['username']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($cle, 5)) {
            throw new BusinessException('Trop de tentatives. Reessayez dans quelques minutes.', 429, 'TROP_DE_TENTATIVES');
        }

        $g = Gestionnaire::where('username', $data['username'])->first();
        if (! $g || ! Hash::check($data['password'], $g->password_hash)) {
            RateLimiter::hit($cle, 300);
            throw new BusinessException('Identifiant ou mot de passe incorrect.', 401, 'IDENTIFIANTS_INVALIDES');
        }
        if (! $g->actif) {
            throw new BusinessException('Ce compte est desactive.', 403, 'COMPTE_DESACTIVE');
        }

        RateLimiter::clear($cle);

        $token = Jwt::encode([
            'sub'  => (string) $g->id,
            'name' => $g->username,
            'role' => AuthUser::GESTIONNAIRE,
            'exp'  => time() + self::DUREE,
        ], (string) config('auth_jwt.secret'));

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => self::DUREE,
        ]);
    }

    /** Admin : GET /api/gestionnaires */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Gestionnaire::orderBy('username')->get()]);
    }

    /** Admin : POST /api/gestionnaires */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|min:3|max:60|unique:gestionnaires,username',
            'password' => 'required|string|min:8|max:200',
        ]);

        $admin = AuthUser::fromRequest($request);
        $g = Gestionnaire::create([
            'username'      => $data['username'],
            'password_hash' => Hash::make($data['password']),
            'actif'         => true,
            'cree_par'      => $admin->id,
        ]);

        return response()->json(['data' => $g], 201);
    }

    /** Admin : PATCH /api/gestionnaires/{id}/actif  { "actif": true|false } */
    public function basculer(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['actif' => 'required|boolean']);
        $g = Gestionnaire::find($id) ?? throw new BusinessException('Gestionnaire introuvable.', 404, 'GESTIONNAIRE_INTROUVABLE');
        $g->update(['actif' => $data['actif']]);

        return response()->json(['data' => $g]);
    }

    /** Admin : PATCH /api/gestionnaires/{id}/mot-de-passe  { "password": "..." } */
    public function motDePasse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['password' => 'required|string|min:8|max:200']);
        $g = Gestionnaire::find($id) ?? throw new BusinessException('Gestionnaire introuvable.', 404, 'GESTIONNAIRE_INTROUVABLE');
        $g->update(['password_hash' => Hash::make($data['password'])]);

        return response()->json(['data' => $g]);
    }
}