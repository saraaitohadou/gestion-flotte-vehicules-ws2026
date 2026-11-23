<?php

namespace App\Support;

use App\Exceptions\BusinessException;
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
        /** Identifiant du chauffeur, issu du JWT (jamais du corps de requête). */
        public readonly ?int $chauffeurId = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $jwt = $request->attributes->get('jwt');

        // Fail-closed : l'absence de JWT ne doit JAMAIS déduire un rôle privilégié.
        if (! is_array($jwt)) {
            throw new BusinessException('Authentification requise.', 401, 'NON_AUTHENTIFIE');
        }

        return new self(
            isset($jwt['sub']) ? (string) $jwt['sub'] : null,
            self::normaliserRole($jwt['role'] ?? null),
            isset($jwt['vehicule_id']) && $jwt['vehicule_id'] !== ''
                ? (int) $jwt['vehicule_id']
                : null,
            self::extraireChauffeurId($jwt),
        );
    }

    /** Le service Chauffeur émet "id_chauffeur" et sub = id_chauffeur. */
    private static function extraireChauffeurId(array $jwt): ?int
    {
        if (isset($jwt['id_chauffeur']) && $jwt['id_chauffeur'] !== '') {
            return (int) $jwt['id_chauffeur'];
        }

        $sub = $jwt['sub'] ?? null;
        if (self::normaliserRole($jwt['role'] ?? null) === self::CHAUFFEUR
            && $sub !== null
            && ctype_digit((string) $sub)) {
            return (int) $sub;
        }

        return null;
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

    public function estGestionnaire(): bool
    {
        return $this->role === self::GESTIONNAIRE;
    }

    public function estChauffeur(): bool
    {
        return $this->role === self::CHAUFFEUR;
    }
}
