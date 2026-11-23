<?php

namespace Tests\Feature;

use App\Models\DeclarationPanne;
use App\Models\Entretien;
use App\Support\AuthUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeclarationPanneTraitementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        return $this->tokenHeaders(AuthUser::ADMIN);
    }

    private function fakeVehicule(?int $km = 50000): void
    {
        $payload = ['id' => 1, 'statut' => 'DISPONIBLE'];
        if ($km !== null) {
            $payload['kilometrage'] = $km;
        }

        Http::fake(['*/api/vehicules/1' => Http::response($payload)]);
    }

    private function declaration(?int $km = 50100): DeclarationPanne
    {
        return DeclarationPanne::create([
            'vehicule_id' => 1, 'chauffeur_id' => 1, 'categorie' => 'PANNE',
            'description' => 'Moteur', 'kilometrage' => $km,
        ]);
    }

    public function test_prendre_en_charge_cree_un_entretien_correctif(): void
    {
        $this->fakeVehicule();
        $d = $this->declaration();

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.statut', 'TRAITEE');

        $this->assertDatabaseCount('entretiens', 1);
        $entretien = Entretien::first();
        $this->assertSame($d->id, $entretien->declaration_panne_id);
        $this->assertSame(50100, (int) $entretien->kilometrage);
        $this->assertNotNull($d->fresh()->entretien_id);
        $this->assertSame('CORRECTIF', $entretien->type);
        $this->assertSame('PLANIFIE', $entretien->statut->value);
    }

    public function test_rejeter(): void
    {
        $d = $this->declaration();

        $this->patchJson("/api/declarations-panne/{$d->id}/rejeter", [], $this->admin())
            ->assertOk()
            ->assertJsonPath('data.statut', 'REJETEE');

        $this->assertDatabaseCount('entretiens', 0);
    }

    public function test_declaration_deja_traitee_refusee(): void
    {
        $d = $this->declaration();
        $this->patchJson("/api/declarations-panne/{$d->id}/rejeter", [], $this->admin())->assertOk();

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DECLARATION_DEJA_TRAITEE');
    }

    public function test_declaration_inconnue_404(): void
    {
        $this->patchJson('/api/declarations-panne/999999/rejeter', [], $this->admin())->assertStatus(404);
    }

    public function test_kilometrage_absent_est_refuse_sans_creer_d_entretien(): void
    {
        $this->fakeVehicule();
        $d = $this->declaration(null);

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'KILOMETRAGE_DECLARATION_MANQUANT');

        $this->assertDatabaseCount('entretiens', 0);
        $this->assertSame(DeclarationPanne::DECLAREE, $d->fresh()->statut);
    }

    public function test_kilometrage_inferieur_a_celui_du_vehicule_est_refuse(): void
    {
        $this->fakeVehicule(50000);
        $d = $this->declaration(49000);

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'KILOMETRAGE_INVALIDE');

        $this->assertDatabaseCount('entretiens', 0);
    }

    public function test_kilometrage_vehicule_indisponible_donne_une_erreur_claire(): void
    {
        $this->fakeVehicule(null);
        $d = $this->declaration(50100);

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'KILOMETRAGE_VEHICULE_INDISPONIBLE');
    }

    public function test_rejou_de_la_meme_prise_en_charge_est_idempotent(): void
    {
        $this->fakeVehicule();
        $d = $this->declaration();

        $premiere = $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())
            ->assertOk()->json('data.entretien_id');

        $seconde = $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())
            ->assertOk()->json('data.entretien_id');

        $this->assertSame($premiere, $seconde);
        $this->assertDatabaseCount('entretiens', 1);
    }

    public function test_deux_requetes_concurrentes_avec_modele_perime_ne_creent_qu_un_entretien(): void
    {
        $this->fakeVehicule();
        $d = $this->declaration();

        // Deux instances du MEME enregistrement : la seconde a un statut périmé,
        // comme lors de deux requêtes HTTP simultanées.
        $stale = DeclarationPanne::findOrFail($d->id);

        $this->patchJson("/api/declarations-panne/{$d->id}/prendre-en-charge", [], $this->admin())->assertOk();
        $this->patchJson("/api/declarations-panne/{$stale->id}/prendre-en-charge", [], $this->admin())->assertOk();

        $this->assertDatabaseCount('entretiens', 1);
        $this->assertSame(
            (int) $d->fresh()->entretien_id,
            (int) Entretien::first()->id
        );
    }

    public function test_index_unique_interdit_deux_entretiens_pour_une_meme_declaration(): void
    {
        $d = $this->declaration();
        $this->fakeVehicule();

        Entretien::create([
            'vehicule_id' => 1, 'declaration_panne_id' => $d->id,
            'type' => 'CORRECTIF', 'categorie' => 'PANNE', 'description' => 'Moteur',
            'date_entretien' => now()->toDateString(), 'kilometrage' => 50100,
        ]);

        $this->expectException(QueryException::class);

        Entretien::create([
            'vehicule_id' => 1, 'declaration_panne_id' => $d->id,
            'type' => 'CORRECTIF', 'categorie' => 'PANNE', 'description' => 'Doublon',
            'date_entretien' => now()->toDateString(), 'kilometrage' => 50100,
        ]);
    }
}
