<?php

namespace Tests\Feature;

use App\Enums\TypeEntretien;
use App\Models\Entretien;
use App\Services\EntretienService;
use App\Support\AuthUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TypeEntretienConfigurableTest extends TestCase
{
    use RefreshDatabase;

    private function fakeVehicule(): void
    {
        Http::fake([
            '*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'vehicule_id'    => 1,
            'type'           => 'PREVENTIF',
            'categorie'      => 'VIDANGE',
            'description'    => 'Vidange',
            'date_entretien' => now()->addDay()->toDateString(),
            'kilometrage'    => 50500,
            'cout'           => 850,
        ], $override);
    }

    public function test_un_type_ajoute_a_la_config_est_accepte_sans_modifier_le_code(): void
    {
        config(['entretien.types.MAINTENANCE' => ['CONTROLE' => ['mois' => 3, 'km' => 5000]]]);
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload([
            'type'      => 'MAINTENANCE',
            'categorie' => 'CONTROLE',
        ]), $this->tokenHeaders(AuthUser::ADMIN))
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'MAINTENANCE');

        $this->assertSame('MAINTENANCE', Entretien::first()->type);
        $this->assertContains('MAINTENANCE', TypeEntretien::types());
    }

    public function test_un_type_absent_de_la_config_est_refuse(): void
    {
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload(['type' => 'TYPE_INCONNU']),
            $this->tokenHeaders(AuthUser::ADMIN))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');
    }

    public function test_une_categorie_inconnue_dun_type_de_config_est_refusee(): void
    {
        config(['entretien.types.MAINTENANCE' => ['CONTROLE' => ['mois' => 3, 'km' => 5000]]]);
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload([
            'type'      => 'MAINTENANCE',
            'categorie' => 'AUTRE_CONTROLE',
        ]), $this->tokenHeaders(AuthUser::ADMIN))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');
    }

    public function test_lecalcul_decheance_utilise_la_periodicite_du_type_de_config(): void
    {
        config(['entretien.types.MAINTENANCE' => ['CONTROLE' => ['mois' => 3, 'km' => 5000]]]);

        $entretien = new Entretien(['type' => 'MAINTENANCE', 'categorie' => 'CONTROLE']);
        [$date, $km] = app(EntretienService::class)->calculerEcheance(
            $entretien,
            Carbon::parse('2026-11-22'),
            50500
        );

        $this->assertSame('2027-02-22', $date->toDateString());
        $this->assertSame(55500, $km);
    }
}
