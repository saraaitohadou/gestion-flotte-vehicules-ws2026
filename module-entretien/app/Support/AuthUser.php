<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AuthUser
{
    public const ADMIN        = 'ADMINISTRATEUR';
    public const GESTIONNAIRE = 'GESTIONNAIRE';
    public const CHAUFFEUR    = 'CHAUFFEUR';

    public function __construct(
        public readonly ?string $id,
        public readonly string $role,
        public readonly ?int $vehiculeId = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return $request->attributes->get('auth_user');
    }

    public static function normaliserRole(?string $role): string
    {
        $role = strtoupper(trim((string) $role));
        return match ($role) {
            'ADMIN', 'ADMINISTRATEUR' => self::ADMIN,
            default                   => $role,
        };
    }

    public function estAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function estChauffeur(): bool
    {
        return $this->role === self::CHAUFFEUR;
    }
}
