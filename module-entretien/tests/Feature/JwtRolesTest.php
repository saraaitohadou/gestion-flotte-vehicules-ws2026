<?php

namespace Tests\Feature;

use App\Models\DeclarationPanne;
use App\Models\Entretien;
use App\Support\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sans_token_401(): void
    {
        $this->getJson('/api/entretiens')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'NON_AUTHENTIFIE');
    }

    public function test_token_falsifie_401(): void
    {
        $token = Jwt::encode(['sub' => 'x', 'role' => 'ADMINISTRATEUR', 'exp' => time() + 60], 'mauvais-secret');

        $this->getJson('/api/entretiens', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_INVALIDE');
    }

    public function test_token_expire_401(): void
    {
        $this->getJson('/api/entretiens', $this->tokenHeaders('ADMINISTRATEUR', null, -60))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'TOKEN_EXPIRE');
    }

    public function test_health_reste_public(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    public function test_chauffeur_ne_peut_pas_gerer_les_entretiens_403(): void
    {
        $this->postJson('/api/entretiens', [], $this->tokenHeaders('CHAUFFEUR'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCES_INTERDIT');
    }

    public function test_chauffeur_ne_peut_pas_prendre_en_charge_403(): void
    {
        $d = DeclarationPanne::create(['vehicule_id' => 1, 'chauffeur_id' => 1, 'categorie' => 'PANNE', 'description' => 'Test']);

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->tokenHeaders('CHAUFFEUR'))
            ->assertStatus(403);
    }

    public function test_gestionnaire_ne_peut_pas_archiver_403(): void
    {
        $e = Entretien::create(['vehicule_id' => 1, 'type' => 'PREVENTIF', 'categorie' => 'VIDANGE', 'description' => 'Test', 'date_entretien' => '2026-11-10', 'kilometrage' => 50000]);

        $this->deleteJson("/api/entretiens/{$e->id}", [], $this->tokenHeaders('GESTIONNAIRE'))
            ->assertStatus(403);
    }

    public function test_gestionnaire_ne_peut_pas_declarer_une_panne_403(): void
    {
        $this->postJson('/api/declarations-panne', [], $this->tokenHeaders('GESTIONNAIRE'))
            ->assertStatus(403);
    }

    public function test_gestionnaire_peut_lister_les_alertes(): void
    {
        $this->getJson('/api/alertes', $this->tokenHeaders('GESTIONNAIRE'))->assertOk();
    }
}