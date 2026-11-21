<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class ChauffeurClient
{
    private function http(): PendingRequest
    {
        return Http::timeout((int) config('chauffeur.timeout', 3))->acceptJson();
    }

    private function url(string $path): string
    {
        $prefix = trim((string) config('chauffeur.prefix', '/api'), '/');
        return rtrim(config('chauffeur.url'), '/') . ($prefix !== '' ? '/' . $prefix : '') . $path;
    }

    /** Eligibilite du chauffeur (statut, permis_valide, selectionnable, motifs). */
    public function eligibilite(int $id): array
    {
        try {
            $response = $this->http()->get($this->url("/chauffeurs/{$id}/eligibilite"));
        } catch (Throwable) {
            throw new BusinessException('Service Chauffeur injoignable.', 503, 'CHAUFFEUR_SERVICE_INDISPONIBLE');
        }

        if ($response->status() === 404) {
            throw new BusinessException("Chauffeur #{$id} introuvable.", 404, 'CHAUFFEUR_INTROUVABLE');
        }

        if (! $response->successful()) {
            throw new BusinessException('Service Chauffeur indisponible.', 503, 'CHAUFFEUR_SERVICE_INDISPONIBLE');
        }

        return $response->json();
    }

    /** Refuse un chauffeur INACTIF / EN_CONGE (non selectionnable). EN_MISSION est accepte. */
    public function verifierSelectionnable(int $id): array
    {
        $c = $this->eligibilite($id);

        if (! ($c['selectionnable'] ?? false)) {
            $motifs = implode(', ', $c['motifs'] ?? []);
            throw new BusinessException(
                "Chauffeur #{$id} non selectionnable ({$motifs}).",
                422,
                'CHAUFFEUR_NON_SELECTIONNABLE'
            );
        }

        return $c;
    }
}