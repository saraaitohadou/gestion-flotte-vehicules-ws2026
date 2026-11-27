<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Entretien;
use App\Models\IntegrationOutbox;
use App\Support\AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntretienApiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(string $role, ?int $vehicule = null): array
    {
        $claims = ['sub' => "test-{$role}", 'role' => $role];
        if ($vehicule) {
            $claims['vehicule_id'] = $vehicule;
        }
        return $this->tokenHeaders($role, $vehicule);
    }

    private function fakeVehicule(string $statut = 'DISPONIBLE', int $km = 50000, int $id = 1): void
    {
        Http::fake([
            "*/api/vehicules/{$id}/statut"      => Http::response(['ok' => true]),
            "*/api/vehicules/{$id}/kilometrage" => Http::response(['ok' => true]),
            "*/api/vehicules/{$id}"             => Http::response(['id' => $id, 'statut' => $statut, 'kilometrage' => $km]),
        ]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'vehicule_id'    => 1,
            'type'           => 'PREVENTIF',
            'categorie'      => 'VIDANGE',
            'description'    => 'Vidange moteur',
            'date_entretien' => now()->addDay()->toDateString(),
            'kilometrage'    => 50500,
            'cout'           => 850,
        ], $override);
    }

    private function entretien(array $override = []): Entretien
    {
        return Entretien::create(array_merge([
            'vehicule_id'    => 1,
            'type'           => TypeEntretien::PREVENTIF,
            'categorie'      => 'VIDANGE',
            'description'    => 'Vidange',
            'date_entretien' => now()->addDay()->toDateString(),
            'kilometrage'    => 50500,
            'cout'           => 850,
            'statut'         => StatutEntretien::Planifie,
        ], $override));
    }


    public function test_admin_cree_un_entretien_planifie(): void
    {
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload(), $this->auth(AuthUser::ADMIN))
            ->assertStatus(201)
            ->assertJsonPath('data.statut', 'PLANIFIE');

        $this->assertDatabaseCount('entretiens', 1);
    }

    /** Diagramme de séquence : GET Véhicule 404 => aucun INSERT local. */
    public function test_creation_vehicule_inexistant_renvoie_404(): void
    {
        Http::fake([
            '*/api/vehicules/999' => Http::response(['message' => 'not found'], 404),
        ]);

        $this->postJson(
            '/api/entretiens',
            $this->payload(['vehicule_id' => 999]),
            $this->auth(AuthUser::ADMIN)
        )
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'VEHICULE_INTROUVABLE')
            ->assertJsonPath('error.message', 'Vehicule #999 introuvable.');

        $this->assertDatabaseCount('entretiens', 0);
        $this->assertDatabaseCount('integrations_outbox', 0);
    }

    /**
     * D2/D3 : la planification vérifie la disponibilité (GET Véhicule) mais ne modifie
     * PAS le statut du véhicule. EN_MAINTENANCE n'est atteint qu'au démarrage (§4.2/§4.4).
     */
    public function test_planifier_un_entretien_ne_change_pas_le_statut_du_vehicule(): void
    {
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload(), $this->auth(AuthUser::ADMIN))
            ->assertStatus(201)
            ->assertJsonPath('data.statut', 'PLANIFIE');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_ends_with($r->url(), '/api/vehicules/1'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertDatabaseCount('integrations_outbox', 0);
        $this->assertDatabaseHas('entretiens', [
            'vehicule_id' => 1,
            'statut'      => StatutEntretien::Planifie->value,
        ]);
    }

    public function test_kilometrage_inferieur_au_vehicule_est_refuse(): void
    {
        $this->fakeVehicule(km: 50000);

        $this->postJson('/api/entretiens', $this->payload(['kilometrage' => 40000]), $this->auth(AuthUser::ADMIN))
            ->assertStatus(422)->assertJsonPath('error.code', 'KILOMETRAGE_INVALIDE');
    }

    public function test_categorie_incoherente_avec_le_type_est_refusee(): void
    {
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload(['categorie' => 'PANNE']), $this->auth(AuthUser::ADMIN))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERREUR');
    }

    public function test_vehicule_hors_service_est_refuse(): void
    {
        $this->fakeVehicule(statut: 'HORS_SERVICE');

        $this->postJson('/api/entretiens', $this->payload(), $this->auth(AuthUser::ADMIN))
            
            ->assertStatus(422)->assertJsonPath('error.code', 'VEHICULE_INACTIF');
    }

    public function test_vehicule_en_mission_est_refuse_des_la_creation(): void
    {
        $this->fakeVehicule(statut: 'EN_MISSION');

        $this->postJson('/api/entretiens', $this->payload(), $this->auth(AuthUser::ADMIN))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VEHICULE_EN_MISSION');

        $this->assertDatabaseCount('entretiens', 0);
    }

    public function test_contrainte_base_refuse_deux_entretiens_en_cours_pour_un_meme_vehicule(): void
    {
        $this->entretien(['statut' => StatutEntretien::EnCours]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->entretien(['statut' => StatutEntretien::EnCours]);
    }

    public function test_demarrer_passe_le_vehicule_en_maintenance(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien();

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertOk()->assertJsonPath('data.statut', 'EN_COURS');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/api/vehicules/1/statut')
            && $r['statut'] === 'EN_MAINTENANCE');
    }

    /** Règle §4.4 : on ne peut démarrer que depuis DISPONIBLE (non-régression). */
    public function test_demarrer_sur_vehicule_disponible_est_autorise(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien();

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertOk()->assertJsonPath('data.statut', 'EN_COURS');

        $this->assertSame(StatutEntretien::EnCours, $e->fresh()->statut);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/api/vehicules/1/statut')
            && $r['statut'] === 'EN_MAINTENANCE');
    }

    public function test_demarrer_refuse_un_vehicule_en_maintenance(): void
    {
        $this->assertDemarrerRefuse('EN_MAINTENANCE');
    }

    public function test_demarrer_refuse_un_vehicule_en_mission(): void
    {
        $this->assertDemarrerRefuse('EN_MISSION');
        $this->assertSame(0, IntegrationOutbox::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'demarrer-liberer'));
    }

    public function test_demarrer_refuse_un_vehicule_hors_service(): void
    {
        $this->assertDemarrerRefuse('HORS_SERVICE');
    }

    /** Aucun refus n'écrit localement, ne crée d'outbox ni n'envoie de PATCH. */
    private function assertDemarrerRefuse(string $statut): void
    {
        $this->fakeVehicule(statut: $statut);
        $e = $this->entretien();

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'VEHICULE_NON_DISPONIBLE')
            ->assertJsonPath('error.details.statut_vehicule', $statut);

        $this->assertSame(StatutEntretien::Planifie, $e->fresh()->statut);
        $this->assertDatabaseCount('integrations_outbox', 0);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_deux_entretiens_en_cours_sur_le_meme_vehicule_sont_interdits(): void
    {
        $this->fakeVehicule();
        $this->entretien(['statut' => StatutEntretien::EnCours]);
        $b = $this->entretien(['categorie' => 'FREINS']);

        $this->patchJson("/api/entretiens/{$b->id}/demarrer", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(409)->assertJsonPath('error.code', 'ENTRETIEN_DEJA_EN_COURS');
    }

    public function test_demarrage_suspendu_si_service_vehicule_injoignable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('down');
        });
        $e = $this->entretien();

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(503)->assertJsonPath('error.code', 'VEHICULE_SERVICE_INDISPONIBLE');

        $this->assertSame(StatutEntretien::Planifie, $e->fresh()->statut);
    }

    public function test_aucun_retour_en_arriere(): void
    {
        $this->fakeVehicule();
        $termine  = $this->entretien(['statut' => StatutEntretien::Termine, 'date_fin' => now()]);
        $planifie = $this->entretien(['vehicule_id' => 2]);

        $this->patchJson("/api/entretiens/{$termine->id}/demarrer", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_INTERDITE');

        $this->patchJson("/api/entretiens/{$planifie->id}/terminer", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_INTERDITE');
    }

    public function test_terminer_met_a_jour_vehicule_et_recalcule_l_echeance(): void
    {
        $this->fakeVehicule(statut: 'EN_MAINTENANCE', km: 50000);
        $e = $this->entretien(['statut' => StatutEntretien::EnCours, 'date_debut' => now()]);

        $this->patchJson("/api/entretiens/{$e->id}/terminer", ['kilometrage' => 52300], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertOk()
            ->assertJsonPath('data.statut', 'TERMINE')
            ->assertJsonPath('data.prochaine_echeance.kilometrage', 62300)
            ->assertJsonPath('data.prochaine_echeance.date', now()->addMonths(6)->toDateString());

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/statut') && $r['statut'] === 'DISPONIBLE');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/kilometrage') && $r['kilometrage'] === 52300);
    }



    public function test_entretien_inexistant_renvoie_404_json(): void
    {
        $this->getJson('/api/entretiens/999', $this->auth(AuthUser::ADMIN))
            ->assertStatus(404)->assertJsonPath('error.code', 'RESSOURCE_INTROUVABLE');
    }
}
