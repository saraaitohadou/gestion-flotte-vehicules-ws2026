<?php
namespace App\Auth;

use App\Exceptions\ServiceException;

/**
 * Vérifie un JWT HS256 (même format que le service Chauffeur : sub / role / exp).
 * Accepte aussi une clé de service (en-tête X-API-Key) pour les appels
 * entre services (Entretien, Carburant, Chauffeur).
 */
class JwtAuth
{
    /** Rôles autorisés à utiliser ce service (ajoute 'GESTIONNAIRE' si besoin). */
    private const ROLES = ['ADMINISTRATEUR', 'ADMIN'];

    /** À appeler avant le routage. Lève une ServiceException 401/403 si refus. */
    public static function exiger(): array
    {
        // 1) Appel de service à service avec la clé partagée
        $cle = getenv('SERVICE_API_KEY') ?: '';
        $envoyee = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($cle !== '' && $envoyee !== '' && hash_equals($cle, $envoyee)) {
            return ['sub' => 'service', 'role' => 'SERVICE'];
        }

        // 2) Token Bearer
        $entete = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');

        if (!preg_match('/^Bearer\s+(.+)$/i', $entete, $m)) {
            throw new ServiceException('Authentification requise : token Bearer manquant', 401);
        }

        $claims = self::decoder(trim($m[1]));

        if (!in_array($claims['role'] ?? '', self::ROLES, true)) {
            throw new ServiceException("Ce compte n'a pas le droit d'accéder à cette ressource", 403);
        }
        return $claims;
    }

    private static function decoder(string $token): array
    {
        $parties = explode('.', $token);
        if (count($parties) !== 3) {
            throw new ServiceException('Token invalide', 401);
        }
        [$head, $body, $sig] = $parties;

        $entete = json_decode(self::b64d($head), true);
        if (!is_array($entete) || ($entete['alg'] ?? '') !== 'HS256') {
            throw new ServiceException('Token invalide', 401);
        }

        $attendue = self::b64e(hash_hmac('sha256', "$head.$body", self::secret(), true));
        if (!hash_equals($attendue, $sig)) {
            throw new ServiceException('Token invalide', 401);
        }

        $payload = json_decode(self::b64d($body), true);
        if (!is_array($payload)) {
            throw new ServiceException('Token invalide', 401);
        }
        if (($payload['exp'] ?? 0) < time()) {
            throw new ServiceException('Session expirée, reconnectez-vous', 401);
        }
        return $payload;
    }

    private static function secret(): string
    {
        $secret = getenv('JWT_SECRET') ?: '';
        if (strlen($secret) < 16) {
            throw new ServiceException('JWT_SECRET non configuré (16 caractères minimum)', 500);
        }
        return $secret;
    }

    private static function b64e(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64d(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}