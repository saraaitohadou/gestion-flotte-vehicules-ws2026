<?php

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Support\AuthUser;
use Closure;
use Illuminate\Http\Request;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = AuthUser::fromRequest($request);
        $autorises = array_map([AuthUser::class, 'normaliserRole'], $roles);

        if (! in_array($user->role, $autorises, true)) {
            throw new BusinessException('Acces refuse pour ce role.', 403, 'ACCES_INTERDIT');
        }

        return $next($request);
    }
}