<?php

namespace Tests\Feature;

use App\Models\DeclarationPanne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeclarationPanneTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        return $this->tokenHeaders(\App\Support\AuthUser::ADMIN);
    }

    private function fake(string $vehiculeStatut = 'DISPONIBLE', ?array $chauffeur = null, int $chauffeurHttp = 200): void
    {
        $chauffeur ??= ['id_chauffeur' => 1, 'statut' => 'EN_MISSION', 'permis_valide' => true,
                        'selectionnable' => true, 'affectable' => false, 'motifs' => ['DEJA_EN_MISSION']];

        Http::fake([
            '*/api/chauffeurs/*/eligibilite' => Http::response($chauffeur, $chauffeurHttp),
            '*/api/vehicules/1'              => Http::response(['id' => 1, 'statut' => $vehiculeStatut, 'kilometrage' => 50000]),
        ]);
    }

    private function payload(array $o = []): array
    {
        return array_merge([
            'vehicule_id'  => 1,
            'chauffeur_id' => 1,
            'categorie'    => 'PANNE',
            'description'  => 'Moteur qui chauffe',
            'kilometrage'  => 50100,
        ], $o);
    }

    public function test_chauffeur_declare_une_panne(): void
    {
        $this->fake();

        $this->postJson('/api/declarations-panne', $this->payload(), $this->admin())
            ->assertStatus(201)
            ->assertJsonPath('data.statut', 'DECLAREE')
            ->assertJsonPath('data.chauffeur_verifie', true);

        $this->assertDatabaseCount('declarations_panne', 1);
    }

    public function test_vehicule_en_mission_est_accepte(): void
    {
        $this->fake('EN_MISSION');

        $this->postJson('/api/declarations-panne', $this->payload(), $this->admin())->assertStatus(201);
    }

    public function test_vehicule_hors_service_est_refuse(): void
    {
        $this->fake('HORS_SERVICE');

        $this->postJson('/api/declarations-panne', $this->payload(), $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VEHICULE_INACTIF');

        $this->assertDatabaseCount('declarations_panne', 0);
    }
    public function test_chauffeur_en_conge_est_refuse(): void
    {
        $this->fake('DISPONIBLE', ['id_chauffeur' => 1, 'statut' => 'EN_CONGE', 'permis_valide' => true,
                                   'selectionnable' => false, 'affectable' => false, 'motifs' => ['CHAUFFEUR_EN_CONGE']]);

        $this->postJson('/api/declarations-panne', $this->payload(), $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CHAUFFEUR_NON_SELECTIONNABLE');
    }

    public function test_chauffeur_introuvable_renvoie_404(): void
    {
        $this->fake('DISPONIBLE', [], 404);

        $this->postJson('/api/declarations-panne', $this->payload(['chauffeur_id' => 99]), $this->admin())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CHAUFFEUR_INTROUVABLE');
    }

    public function test_service_chauffeur_injoignable_mode_degrade(): void
    {
        Http::fake([
            '*/api/chauffeurs/*/eligibilite' => fn () => throw new ConnectionException('down'),
            '*/api/vehicules/1'              => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
        ]);

        $this->postJson('/api/declarations-panne', $this->payload(), $this->admin())
            ->assertStatus(201)
            ->assertJsonPath('data.chauffeur_verifie', false);
    }

    public function test_categorie_preventive_est_refusee(): void
    {
        $this->fake();

        $this->postJson('/api/declarations-panne', $this->payload(['categorie' => 'VIDANGE']), $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');
    }

    public function test_champs_obligatoires(): void
    {
        $this->fake();

        $this->postJson('/api/declarations-panne', [], $this->admin())->assertStatus(422);
    }

    public function test_liste_et_detail(): void
    {
        $this->fake();
        $d = DeclarationPanne::create($this->payload());

        $this->getJson('/api/declarations-panne', $this->admin())->assertOk()->assertJsonFragment(['id' => $d->id]);
        $this->getJson("/api/declarations-panne/{$d->id}", $this->admin())->assertOk()->assertJsonPath('data.id', $d->id);
        $this->getJson('/api/declarations-panne/999999', $this->admin())->assertStatus(404)
            ->assertJsonPath('error.code', 'RESSOURCE_INTROUVABLE');
    }
}