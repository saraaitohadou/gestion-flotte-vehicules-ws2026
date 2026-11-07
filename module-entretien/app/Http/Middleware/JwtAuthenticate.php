<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Support\AuthUser;
use App\Support\JwtService;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    public function __construct(private JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            throw new BusinessException('Authentification requise : token Bearer manquant.', 401, 'NON_AUTHENTIFIE');
        }

        try {
            $claims = $this->jwt->decode($token);
        } catch (InvalidArgumentException $e) {
            throw new BusinessException('Token invalide ou expiré.', 401, 'TOKEN_INVALIDE');
        }

        $role = AuthUser::normaliserRole($claims['role'] ?? null);
        if (!in_array($role, [AuthUser::ADMIN, AuthUser::GESTIONNAIRE, AuthUser::CHAUFFEUR], true)) {
            throw new BusinessException('Rôle inconnu dans le token.', 403, 'ROLE_INCONNU');
        }

        $request->attributes->set('auth_user', new AuthUser(
            isset($claims['sub']) ? (string) $claims['sub'] : null,
            $role,
            isset($claims['vehicule_id']) ? (int) $claims['vehicule_id'] : null,
        ));

        return $next($request);
    }
}
