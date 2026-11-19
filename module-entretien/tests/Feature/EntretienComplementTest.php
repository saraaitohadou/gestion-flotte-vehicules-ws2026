<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Alerte;
use App\Models\Entretien;
use App\Services\EcheanceService;
use App\Support\AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntretienComplementTest extends TestCase
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

    private function termine(array $override = []): Entretien
    {
        return $this->entretien(array_merge(['statut' => StatutEntretien::Termine, 'date_fin' => now()], $override));
    }




    // ---------- Annuler ----------

    public function test_annuler_un_entretien_en_cours_remet_le_vehicule_disponible(): void
    {
        $this->fakeVehicule(statut: 'EN_MAINTENANCE');
        $e = $this->entretien(['statut' => StatutEntretien::EnCours, 'date_debut' => now()]);

        $this->patchJson("/api/entretiens/{$e->id}/annuler", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertOk()->assertJsonPath('data.statut', 'ANNULE');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/api/vehicules/1/statut')
            && $r['statut'] === 'DISPONIBLE');
    }

    public function test_annuler_un_entretien_planifie_ne_touche_pas_au_vehicule(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien();

        $this->patchJson("/api/entretiens/{$e->id}/annuler", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertOk()->assertJsonPath('data.statut', 'ANNULE');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/statut'));
    }

    public function test_annuler_un_entretien_termine_est_interdit(): void
    {
        $e = $this->termine();

        $this->patchJson("/api/entretiens/{$e->id}/annuler", [], $this->auth(AuthUser::GESTIONNAIRE))
            ->assertStatus(409)->assertJsonPath('error.code', 'TRANSITION_INTERDITE');
    }

    // ---------- GET /alertes ----------

    private function alerte(int $vehiculeId, string $type, bool $resolue = false): Alerte
    {
        $e = $this->termine(['vehicule_id' => $vehiculeId]);

        return Alerte::forceCreate([
            'entretien_id' => $e->id,
            'vehicule_id'  => $vehiculeId,
            'type'         => $type,
            'categorie'    => 'VIDANGE',
            'message'      => "Alerte vehicule {$vehiculeId}",
            'resolue_at'   => $resolue ? now() : null,
        ]);
    }


    public function test_alertes_gestionnaire_voit_les_alertes_ouvertes_seulement(): void
    {
        $this->alerte(1, Alerte::PROCHE);
        $this->alerte(2, Alerte::EN_RETARD);
        $this->alerte(3, Alerte::PROCHE, resolue: true);

        $h = $this->auth(AuthUser::GESTIONNAIRE);

        $this->getJson('/api/alertes', $h)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/alertes?inclure_resolues=1', $h)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/alertes?vehicule_id=2', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/alertes?type=PROCHE', $h)->assertOk()->assertJsonCount(1, 'data');
    }



    // ---------- EcheanceService (seuils et cas limites) ----------

    public function test_echeance_par_date_seuils_et_cas_limites(): void
    {
        config(['entretien.alertes.jours_avant' => 15, 'entretien.alertes.km_avant' => 500]);

        $this->termine(['vehicule_id' => 1, 'date_prochaine_echeance' => now()->addDays(15)->toDateString()]); // limite : proche
        $this->termine(['vehicule_id' => 2, 'date_prochaine_echeance' => now()->addDays(16)->toDateString()]); // hors seuil
        $this->termine(['vehicule_id' => 3, 'date_prochaine_echeance' => now()->toDateString()]);              // jour meme : proche
        $this->termine(['vehicule_id' => 4, 'date_prochaine_echeance' => now()->subDay()->toDateString()]);    // hier : retard

        $stats = app(EcheanceService::class)->verifier();

        $this->assertSame(4, $stats['verifies']);
        $this->assertSame(2, $stats['alertes_proche']);
        $this->assertSame(1, $stats['alertes_retard']);
        $this->assertSame(0, Alerte::where('vehicule_id', 2)->count());
        $this->assertSame(Alerte::EN_RETARD, Alerte::where('vehicule_id', 4)->value('type'));
    }

    public function test_echeance_par_kilometrage_proche(): void
    {
        config(['entretien.alertes.km_avant' => 500]);
        $this->fakeVehicule(km: 52000);
        $this->termine(['vehicule_id' => 1, 'km_prochaine_echeance' => 52300]); // reste 300 km

        $stats = app(EcheanceService::class)->verifier();

        $this->assertSame(1, $stats['alertes_proche']);
        $this->assertSame(0, $stats['alertes_retard']);
    }

    public function test_echeance_par_kilometrage_depasse_donne_retard(): void
    {
        $this->fakeVehicule(km: 52500);
        $this->termine(['vehicule_id' => 1, 'km_prochaine_echeance' => 52300]);

        $stats = app(EcheanceService::class)->verifier();

        $this->assertSame(1, $stats['alertes_retard']);
        $this->assertSame(0, $stats['alertes_proche']);
    }

    public function test_echeance_par_kilometrage_loin_ne_genere_rien(): void
    {
        config(['entretien.alertes.km_avant' => 500]);
        $this->fakeVehicule(km: 50000);
        $this->termine(['vehicule_id' => 1, 'km_prochaine_echeance' => 60000]);

        $stats = app(EcheanceService::class)->verifier();

        $this->assertSame(0, $stats['alertes_proche'] + $stats['alertes_retard']);
        $this->assertSame(0, Alerte::count());
    }

    public function test_cron_est_idempotent(): void
    {
        $this->termine(['vehicule_id' => 1, 'date_prochaine_echeance' => now()->subDays(2)->toDateString()]);

        app(EcheanceService::class)->verifier();
        app(EcheanceService::class)->verifier();

        $this->assertSame(1, Alerte::count());
    }
}