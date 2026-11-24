<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Test du proxy GET /api/vehicules/{id}/carburant consomme par le frontal Entretien. */
class CarburantStatsProxyTest extends TestCase
{
    public function test_le_proxy_renvoie_les_stats_carburant(): void
    {
        Http::fake([
            '*/carburants/stats/vehicules/1' => Http::response([
                'vehicule_id'            => 1,
                'nombre_pleins'          => 3,
                'total_litres'           => 120.5,
                'cout_total'             => 1800.0,
                'distance_km'            => 1500,
                'consommation_l_100km'   => 7.5,
                'cout_au_km'             => 1.2,
            ]),
        ]);

        $this->getJson('/api/vehicules/1/carburant', $this->tokenHeaders())
            ->assertOk()
            ->assertJsonPath('data.nombre_pleins', 3)
            ->assertJsonPath('data.consommation_l_100km', 7.5);

        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && str_ends_with($r->url(), '/carburants/stats/vehicules/1')
            && $r->hasHeader('X-API-Key'));
    }

    public function test_service_indisponible_renvoie_503_sans_faux_succes(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->getJson('/api/vehicules/1/carburant', $this->tokenHeaders())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'CARBURANT_SERVICE_INDISPONIBLE');
    }

    public function test_chauffeur_ne_peut_pas_consulter_les_stats(): void
    {
        Http::fake();

        $this->getJson('/api/vehicules/1/carburant', $this->tokenHeaders('CHAUFFEUR', 1))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCES_INTERDIT');

        Http::assertNothingSent();
    }
}
