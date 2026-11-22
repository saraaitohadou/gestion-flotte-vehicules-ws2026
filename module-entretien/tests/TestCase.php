<?php

namespace Tests;

use App\Support\Jwt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Fabrique un en-tête Bearer pour les tests qui ont besoin d'un JWT.
     * Les tests doivent le passer EXPLICITEMENT à chaque requête : aucun token
     * administrateur n'est plus injecté automatiquement (plus de fail-open).
     */
    protected function tokenHeaders(string $role = 'ADMINISTRATEUR', ?int $vehicule = null, int $ttl = 3600): array
    {
        $claims = ['sub' => "test-{$role}", 'role' => $role, 'exp' => time() + $ttl];
        if ($vehicule) {
            $claims['vehicule_id'] = $vehicule;
        }

        return ['Authorization' => 'Bearer ' . Jwt::encode($claims, (string) config('auth_jwt.secret'))];
    }
}
