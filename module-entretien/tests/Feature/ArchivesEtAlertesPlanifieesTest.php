<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Alerte;
use App\Models\Entretien;
use App\Services\EcheanceService;
use App\Support\AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Extensions : filtre d'archivage et alertes des entretiens PLANIFIES. */
class ArchivesEtAlertesPlanifieesTest extends TestCase
{
    use RefreshDatabase;

    private function h(): array
    {
        return $this->tokenHeaders(AuthUser::GESTIONNAIRE);
    }

    private function entretien(array $override = []): Entretien
    {
        return Entretien::create(array_merge([
            'vehicule_id'    => 1,
            'type'           => TypeEntretien::PREVENTIF,
            'categorie'      => 'VIDANGE',
            'description'    => 'Vidange',
            'date_entretien' => now()->addDay()->toDateString(),
            'kilometrage'    => 50000,
            'cout'           => 850,
            'statut'         => StatutEntretien::Planifie,
        ], $override));
    }

    private function fakeVehicule(string $statut = 'DISPONIBLE', int $km = 50000): void
    {
        Http::fake([
            '*/api/vehicules/1/statut'      => Http::response(['ok' => true]),
            '*/api/vehicules/1/kilometrage' => Http::response(['ok' => true]),
            '*/api/vehicules/1'             => Http::response(['id' => 1, 'statut' => $statut, 'kilometrage' => $km]),
        ]);
    }

    // ---------------- 1. Archives ----------------

