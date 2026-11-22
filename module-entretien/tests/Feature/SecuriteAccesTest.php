<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\DeclarationPanne;
use App\Support\AuthUser;
use App\Support\Jwt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecuriteAccesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Chaque test fournit explicitement son token : plus de token admin automatique.
    }

    private function token(array $claims): array
    {
        return ['Authorization' => 'Bearer ' . Jwt::encode(
            $claims + ['exp' => time() + 3600],
            (string) config('auth_jwt.secret')
        )];
    }

    private function chauffeur(int $id, ?int $vehicule = null): array
    {
        $claims = [
            'sub'           => (string) $id,
            'id_chauffeur'  => $id,
            'role'          => AuthUser::CHAUFFEUR,
        ];

        if ($vehicule !== null) {
            $claims['vehicule_id'] = $vehicule;
        }

        return $this->token($claims);
    }

    private function fakeServices(): void
    {
        Http::fake([
            '*/api/chauffeurs/*/eligibilite' => Http::response([
                'id_chauffeur' => 1, 'statut' => 'EN_MISSION', 'permis_valide' => true, 'selectionnable' => true,
            ]),
            '*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
        ]);
    }

    private function declaration(int $chauffeurId, int $vehiculeId = 1): DeclarationPanne
    {
        return DeclarationPanne::create([
            'vehicule_id'  => $vehiculeId,
            'chauffeur_id' => $chauffeurId,
            'categorie'    => 'PANNE',
            'description'  => 'Moteur qui chauffe',
        ]);
    }

    // --- 1. Fail-open supprimé ---

    public function test_authuser_sans_jwt_est_fail_closed(): void
    {
        try {
            AuthUser::fromRequest(Request::create('/api/entretiens'));
            $this->fail('Une requête sans JWT ne doit jamais produire un ADMINISTRATEUR.');
        } catch (BusinessException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('NON_AUTHENTIFIE', $e->errorCode);
        }
    }

    // --- 2. Filtrage des déclarations par chauffeur ---

    public function test_chauffeur_voit_uniquement_ses_declarations(): void
    {
        $mienne = $this->declaration(1);
        $this->declaration(2);

        $this->getJson('/api/declarations-panne', $this->chauffeur(1))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mienne->id);
    }

    public function test_chauffeur_ne_peut_pas_lire_la_declaration_dun_autre(): void
    {
        $autre = $this->declaration(2);

        $this->getJson("/api/declarations-panne/{$autre->id}", $this->chauffeur(1))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCES_REFUSE');
    }

    public function test_chauffeur_ne_peut_pas_filtrer_sur_le_vehicule_dun_autre(): void
    {
        $this->getJson('/api/declarations-panne?vehicule_id=9', $this->chauffeur(1, 1))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCES_REFUSE');
    }

    public function test_admin_voit_toutes_les_declarations(): void
    {
        $this->declaration(1);
        $this->declaration(2);

        $this->getJson('/api/declarations-panne', $this->token(['sub' => 'admin-1', 'role' => AuthUser::ADMIN]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // --- 3. Identité dérivée du JWT (plus de chauffeur_id venant du body) ---

    public function test_chauffeur_ne_peut_pas_declarer_pour_un_autre_chauffeur(): void
    {
        $this->fakeServices();

        $this->postJson('/api/declarations-panne', [
            'vehicule_id'  => 1,
            'chauffeur_id' => 999, // tentative d'usurpation
            'categorie'    => 'PANNE',
            'description'  => 'Moteur qui chauffe',
        ], $this->chauffeur(1))->assertStatus(201);

        $this->assertDatabaseHas('declarations_panne', [
            'chauffeur_id' => 1,
            'created_by'   => '1',
        ]);
        $this->assertDatabaseMissing('declarations_panne', ['chauffeur_id' => 999]);
    }

    public function test_chauffeur_sans_identite_dans_le_token_est_refuse(): void
    {
        $this->postJson('/api/declarations-panne', [
            'vehicule_id' => 1,
            'categorie'   => 'PANNE',
            'description' => 'Moteur qui chauffe',
        ], $this->token(['sub' => 'inconnu', 'role' => AuthUser::CHAUFFEUR]))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'IDENTITE_CHAUFFEUR_MANQUANTE');
    }

    public function test_chauffeur_ne_peut_pas_declarer_pour_un_autre_vehicule(): void
    {
        $this->fakeServices();

        $this->postJson('/api/declarations-panne', [
            'vehicule_id' => 2,
            'categorie'   => 'PANNE',
            'description' => 'Moteur qui chauffe',
        ], $this->chauffeur(1, 1))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCES_REFUSE');
    }

    public function test_admin_peut_encore_declarer_pour_un_chauffeur_designe(): void
    {
        $this->fakeServices();

        $this->postJson('/api/declarations-panne', [
            'vehicule_id'  => 1,
            'chauffeur_id' => 7,
            'categorie'    => 'PANNE',
            'description'  => 'Moteur qui chauffe',
        ], $this->token(['sub' => 'admin-1', 'role' => AuthUser::ADMIN]))
            ->assertStatus(201)
            ->assertJsonPath('data.chauffeur_verifie', true);

        $this->assertDatabaseHas('declarations_panne', ['chauffeur_id' => 7]);
    }
}
