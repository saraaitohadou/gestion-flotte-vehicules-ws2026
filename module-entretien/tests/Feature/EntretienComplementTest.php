<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Entretien;
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
}