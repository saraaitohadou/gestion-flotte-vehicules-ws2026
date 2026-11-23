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

class AlertesNonReouvertesTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_cron_ne_reouvre_pas_une_alerte_deja_resolue(): void
    {
        $e = Entretien::create([
            'vehicule_id' => 1, 'type' => TypeEntretien::PREVENTIF, 'categorie' => 'VIDANGE',
            'description' => 'Vidange', 'date_entretien' => now()->subDay()->toDateString(),
            'kilometrage' => 50000, 'cout' => 850, 'statut' => StatutEntretien::Termine,
            'date_fin' => now()->subDay(), 'date_prochaine_echeance' => now()->subDays(3)->toDateString(),
        ]);

        $alerte = Alerte::forceCreate([
            'entretien_id' => $e->id, 'vehicule_id' => 1, 'type' => Alerte::EN_RETARD,
            'categorie' => 'VIDANGE', 'message' => 'Alerte resolue manuellement',
            'resolue_at' => now()->subDay(),
        ]);

        app(EcheanceService::class)->verifier();
        app(EcheanceService::class)->verifier(); // plusieurs nuits

        $this->assertSame(1, Alerte::count());
        $this->assertNotNull($alerte->fresh()->resolue_at);
    }

    private function entretienTermine(string $dateEcheance): Entretien
    {
        return Entretien::create([
            'vehicule_id'             => 1,
            'type'                    => TypeEntretien::PREVENTIF,
            'categorie'               => 'VIDANGE',
            'description'             => 'Vidange',
            'date_entretien'          => now()->subMonth()->toDateString(),
            'kilometrage'             => 50000,
            'cout'                    => 850,
            'statut'                  => StatutEntretien::Termine,
            'date_fin'                => now()->subMonth(),
            'date_prochaine_echeance' => $dateEcheance,
        ]);
    }

    public function test_proche_et_en_retard_ne_restent_pas_ouverts_en_meme_temps_apres_changement_de_date(): void
    {
        $e = $this->entretienTermine(now()->addDays(5)->toDateString());

        app(EcheanceService::class)->verifier();
        $this->assertSame(Alerte::PROCHE, Alerte::where('entretien_id', $e->id)->whereNull('resolue_at')->value('type'));

        // La date d'échéance recule dans le passé : PROCHE doit être fermée.
        Entretien::whereKey($e->id)->update(['date_prochaine_echeance' => now()->subDays(2)->toDateString()]);
        app(EcheanceService::class)->verifier();

        $ouvertes = Alerte::where('entretien_id', $e->id)->whereNull('resolue_at')->get();
        $this->assertCount(1, $ouvertes);
        $this->assertSame(Alerte::EN_RETARD, $ouvertes->first()->type);
        $this->assertNotNull(Alerte::where('entretien_id', $e->id)->where('type', Alerte::PROCHE)->value('resolue_at'));
    }

    public function test_recalcul_vers_une_echeance_lointaine_resout_l_alerte_proche(): void
    {
        $e = $this->entretienTermine(now()->addDays(3)->toDateString());

        app(EcheanceService::class)->verifier();
        $this->assertSame(1, Alerte::where('entretien_id', $e->id)->whereNull('resolue_at')->count());

        Entretien::whereKey($e->id)->update(['date_prochaine_echeance' => now()->addMonths(6)->toDateString()]);
        app(EcheanceService::class)->verifier();

        $this->assertSame(0, Alerte::where('entretien_id', $e->id)->whereNull('resolue_at')->count());
        $this->assertNotNull(Alerte::where('entretien_id', $e->id)->where('type', Alerte::PROCHE)->value('resolue_at'));
    }

    public function test_changement_de_categorie_clot_les_alertes_de_l_ancienne_echeance(): void
    {
        Http::fake(['*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000])]);

        $e = Entretien::create([
            'vehicule_id'             => 1,
            'type'                    => TypeEntretien::PREVENTIF,
            'categorie'               => 'VIDANGE',
            'description'             => 'Vidange',
            'date_entretien'          => now()->addDay()->toDateString(),
            'kilometrage'             => 50000,
            'cout'                    => 850,
            'statut'                  => StatutEntretien::Planifie,
            'date_prochaine_echeance' => now()->addDays(5)->toDateString(),
            'km_prochaine_echeance'   => 60000,
        ]);

        $alerte = Alerte::forceCreate([
            'entretien_id' => $e->id, 'vehicule_id' => 1, 'type' => Alerte::PROCHE,
            'categorie' => 'VIDANGE', 'message' => 'Alerte ouverte avant recalcul',
        ]);

        $this->putJson("/api/entretiens/{$e->id}", ['categorie' => 'FREINS'], $this->tokenHeaders(AuthUser::GESTIONNAIRE))
            ->assertOk();

        $this->assertNotNull($alerte->fresh()->resolue_at);
        $this->assertSame('FREINS', $e->fresh()->categorie);
        $this->assertNotSame(
            now()->addDays(5)->toDateString(),
            $e->fresh()->date_prochaine_echeance?->toDateString()
        );
    }
}
