<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class VehiculeClient
{
    /** Statuts interdits pour une déclaration de panne (EN_MISSION toléré : une panne est urgente). */
    private const INACTIFS = ['HORS_SERVICE', 'ARCHIVE'];

    /** Statuts interdits pour créer un entretien : EN_MISSION inclus (voir règle §4.2). */
    public const STATUTS_INTERDITS_ENTRETIEN = ['HORS_SERVICE', 'ARCHIVE', 'EN_MISSION'];

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('vehicule.timeout', 3))
            ->acceptJson()
            ->withHeaders(['X-API-Key' => (string) config('vehicule.api_key', '')]);
    }

    private function url(string $path): string
    {
        $prefix = trim((string) config('vehicule.prefix', '/api'), '/');
        return rtrim(config('vehicule.url'), '/') . ($prefix !== '' ? '/' . $prefix : '') . $path;
    }

    /** Infos du vehicule (statut, kilometrage...). Leve BusinessException si introuvable ou injoignable. */
    public function find(int $id): array
    {
        try {
            $response = $this->http()->get($this->url("/vehicules/{$id}"));
        } catch (Throwable) {
            throw new BusinessException('Service Vehicule injoignable.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        if ($response->status() === 404) {
            throw new BusinessException("Vehicule #{$id} introuvable.", 404, 'VEHICULE_INTROUVABLE');
        }

        if (! $response->successful()) {
            throw new BusinessException('Service Vehicule indisponible.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        // Un corps vide (mock, service minimal) doit rester un tableau exploitable.
        return $response->json() ?? [];
    }

    /** Liste des vehicules (pour le formulaire d'entretien). */
    public function lister(): array
    {
        try {
            $response = $this->http()->get($this->url('/vehicules'));
        } catch (Throwable) {
            throw new BusinessException('Service Vehicule injoignable.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        if (! $response->successful()) {
            throw new BusinessException('Service Vehicule indisponible.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        $json = $response->json();
        return array_values($json['data'] ?? $json ?? []);
    }

    /**
     * Refuse un vehicule inexistant, archive ou hors service.
     *
     * @param array|null $statutsInterdits liste optionnelle (utilisée par les entretiens
     *                                     pour interdire également EN_MISSION)
     */
    public function verifierUtilisable(int $id, ?array $statutsInterdits = null): array
    {
        $v = $this->find($id);

        $statuts = array_map('strtoupper', $statutsInterdits ?? self::INACTIFS);
        $statut  = strtoupper((string) ($v['statut'] ?? ''));

        if (in_array('EN_MISSION', $statuts, true) && $statut === 'EN_MISSION') {
            throw new BusinessException(
                "Vehicule #{$id} en mission : aucun entretien ne peut etre cree.",
                422,
                'VEHICULE_EN_MISSION'
            );
        }

        if (in_array($statut, $statuts, true) || ($v['archive'] ?? false) === true) {
            throw new BusinessException("Vehicule #{$id} archive ou hors service.", 422, 'VEHICULE_INACTIF');
        }

        return $v;
    }

    public function changerStatut(int $id, string $statut, ?string $idempotencyKey = null): void
    {
        $this->patch("/vehicules/{$id}/statut", ['statut' => $statut], $idempotencyKey);
    }

    public function changerKilometrage(int $id, int $kilometrage, ?string $idempotencyKey = null): void
    {
        $this->patch("/vehicules/{$id}/kilometrage", ['kilometrage' => $kilometrage], $idempotencyKey);
    }

    private function patch(string $path, array $data, ?string $idempotencyKey = null): void
    {
        try {
            $requete = $this->http();
            if ($idempotencyKey !== null) {
                // Permet au service Véhicule d'ignorer un PATCH rejoué après une réponse perdue.
                $requete->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }
            $response = $requete->patch($this->url($path), $data);
        } catch (Throwable) {
            throw new BusinessException('Service Vehicule injoignable.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }

        if (! $response->successful()) {
            throw $response->clientError()
                ? new BusinessException('Mise a jour du vehicule refusee.', 409, 'VEHICULE_MISE_A_JOUR_REFUSEE')
                : new BusinessException('Service Vehicule indisponible.', 503, 'VEHICULE_SERVICE_INDISPONIBLE');
        }
    }
}
