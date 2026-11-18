<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Alerte;
use App\Models\Entretien;
use App\Support\AuthUser;
use App\Support\JwtService;
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
        return [];
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
            'date_entretien' => '2026-11-10',
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
            'date_entretien' => '2026-11-10',
            'kilometrage'    => 50500,
            'cout'           => 850,
            'statut'         => StatutEntretien::Planifie,
        ], $override));
    }


    public function test_gestionnaire_cree_un_entretien_planifie(): void
    {
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload(), $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(201)
            ->assertJsonPath('data.statut', 'PLANIFIE');

        $this->assertDatabaseCount('entretiens', 1);
    }


    public function test_kilometrage_inferieur_au_vehicule_est_refuse(): void
    {
        $this->fakeVehicule(km: 50000);

        $this->postJson('/api/entretiens', $this->payload(['kilometrage' => 40000]), $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(422)->assertJsonPath('error.code', 'KILOMETRAGE_INVALIDE');
    }

    public function test_categorie_incoherente_avec_le_type_est_refusee(): void
    {
        $this->fakeVehicule();

        $this->postJson('/api/entretiens', $this->payload(['categorie' => 'PANNE']), $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERREUR');
    }

    public function test_vehicule_hors_service_est_refuse(): void
    {
        $this->fakeVehicule(statut: 'HORS_SERVICE');

        $this->postJson('/api/entretiens', $this->payload(), $this->auth(AuthUser::GESTIONNAIRE))
            
            ->assertStatus(422)->assertJsonPath('error.code', 'VEHICULE_INACTIF');
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

    public function test_cron_genere_les_alertes_proche_et_en_retard(): void
    {
        $this->entretien([
            'vehicule_id' => 1, 'statut' => StatutEntretien::Termine, 'date_fin' => now(),
            'date_prochaine_echeance' => now()->addDays(10)->toDateString(),
        ]);
        $this->entretien([
            'vehicule_id' => 2, 'statut' => StatutEntretien::Termine, 'date_fin' => now(),
            'date_prochaine_echeance' => now()->subDays(3)->toDateString(),
        ]);
        $this->entretien([ // loin dans le futur : pas d'alerte
            'vehicule_id' => 3, 'statut' => StatutEntretien::Termine, 'date_fin' => now(),
            'date_prochaine_echeance' => now()->addMonths(5)->toDateString(),
        ]);

        $this->artisan('entretiens:verifier-echeances')->assertSuccessful();

        $this->assertSame(1, Alerte::where('type', Alerte::PROCHE)->count());
        $this->assertSame(1, Alerte::where('type', Alerte::EN_RETARD)->count());

        $this->getJson('/api/alertes?type=EN_RETARD', $this->auth(AuthUser::GESTIONNAIRE))
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
