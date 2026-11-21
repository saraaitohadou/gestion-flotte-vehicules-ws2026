<?php

namespace Tests\Feature;

use App\Models\DeclarationPanne;
use App\Models\Entretien;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeclarationPanneTraitementTest extends TestCase
{
    use RefreshDatabase;

    private function declaration(): DeclarationPanne
    {
        return DeclarationPanne::create([
            'vehicule_id' => 1, 'chauffeur_id' => 1, 'categorie' => 'PANNE',
            'description' => 'Moteur', 'kilometrage' => 50100,
        ]);
    }

    public function test_prendre_en_charge_cree_un_entretien_correctif(): void
    {
        $d = $this->declaration();

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge")
            ->assertOk()
            ->assertJsonPath('data.statut', 'TRAITEE');

        $this->assertDatabaseCount('entretiens', 1);
        $this->assertNotNull($d->fresh()->entretien_id);
        $this->assertSame('CORRECTIF', Entretien::first()->type->value);
        $this->assertSame('PLANIFIE', Entretien::first()->statut->value);
    }

    public function test_rejeter(): void
    {
        $d = $this->declaration();

        $this->patchJson("/api/declarations-panne/{$d->id}/rejeter")
            ->assertOk()
            ->assertJsonPath('data.statut', 'REJETEE');

        $this->assertDatabaseCount('entretiens', 0);
    }

    public function test_declaration_deja_traitee_refusee(): void
    {
        $d = $this->declaration();
        $this->patchJson("/api/declarations-panne/{$d->id}/rejeter")->assertOk();

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DECLARATION_DEJA_TRAITEE');
    }

    public function test_declaration_inconnue_404(): void
    {
        $this->patchJson('/api/declarations-panne/999999/rejeter')->assertStatus(404);
    }
}