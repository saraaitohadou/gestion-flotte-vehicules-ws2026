<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Entretien;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntretienModificationTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        return $this->tokenHeaders(\App\Support\AuthUser::GESTIONNAIRE);
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

    public function test_modifier_un_entretien_planifie(): void
    {
        Http::fake([
            '*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
        ]);
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", [
            'description' => 'Vidange + filtre',
            'kilometrage' => 51000,
            'cout'        => 1200,
        ], $this->auth())->assertOk();

        $e->refresh();
        $this->assertSame('Vidange + filtre', $e->description);
        $this->assertEquals(51000, $e->kilometrage);
        $this->assertEquals(1200, $e->cout);
    }

    public function test_modifier_un_entretien_en_cours_est_autorise(): void
    {
        Http::fake();
        $e = $this->entretien(['statut' => StatutEntretien::EnCours]);

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Maj en cours'], $this->auth())
            ->assertOk();

        $this->assertSame('Maj en cours', $e->fresh()->description);
    }

    public function test_modifier_un_entretien_termine_est_refuse(): void
    {
        Http::fake();
        $e = $this->entretien(['statut' => StatutEntretien::Termine, 'date_fin' => now()]);

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Nouveau'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ENTRETIEN_NON_MODIFIABLE');

        $this->assertSame('Vidange', $e->fresh()->description);
    }

    public function test_modifier_un_entretien_annule_est_refuse(): void
    {
        Http::fake();
        $e = $this->entretien(['statut' => StatutEntretien::Annule]);

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Nouveau'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ENTRETIEN_NON_MODIFIABLE');
    }

    public function test_modifier_un_entretien_archive_est_refuse(): void
    {
        Http::fake();
        $e = $this->entretien();
        $e->forceFill(['archive_at' => now()])->save();

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Nouveau'], $this->auth())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ENTRETIEN_NON_MODIFIABLE');
    }

    public function test_cout_negatif_est_refuse_en_422(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['cout' => -5], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');

        $this->assertEquals(850, $e->fresh()->cout);
    }

    public function test_categorie_incoherente_avec_le_type_est_refusee(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['categorie' => 'CATEGORIE_INCONNUE'], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');

        $this->assertSame('VIDANGE', $e->fresh()->categorie);
    }

    public function test_changer_de_categorie_recalcule_le_prochain_echeance(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['categorie' => 'FREINS'], $this->auth())
            ->assertOk();

        $e->refresh();
        // VIDANGE = 6 mois / 10 000 km ; FREINS = 12 mois / 30 000 km.
        $this->assertEquals(
            now()->parse('2026-11-10')->addMonths(12)->toDateString(),
            $e->date_prochaine_echeance->toDateString()
        );
        $this->assertSame(80500, (int) $e->km_prochaine_echeance);
    }

    public function test_changer_de_categorie_resout_les_alertes_de_lancienne_echeance(): void
    {
        Http::fake();
        $e = $this->entretien();
        \App\Models\Alerte::forceCreate([
            'entretien_id' => $e->id, 'vehicule_id' => 1, 'type' => \App\Models\Alerte::PROCHE,
            'categorie' => 'VIDANGE', 'message' => 'Ancienne alerte',
        ]);

        $this->putJson("/api/entretiens/{$e->id}", ['categorie' => 'PNEUS'], $this->auth())->assertOk();

        $this->assertNotNull(\App\Models\Alerte::first()->resolue_at);
    }

    public function test_le_statut_ne_peut_pas_etre_change_par_put(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", [
            'description' => 'Tentative',
            'statut'      => 'TERMINE',
        ], $this->auth())->assertOk();

        $this->assertSame(StatutEntretien::Planifie, $e->fresh()->statut);
    }

    public function test_modifier_entretien_inexistant_renvoie_404_json(): void
    {
        $this->putJson('/api/entretiens/999999', ['description' => 'x'], $this->auth())
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESSOURCE_INTROUVABLE');
    }

    public function test_kilometrage_put_inferieur_au_vehicule_est_refuse(): void
    {
        Http::fake([
            '*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 60000]),
        ]);
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['kilometrage' => 55000], $this->auth())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'KILOMETRAGE_INVALIDE');

        $this->assertEquals(50500, $e->fresh()->kilometrage);
    }

    public function test_kilometrage_put_egal_au_vehicule_est_accepte(): void
    {
        Http::fake([
            '*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 51000]),
        ]);
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['kilometrage' => 51000], $this->auth())->assertOk();

        $this->assertEquals(51000, $e->fresh()->kilometrage);
    }

    public function test_put_sans_kilometrage_ne_contacte_pas_le_service_vehicule(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Sans km'], $this->auth())->assertOk();

        Http::assertNothingSent();
        $this->assertEquals(50500, $e->fresh()->kilometrage);
    }
}