<?php

namespace App\Support;

use App\Exceptions\BusinessException;

final class Jwt
{
    public static function decode(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw self::invalide();
        }

        [$h, $p, $s] = $parts;
        $header  = json_decode(self::b64d($h), true);
        $payload = json_decode(self::b64d($p), true);

        if (! is_array($header) || ! is_array($payload) || ($header['alg'] ?? null) !== 'HS256') {
            throw self::invalide();
        }

        $attendu = self::b64e(hash_hmac('sha256', "$h.$p", $secret, true));
        if (! hash_equals($attendu, $s)) {
            throw self::invalide();
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            throw new BusinessException('Token expire.', 401, 'TOKEN_EXPIRE');
        }

        return $payload;
    }

    public static function encode(array $payload, string $secret): string
    {
        $h = self::b64e(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64e(json_encode($payload));
        $s = self::b64e(hash_hmac('sha256', "$h.$p", $secret, true));

        return "$h.$p.$s";
    }

    private static function invalide(): BusinessException
    {
        return new BusinessException('Token invalide.', 401, 'TOKEN_INVALIDE');
    }

    private static function b64e(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    private static function b64d(string $d): string
    {
        return (string) base64_decode(strtr($d, '-_', '+/'));
    }
}