<?php

namespace Tests;

use App\Support\Jwt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Mettre a true dans un test pour envoyer la requete SANS token (tests 401). */
    protected bool $sansToken = false;

    protected function tokenHeaders(string $role = 'ADMINISTRATEUR', ?int $vehicule = null, int $ttl = 3600): array
    {
        $claims = ['sub' => "test-{$role}", 'role' => $role, 'exp' => time() + $ttl];
        if ($vehicule) {
            $claims['vehicule_id'] = $vehicule;
        }

        return ['Authorization' => 'Bearer ' . Jwt::encode($claims, (string) config('auth_jwt.secret'))];
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (! $this->sansToken && ! isset($server['HTTP_AUTHORIZATION'])) {
            $server['HTTP_AUTHORIZATION'] = $this->tokenHeaders()['Authorization'];
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}