    public function test_liste_exclut_les_archives_par_defaut(): void
    {
        $visible = $this->entretien();
        $archivee = $this->entretien();
        Entretien::whereKey($archivee->id)->update(['archive_at' => now()]);

        $res = $this->getJson('/api/entretiens', $this->h())->assertOk();

        $this->assertSame([$visible->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_liste_inclut_les_archives_avec_archives_inclure(): void
    {
        $this->entretien();
        $archivee = $this->entretien();
        Entretien::whereKey($archivee->id)->update(['archive_at' => now()]);

        $res = $this->getJson('/api/entretiens?archives=inclure', $this->h())->assertOk();

        $this->assertSame(2, $res->json('meta.total'));
        $this->assertTrue((bool) collect($res->json('data'))->firstWhere('id', $archivee->id)['archive']);
    }

    public function test_liste_avec_archives_seulement(): void
    {
        $this->entretien();
        $archivee = $this->entretien();
        Entretien::whereKey($archivee->id)->update(['archive_at' => now()]);

        $res = $this->getJson('/api/entretiens?archives=seulement', $this->h())->assertOk();

        $this->assertSame([$archivee->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertTrue((bool) $res->json('data.0.archive'));
    }

    public function test_compatibilite_inclure_archives_1(): void
    {
        $this->entretien();
        $archivee = $this->entretien();
        Entretien::whereKey($archivee->id)->update(['archive_at' => now()]);

        $this->getJson('/api/entretiens?inclure_archives=1', $this->h())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_parametre_archives_invalide_est_422(): void
    {
        $this->getJson('/api/entretiens?archives=tous', $this->h())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERREUR');
    }

    // ---------------- 2. Alertes des entretiens planifiés ----------------

    public function test_entretien_planifie_en_retard_genere_alerte_en_retard(): void
    {
        $e = $this->entretien(['date_entretien' => now()->subDays(2)->toDateString()]);

        $stats = app(EcheanceService::class)->verifier();

        $alerte = Alerte::where('entretien_id', $e->id)->firstOrFail();
        $this->assertSame(Alerte::EN_RETARD, $alerte->type);
        $this->assertSame($e->date_entretien->toDateString(), $alerte->echeance_date->toDateString());
        $this->assertStringContainsString('en retard de 2 jour(s)', $alerte->message);
        $this->assertSame(1, $stats['alertes_retard']);
    }

    public function test_entretien_planifie_dans_10_jours_genere_alerte_proche(): void
    {
        config(['entretien.alertes.jours_avant' => 15]);
        $e = $this->entretien(['date_entretien' => now()->addDays(10)->toDateString()]);

        app(EcheanceService::class)->verifier();

        $alerte = Alerte::where('entretien_id', $e->id)->firstOrFail();
        $this->assertSame(Alerte::PROCHE, $alerte->type);
        $this->assertStringContainsString('dans 10 jour(s)', $alerte->message);
    }

    public function test_entretien_planifie_dans_40_jours_ne_genere_rien(): void
    {
        config(['entretien.alertes.jours_avant' => 15]);
        $e = $this->entretien(['date_entretien' => now()->addDays(40)->toDateString()]);

        app(EcheanceService::class)->verifier();

        $this->assertSame(0, Alerte::where('entretien_id', $e->id)->count());
    }

    public function test_cron_lance_deux_fois_ne_cree_qu_une_seule_alerte(): void
    {
        $e = $this->entretien(['date_entretien' => now()->addDays(5)->toDateString()]);

        app(EcheanceService::class)->verifier();
        app(EcheanceService::class)->verifier();

        $this->assertSame(1, Alerte::where('entretien_id', $e->id)->count());
        $this->assertNull(Alerte::where('entretien_id', $e->id)->value('resolue_at'));
    }

    public function test_alerte_planifiee_resolue_n_est_pas_rouverte(): void
    {
        $e = $this->entretien(['date_entretien' => now()->subDay()->toDateString()]);

        app(EcheanceService::class)->verifier();
        $alerte = Alerte::where('entretien_id', $e->id)->firstOrFail();
        $alerte->update(['resolue_at' => now()]);
        app(EcheanceService::class)->verifier();

        $this->assertSame(1, Alerte::count());
        $this->assertNotNull($alerte->fresh()->resolue_at);
    }

    public function test_demarrage_resout_l_alerte_planifiee(): void
    {
        $this->fakeVehicule();
        $e = $this->entretien(['date_entretien' => now()->addDays(3)->toDateString()]);
        app(EcheanceService::class)->verifier();
        $this->assertNotNull(Alerte::where('entretien_id', $e->id)->value('id'));

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->h())->assertOk();

        $this->assertNotNull(Alerte::where('entretien_id', $e->id)->value('resolue_at'));
    }

    public function test_annulation_resout_l_alerte_planifiee(): void
    {
        $e = $this->entretien(['date_entretien' => now()->addDays(3)->toDateString()]);
        app(EcheanceService::class)->verifier();
        $this->assertNotNull(Alerte::where('entretien_id', $e->id)->value('id'));

        $this->patchJson("/api/entretiens/{$e->id}/annuler", [], $this->h())->assertOk();

        $this->assertNotNull(Alerte::where('entretien_id', $e->id)->value('resolue_at'));
    }

    public function test_terminer_ne_ferme_pas_l_alerte_dun_autre_entretien_planifie(): void
    {
        $this->fakeVehicule('EN_MAINTENANCE');
        $a = $this->entretien([
            'statut'         => StatutEntretien::EnCours,
            'date_entretien' => now()->subDay()->toDateString(),
            'date_debut'     => now()->subDay(),
        ]);
        $b = $this->entretien(['date_entretien' => now()->addDays(5)->toDateString()]);

        Alerte::forceCreate([
            'entretien_id' => $a->id, 'vehicule_id' => 1, 'type' => Alerte::PROCHE,
            'categorie' => 'VIDANGE', 'message' => 'Ancienne echeance',
        ]);
        app(EcheanceService::class)->verifier(); // alerte planifiée de $b uniquement
        $this->assertNotNull(Alerte::where('entretien_id', $b->id)->value('id'));

        $this->patchJson("/api/entretiens/{$a->id}/terminer", [], $this->h())->assertOk();

        $this->assertNull(Alerte::where('entretien_id', $b->id)->value('resolue_at'));
        $this->assertNotNull(Alerte::where('entretien_id', $a->id)->value('resolue_at'));
    }
}
