<?php

namespace Tests\Feature;

use App\Models\DeclarationPanne;
use App\Models\Entretien;
use App\Models\Gestionnaire;
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

    public function test_gestionnaire_ne_peut_pas_creer_un_entretien_403(): void
    {
        $this->postJson('/api/entretiens', [], $this->tokenHeaders('GESTIONNAIRE'))
            ->assertStatus(403);
    }

    public function test_admin_ne_peut_pas_demarrer_ni_terminer_403(): void
    {
        $e = Entretien::create([
            'vehicule_id' => 1, 'type' => 'PREVENTIF', 'categorie' => 'VIDANGE',
            'description' => 'Test', 'date_entretien' => '2026-11-10',
            'kilometrage' => 50000, 'statut' => 'PLANIFIE',
        ]);

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->tokenHeaders('ADMINISTRATEUR'))
            ->assertStatus(403);
        $this->patchJson("/api/entretiens/{$e->id}/terminer", [], $this->tokenHeaders('ADMINISTRATEUR'))
            ->assertStatus(403);
    }

    public function test_les_responsables_sont_affiches_par_nom_pas_par_id(): void
    {
        $g = Gestionnaire::create([
            'username'      => 'gestionnaire-nom',
            'password_hash' => 'x',
            'actif'         => true,
        ]);
        $e = Entretien::create([
            'vehicule_id' => 1, 'type' => 'PREVENTIF', 'categorie' => 'VIDANGE',
            'description' => 'Test', 'date_entretien' => '2026-11-10',
            'kilometrage' => 50000, 'statut' => 'PLANIFIE', 'created_by' => (string) $g->id,
        ]);

        $this->getJson("/api/entretiens/{$e->id}", $this->tokenHeaders('ADMINISTRATEUR'))
            ->assertOk()
            ->assertJsonPath('data.responsables.cree_par', 'gestionnaire-nom');
    }

    public function test_gestionnaire_ne_peut_pas_lister_ni_traiter_les_declarations_403(): void
    {
        $d = DeclarationPanne::create([
            'vehicule_id' => 1, 'chauffeur_id' => 1, 'categorie' => 'PANNE',
            'description' => 'Test',
        ]);

        $this->getJson('/api/declarations-panne', $this->tokenHeaders('GESTIONNAIRE'))
            ->assertStatus(403);
        $this->getJson("/api/declarations-panne/{$d->id}", $this->tokenHeaders('GESTIONNAIRE'))
            ->assertStatus(403);
        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->tokenHeaders('GESTIONNAIRE'))
            ->assertStatus(403);
        $this->patchJson("/api/declarations-panne/{$d->id}/rejeter", [], $this->tokenHeaders('GESTIONNAIRE'))
            ->assertStatus(403);
    }
}