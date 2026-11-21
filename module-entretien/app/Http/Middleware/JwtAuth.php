<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Support\Jwt;
use Closure;
use Illuminate\Http\Request;

class JwtAuth
{
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('auth_jwt.secret');
        if (strlen($secret) < 16 || $secret === 'change-me-en-production') {
            throw new BusinessException('JWT_SECRET non configure.', 500, 'CONFIG_MANQUANTE');
        }

        $token = $request->bearerToken();
        if (! $token) {
            throw new BusinessException('Authentification requise.', 401, 'NON_AUTHENTIFIE');
        }

        $payload = Jwt::decode($token, $secret);
        $request->attributes->set('jwt', $payload);

        return $next($request);
    }
}