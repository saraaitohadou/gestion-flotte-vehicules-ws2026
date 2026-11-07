<?php

namespace App\Support;

use InvalidArgumentException;

/** JWT HS256 minimaliste (aucune dépendance externe). */
class JwtService
{
    public function issue(array $claims, int $ttlSeconds = 3600): string
    {
        $now     = time();
        $payload = array_merge(['iat' => $now, 'exp' => $now + $ttlSeconds], $claims);
        $head    = $this->b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body    = $this->b64(json_encode($payload));
        $sig     = $this->b64(hash_hmac('sha256', "$head.$body", $this->secret(), true));

        return "$head.$body.$sig";
    }

    /** @throws InvalidArgumentException */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Format de token invalide.');
        }
        [$head, $body, $sig] = $parts;

        $header = json_decode($this->unb64($head), true);
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidArgumentException('Algorithme non supporté.');
        }

        $attendue = $this->b64(hash_hmac('sha256', "$head.$body", $this->secret(), true));
        if (!hash_equals($attendue, $sig)) {
            throw new InvalidArgumentException('Signature invalide.');
        }

        $payload = json_decode($this->unb64($body), true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Payload invalide.');
        }
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new InvalidArgumentException('Token expiré.');
        }

        return $payload;
    }

    private function secret(): string
    {
        $secret = (string) config('entretien.jwt.secret');
        if (strlen($secret) < 16) {
            throw new InvalidArgumentException('JWT_SECRET non configuré.');
        }
        return $secret;
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
