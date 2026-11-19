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
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", [
            'description' => 'Vidange + filtre',
            'kilometrage' => 51000,
            'cout'        => 1200,
        ])->assertOk();

        $e->refresh();
        $this->assertSame('Vidange + filtre', $e->description);
        $this->assertEquals(51000, $e->kilometrage);
        $this->assertEquals(1200, $e->cout);
    }

    public function test_modifier_un_entretien_en_cours_est_autorise(): void
    {
        Http::fake();
        $e = $this->entretien(['statut' => StatutEntretien::EnCours]);

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Maj en cours'])
            ->assertOk();

        $this->assertSame('Maj en cours', $e->fresh()->description);
    }

    public function test_modifier_un_entretien_termine_est_refuse(): void
    {
        Http::fake();
        $e = $this->entretien(['statut' => StatutEntretien::Termine, 'date_fin' => now()]);

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Nouveau'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ENTRETIEN_NON_MODIFIABLE');

        $this->assertSame('Vidange', $e->fresh()->description);
    }

    public function test_modifier_un_entretien_annule_est_refuse(): void
    {
        Http::fake();
        $e = $this->entretien(['statut' => StatutEntretien::Annule]);

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Nouveau'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ENTRETIEN_NON_MODIFIABLE');
    }

    public function test_modifier_un_entretien_archive_est_refuse(): void
    {
        Http::fake();
        $e = $this->entretien();
        $e->forceFill(['archive_at' => now()])->save();

        $this->putJson("/api/entretiens/{$e->id}", ['description' => 'Nouveau'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ENTRETIEN_NON_MODIFIABLE');
    }

    public function test_cout_negatif_est_refuse_en_422(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['cout' => -5])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');

        $this->assertEquals(850, $e->fresh()->cout);
    }

    public function test_categorie_incoherente_avec_le_type_est_refusee(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", ['categorie' => 'CATEGORIE_INCONNUE'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');

        $this->assertSame('VIDANGE', $e->fresh()->categorie);
    }

    public function test_le_statut_ne_peut_pas_etre_change_par_put(): void
    {
        Http::fake();
        $e = $this->entretien();

        $this->putJson("/api/entretiens/{$e->id}", [
            'description' => 'Tentative',
            'statut'      => 'TERMINE',
        ])->assertOk();

        $this->assertSame(StatutEntretien::Planifie, $e->fresh()->statut);
    }

    public function test_modifier_entretien_inexistant_renvoie_404_json(): void
    {
        $this->putJson('/api/entretiens/999999', ['description' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESSOURCE_INTROUVABLE');
    }
}