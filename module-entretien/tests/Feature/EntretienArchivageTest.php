<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Entretien;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntretienArchivageTest extends TestCase
{
    use RefreshDatabase;

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

    // ---------- Archivage (DELETE) ----------

    public function test_archiver_un_entretien_termine_ne_supprime_pas_la_ligne(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien(['statut' => StatutEntretien::Termine, 'date_fin' => now()]);

        $this->deleteJson("/api/entretiens/{$e->id}")->assertOk();

        $this->assertDatabaseHas('entretiens', ['id' => $e->id]);
        $e->refresh();
        $this->assertNotNull($e->archive_at);
        $this->assertNotNull($e->archived_by);
    }

    public function test_archiver_un_entretien_annule_est_autorise(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien(['statut' => StatutEntretien::Annule]);

        $this->deleteJson("/api/entretiens/{$e->id}")->assertOk();

        $this->assertNotNull($e->fresh()->archive_at);
    }

    public function test_archiver_un_entretien_planifie_est_refuse(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien();

        $this->deleteJson("/api/entretiens/{$e->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ARCHIVAGE_INTERDIT');

        $this->assertNull($e->fresh()->archive_at);
    }

    public function test_archiver_un_entretien_en_cours_est_refuse(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien(['statut' => StatutEntretien::EnCours]);

        $this->deleteJson("/api/entretiens/{$e->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ARCHIVAGE_INTERDIT');
    }

    public function test_archiver_deux_fois_donne_409(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien(['statut' => StatutEntretien::Termine, 'date_fin' => now()]);

        $this->deleteJson("/api/entretiens/{$e->id}")->assertOk();
        $this->deleteJson("/api/entretiens/{$e->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DEJA_ARCHIVE');
    }

    public function test_archiver_entretien_inexistant_renvoie_404_json(): void
    {
        $this->deleteJson('/api/entretiens/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESSOURCE_INTROUVABLE');
    }

    // ---------- Historique ----------

    public function test_historique_du_vehicule_liste_ses_entretiens(): void
    {
        $this->fakeVehicule();
        $a = $this->entretien();
        $b = $this->entretien(['description' => 'Freins', 'categorie' => 'FREINS']);

        $this->getJson('/api/vehicules/1/entretiens')
            ->assertOk()
            ->assertJsonFragment(['id' => $a->id])
            ->assertJsonFragment(['id' => $b->id]);
    }

    public function test_historique_ne_contient_pas_les_entretiens_d_un_autre_vehicule(): void
    {
        $this->fakeVehicule();
        $autre = $this->entretien(['vehicule_id' => 2]);

        $this->getJson('/api/vehicules/1/entretiens')
            ->assertOk()
            ->assertJsonMissing(['id' => $autre->id]);
    }

    // ---------- Health ----------

    public function test_health_repond_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'service' => 'entretien']);
    }
}