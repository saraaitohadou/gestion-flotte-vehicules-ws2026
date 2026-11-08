<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Support\AuthUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    /** Usage : ->middleware('role:GESTIONNAIRE') (l'administrateur passe toujours). */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = AuthUser::fromRequest($request);

        if (!$user->estAdmin() && !in_array($user->role, $roles, true)) {
            throw new BusinessException(
                "Accès refusé : le rôle {$user->role} n'a pas le droit d'effectuer cette action.",
                403,
                'ACCES_REFUSE',
            );
        }

        return $next($request);
    }
}
