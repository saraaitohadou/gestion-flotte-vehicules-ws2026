<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Test du proxy GET /api/vehicules/{id} consomme par le frontal Entretien. */
class VehiculeDetailProxyTest extends TestCase
{
    public function test_le_proxy_renvoie_le_statut_reel_du_vehicule(): void
    {
        Http::fake([
            '*/api/vehicules/2' => Http::response([
                'id' => 2, 'immatriculation' => '67890-B-1',
                'statut' => 'EN_MAINTENANCE', 'kilometrage' => 62500,
            ]),
        ]);

        $this->getJson('/api/vehicules/2', $this->tokenHeaders())
            ->assertOk()
            ->assertJsonPath('data.statut', 'EN_MAINTENANCE')
            ->assertJsonPath('data.kilometrage', 62500);

        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/api/vehicules/2'));
    }

    public function test_vehicule_introuvable_renvoie_404(): void
    {
        Http::fake(['*/api/vehicules/999' => Http::response([], 404)]);

        $this->getJson('/api/vehicules/999', $this->tokenHeaders())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICULE_INTROUVABLE');
    }

    public function test_service_indisponible_renvoie_503_sans_faux_succes(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->getJson('/api/vehicules/2', $this->tokenHeaders())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'VEHICULE_SERVICE_INDISPONIBLE');
    }

    public function test_chauffeur_ne_peut_pas_lire_le_detail_vehicule(): void
    {
        Http::fake();

        $this->getJson('/api/vehicules/2', $this->tokenHeaders('CHAUFFEUR', 2))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCES_INTERDIT');

        Http::assertNothingSent();
    }
}
