<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class CarburantClient
{
    private function http(): PendingRequest
    {
        return Http::timeout((int) config('carburant.timeout', 3))
            ->acceptJson()
            ->withHeaders(['X-API-Key' => (string) config('carburant.api_key', '')]);
    }

    private function url(string $path): string
    {
        $prefix = trim((string) config('carburant.prefix', ''), '/');
        return rtrim(config('carburant.url'), '/') . ($prefix !== '' ? '/' . $prefix : '') . $path;
    }

    /** Statistiques carburant d'un vehicule : appel REST en lecture seule. */
    public function statsVehicule(int $vehiculeId): array
    {
        try {
            $response = $this->http()->get($this->url("/carburants/stats/vehicules/{$vehiculeId}"));
        } catch (Throwable) {
            throw new BusinessException('Service Carburant injoignable.', 503, 'CARBURANT_SERVICE_INDISPONIBLE');
        }

        if ($response->status() === 404) {
            throw new BusinessException("Vehicule #{$vehiculeId} introuvable cote Carburant.", 404, 'VEHICULE_INTROUVABLE');
        }

        if (! $response->successful()) {
            throw new BusinessException('Service Carburant indisponible.', 503, 'CARBURANT_SERVICE_INDISPONIBLE');
        }

        return $response->json() ?? [];
    }
}
