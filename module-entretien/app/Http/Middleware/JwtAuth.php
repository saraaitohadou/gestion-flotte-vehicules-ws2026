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
        $token = $request->bearerToken();
        if (! $token) {
            throw new BusinessException('Authentification requise.', 401, 'NON_AUTHENTIFIE');
        }

        $payload = Jwt::decode($token, (string) config('auth_jwt.secret'));
        $request->attributes->set('jwt', $payload);

        return $next($request);
    }
}