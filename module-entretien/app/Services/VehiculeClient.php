<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class VehiculeClient
{
    /** Statuts qui interdisent de creer un entretien. */
    private const INACTIFS = ['HORS_SERVICE', 'ARCHIVE'];

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('entretien.vehicule_service.timeout', 3))->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim(config('vehicule.url'), '/') . $path;
    }

    /** Infos du vehicule (statut, kilometrage...). Leve BusinessException si introuvable ou injoignable. */
    public function find(int $id): array
    {
        try {
            $response = $this->http()->get($this->url("/api/vehicules/{$id}"));
        } catch (Throwable) {
            throw new BusinessException('Service Vehicule injoignable.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        if ($response->status() === 404) {
            throw new BusinessException("Vehicule #{$id} introuvable.", 404, 'VEHICULE_INTROUVABLE');
        }

        if (! $response->successful()) {
            throw new BusinessException('Service Vehicule indisponible.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        return $response->json();
    }

    /** Refuse un vehicule inexistant, archive ou hors service. Retourne ses infos sinon. */
    public function verifierUtilisable(int $id): array
    {
        $v = $this->find($id);

        $statut = strtoupper((string) ($v['statut'] ?? ''));
        if (in_array($statut, self::INACTIFS, true) || ($v['archive'] ?? false) === true) {
            throw new BusinessException("Vehicule #{$id} archive ou hors service.", 422, 'VEHICULE_INACTIF');
        }

        return $v;
    }

    public function changerStatut(int $id, string $statut): void
    {
        $this->patch("/api/vehicules/{$id}/statut", ['statut' => $statut]);
    }

    public function changerKilometrage(int $id, int $kilometrage): void
    {
        $this->patch("/api/vehicules/{$id}/kilometrage", ['kilometrage' => $kilometrage]);
    }

    private function patch(string $path, array $data): void
    {
        try {
            $response = $this->http()->patch($this->url($path), $data);
        } catch (Throwable) {
            throw new BusinessException('Service Vehicule injoignable.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        if (! $response->successful()) {
            throw new BusinessException('Mise a jour du vehicule refusee.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }
    }

    /** Ancienne methode (utilisee par le controller actuel, remplacee a l'etape suivante). */
    public function existe(int $id): bool
    {
        if (! config('vehicule.check')) {
            return true;
        }

        try {
            $this->find($id);
            return true;
        } catch (BusinessException $e) {
            if ($e->status === 404) {
                return false;
            }
            throw $e;
        }
    }
}
