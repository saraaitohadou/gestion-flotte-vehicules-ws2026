<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Models\Entretien;
use App\Models\IntegrationOutbox;
use App\Services\SynchronisationVehiculeService;
use App\Support\AuthUser;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SynchronisationVehiculeTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
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
            'kilometrage'    => 50500,
            'cout'           => 850,
            'statut'         => StatutEntretien::Planifie,
        ], $override));
    }

    /**
     * Le PATCH est appliqué chez Véhicule mais la réponse est perdue :
     * la compensation doit remettre le véhicule DISPONIBLE et l'entretien PLANIFIE.
     */
    public function test_patch_perdu_compense_le_vehicule_et_annule_la_modification_locale(): void
    {
        Http::fake([
            '*/api/vehicules/1/statut' => Http::sequence()
                ->push(['ok' => true], 500) // PATCH EN_MAINTENANCE : échec réseau
                ->push(['ok' => true], 200), // compensation : retour DISPONIBLE
            '*/api/vehicules/1' => Http::sequence()
                ->push(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000])     // pré-vol
                ->push(['id' => 1, 'statut' => 'EN_MAINTENANCE', 'kilometrage' => 50000]), // le PATCH est passé
        ]);

        $e = $this->entretien();

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->auth())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SYNCHRONISATION_VEHICULE_ECHOUEE');

        $this->assertSame(StatutEntretien::Planifie, $e->fresh()->statut);
        $this->assertDatabaseHas('integrations_outbox', [
            'entretien_id' => $e->id,
            'statut'       => IntegrationOutbox::COMPENSE,
        ]);
        $this->assertDatabaseHas('entretien_historique', [
            'entretien_id'   => $e->id,
            'ancien_statut'  => 'EN_COURS',
            'nouveau_statut' => 'PLANIFIE',
        ]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/api/vehicules/1/statut')
            && $r['statut'] === 'EN_MAINTENANCE');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/api/vehicules/1/statut')
            && $r['statut'] === 'DISPONIBLE');
    }

    /** Règle 7 : le compteur du véhicule est mis à jour même si le client n'envoie rien. */
    public function test_cloture_sans_kilometrage_met_quand_meme_a_jour_le_compteur_vehicule(): void
    {
        Http::fake([
            '*/api/vehicules/1/statut'      => Http::response(['ok' => true]),
            '*/api/vehicules/1/kilometrage' => Http::response(['ok' => true]),
            '*/api/vehicules/1'             => Http::response(['id' => 1, 'statut' => 'EN_MAINTENANCE', 'kilometrage' => 50000]),
        ]);

        $e = $this->entretien([
            'statut'      => StatutEntretien::EnCours,
            'date_debut'  => now(),
            'kilometrage' => 50500,
        ]);

        $this->patchJson("/api/entretiens/{$e->id}/terminer", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.statut', 'TERMINE');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/kilometrage')
            && (int) $r['kilometrage'] === 50500);

        $this->assertDatabaseHas('integrations_outbox', [
            'entretien_id' => $e->id,
            'statut'       => IntegrationOutbox::APPLIQUE,
        ]);
    }

    private function outbox(array $override = []): IntegrationOutbox
    {
        $entretien = $this->entretien(['statut' => StatutEntretien::EnCours]);

        return IntegrationOutbox::create(array_merge([
            'entretien_id'          => $entretien->id,
            'vehicule_id'           => 1,
            'action'                => IntegrationOutbox::ACTION_MAJ,
            'operations'            => [['champ' => 'statut', 'valeur' => 'DISPONIBLE']],
            'operations_inverses'   => [['champ' => 'statut', 'valeur' => 'EN_MAINTENANCE']],
            'etat_vehicule_avant'   => ['statut' => 'EN_MAINTENANCE', 'kilometrage' => 50000],
            'operations_appliquees' => [],
            'entretien_avant'       => ['statut' => StatutEntretien::EnCours->value],
            'entretien_apres'       => ['statut' => StatutEntretien::Termine->value],
            'idempotency_key'       => (string) Str::uuid(),
            'tentatives'            => 0,
            'tentatives_max'        => 3,
            'statut'                => IntegrationOutbox::EN_ATTENTE,
        ], $override));
    }

    /** Une opération déjà appliquée n'est pas renvoyée ; la suivante part normalement. */
    public function test_rejou_ignore_une_operation_deja_appliquee_et_continue(): void
    {
        Http::fake([
            '*/api/vehicules/1'             => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
            '*/api/vehicules/1/statut'      => Http::response(['ok' => true], 409), // ne doit pas être appelé
            '*/api/vehicules/1/kilometrage' => Http::response(['ok' => true]),
        ]);

        $outbox = $this->outbox([
            'operations'            => [
                ['champ' => 'statut', 'valeur' => 'DISPONIBLE'],
                ['champ' => 'kilometrage', 'valeur' => 50500],
            ],
            'operations_appliquees' => ['statut' => true],
        ]);

        $stats = app(SynchronisationVehiculeService::class)->rejouerEnAttente();

        $this->assertSame(1, $stats['appliquees']);
        $this->assertSame(IntegrationOutbox::APPLIQUE, $outbox->fresh()->statut);
        $this->assertSame(['statut' => true, 'kilometrage' => true], $outbox->fresh()->operations_appliquees);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/statut'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/kilometrage')
            && (int) $r['kilometrage'] === 50500);
    }

    /** Un 409 « même état » est résolu en relisant l'état réel, puis le rejou continue. */
    public function test_rejou_409_sur_operation_deja_faite_ne_bloque_pas_la_suivante(): void
    {
        Http::fake([
            '*/api/vehicules/1'             => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
            '*/api/vehicules/1/statut'      => Http::response(['erreur' => 'Transition impossible'], 409),
            '*/api/vehicules/1/kilometrage' => Http::response(['ok' => true]),
        ]);

        $outbox = $this->outbox([
            'operations' => [
                ['champ' => 'statut', 'valeur' => 'DISPONIBLE'],
                ['champ' => 'kilometrage', 'valeur' => 50500],
            ],
        ]);

        app(SynchronisationVehiculeService::class)->rejouerEnAttente();

        $this->assertSame(IntegrationOutbox::APPLIQUE, $outbox->fresh()->statut);
        $this->assertSame(['statut' => true, 'kilometrage' => true], $outbox->fresh()->operations_appliquees);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/kilometrage'));
    }

    /** Nouvelle tentative : l'état courant est lu et AUCUN PATCH redondant n'est envoyé. */
    public function test_nouvelle_tentative_ne_renvoie_aucune_operation_deja_visible(): void
    {
        Http::fake([
            '*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50500]),
        ]);

        $outbox = $this->outbox([
            'tentatives' => 1,
            'operations' => [
                ['champ' => 'statut', 'valeur' => 'DISPONIBLE'],
                ['champ' => 'kilometrage', 'valeur' => 50500],
            ],
        ]);

        $stats = app(SynchronisationVehiculeService::class)->rejouerEnAttente();

        $this->assertSame(1, $stats['appliquees']);
        $this->assertSame(IntegrationOutbox::APPLIQUE, $outbox->fresh()->statut);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    /** La limite de tentatives conduit à un dead-letter explicite, pas à une boucle infinie. */
    public function test_limite_de_tentatives_passe_la_ligne_en_echec_definitif(): void
    {
        Http::fake(['*/api/vehicules/1/statut' => Http::response(['ok' => false], 500)]);

        $outbox = $this->outbox([
            'tentatives'     => 2,
            'tentatives_max' => 3,
            'operations'     => [['champ' => 'statut', 'valeur' => 'EN_MAINTENANCE']],
        ]);

        $service = app(SynchronisationVehiculeService::class);
        $stats   = $service->rejouerEnAttente();

        $this->assertSame(1, $stats['echecs_definitifs']);
        $this->assertSame(IntegrationOutbox::ECHEC_DEFINITIF, $outbox->fresh()->statut);
        $this->assertSame(3, (int) $outbox->fresh()->tentatives);

        // Une seconde exécution ne reprend pas la ligne en dead-letter.
        $this->assertSame(0, $service->rejouerEnAttente()['appliquees']);
    }

    /** Compare-and-swap : une transition récente n'est jamais écrasée par la compensation. */
    public function test_compensation_refusee_si_etat_local_a_evolue(): void
    {
        Http::fake(['*/api/vehicules/1' => Http::response(['id' => 1, 'statut' => 'EN_MAINTENANCE'])]);

        $outbox = $this->outbox([
            'entretien_avant' => ['statut' => StatutEntretien::Planifie->value],
            'entretien_apres' => ['statut' => StatutEntretien::EnCours->value],
        ]);

        // Une transition concurrente a écrit un état plus récent.
        Entretien::whereKey($outbox->entretien_id)->update(['statut' => StatutEntretien::Termine->value]);

        $compensee = app(SynchronisationVehiculeService::class)->compenser(
            $outbox,
            new AuthUser('gestionnaire-1', AuthUser::GESTIONNAIRE)
        );

        $this->assertFalse($compensee);
        $this->assertSame(StatutEntretien::Termine, Entretien::findOrFail($outbox->entretien_id)->statut);
        $this->assertSame(IntegrationOutbox::COMPENSATION_IGNOREE, $outbox->fresh()->statut);
        Http::assertNothingSent();
    }

    /** Service injoignable : aucune écriture locale, aucune ligne d'outbox. */
    public function test_service_indisponible_ne_cree_aucune_ligne_outbox(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $e = $this->entretien();

        $this->patchJson("/api/entretiens/{$e->id}/demarrer", [], $this->auth())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'VEHICULE_SERVICE_INDISPONIBLE');

        $this->assertSame(StatutEntretien::Planifie, $e->fresh()->statut);
        $this->assertDatabaseCount('integrations_outbox', 0);
    }
}
