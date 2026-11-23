<?php

namespace Tests\Feature;

use App\Enums\StatutEntretien;
use App\Enums\TypeEntretien;
use App\Exceptions\BusinessException;
use App\Models\Entretien;
use App\Models\IntegrationOutbox;
use App\Services\SynchronisationVehiculeService;
use App\Support\AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Intégration isolée de la saga : aucune requête réelle, aucune donnée réelle
 * n'est modifiée (Http::fake + base SQLite en mémoire via phpunit.xml).
 */
class IntegrationSagaOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function entretien(array $override = []): Entretien
    {
        return Entretien::create(array_merge([
            'vehicule_id'    => 1,
            'type'           => TypeEntretien::PREVENTIF,
            'categorie'      => 'VIDANGE',
            'description'    => 'Vidange saga',
            'date_entretien' => now()->addDay()->toDateString(),
            'kilometrage'    => 50500,
            'cout'           => 850,
            'statut'         => StatutEntretien::Planifie,
        ], $override));
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

    private function cle(Request $requete): ?string
    {
        return $requete->headers()['Idempotency-Key'][0] ?? null;
    }

    /** La transition locale et l'outbox sont atomiques, puis le PATCH part après commit. */
    public function test_transition_et_outbox_sont_atomiques_puis_patch_apres_commit(): void
    {
        Http::fake([
            '*/api/vehicules/1'        => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
            '*/api/vehicules/1/statut' => Http::response(['ok' => true]),
        ]);

        $e = $this->entretien();
        $this->patchJson(
            "/api/entretiens/{$e->id}/demarrer",
            [],
            $this->tokenHeaders(AuthUser::GESTIONNAIRE)
        )->assertOk();

        $this->assertSame(StatutEntretien::EnCours, $e->fresh()->statut);
        $this->assertDatabaseHas('integrations_outbox', [
            'entretien_id' => $e->id,
            'statut'       => IntegrationOutbox::APPLIQUE,
        ]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/api/vehicules/1/statut')
            && $r['statut'] === 'EN_MAINTENANCE');
    }

    /** Chaque opération porte une clé stable et distincte (sécurité anti-double-écriture). */
    public function test_cles_idempotence_distinctes_par_operation(): void
    {
        Http::fake([
            '*/api/vehicules/1'             => Http::response(['id' => 1, 'statut' => 'EN_MAINTENANCE', 'kilometrage' => 50000]),
            '*/api/vehicules/1/statut'      => Http::response(['ok' => true]),
            '*/api/vehicules/1/kilometrage' => Http::response(['ok' => true]),
        ]);

        $outbox = $this->outbox([
            'tentatives' => 1,
            'operations' => [
                ['champ' => 'statut', 'valeur' => 'DISPONIBLE'],
                ['champ' => 'kilometrage', 'valeur' => 50500],
            ],
        ]);

        app(SynchronisationVehiculeService::class)->rejouerEnAttente();

        $cles = [];
        foreach (Http::recorded() as [$requete, $_]) {
            if ($requete->method() === 'PATCH') {
                $cles[] = $this->cle($requete);
            }
        }

        $this->assertCount(2, $cles);
        $this->assertNotNull($cles[0]);
        $this->assertNotSame($cles[0], $cles[1]);
        $this->assertSame(IntegrationOutbox::APPLIQUE, $outbox->fresh()->statut);
    }

    /** Le dead-letter est terminal et n'est jamais rejoué. */
    public function test_dead_letter_reste_terminal(): void
    {
        Http::fake(['*/api/vehicules/1/statut' => Http::response(['ok' => false], 503)]);
        $outbox = $this->outbox(['tentatives' => 2, 'tentatives_max' => 3]);
        $service = app(SynchronisationVehiculeService::class);

        try {
            $service->appliquer($outbox->fresh());
            $this->fail('Une BusinessException 503 était attendue.');
        } catch (BusinessException $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('VEHICULE_SERVICE_INDISPONIBLE', $e->errorCode);
        }

        $this->assertSame(IntegrationOutbox::ECHEC_DEFINITIF, $outbox->fresh()->statut);
        $this->assertSame(0, $service->rejouerEnAttente()['appliquees']);

        // Un appel direct sur une ligne déjà en dead-letter refuse toute tentative.
        try {
            $service->appliquer($outbox->fresh());
            $this->fail('Une BusinessException 503 était attendue.');
        } catch (BusinessException $e) {
            $this->assertSame('SYNCHRONISATION_TENTATIVES_EPUISEES', $e->errorCode);
        }
    }

    /** La compensation ne restaure que les champs réellement appliqués côté Véhicule. */
    public function test_compensation_restaure_uniquement_ce_qui_a_ete_applique(): void
    {
        Http::fake([
            '*/api/vehicules/1'        => Http::response(['id' => 1, 'statut' => 'DISPONIBLE', 'kilometrage' => 50000]),
            '*/api/vehicules/1/statut' => Http::response(['ok' => true]),
        ]);

        $outbox = $this->outbox([
            'entretien_apres'     => ['statut' => StatutEntretien::EnCours->value],
            'operations'          => [
                ['champ' => 'statut', 'valeur' => 'DISPONIBLE'],
                ['champ' => 'kilometrage', 'valeur' => 50500],
            ],
            'operations_inverses' => [
                ['champ' => 'statut', 'valeur' => 'EN_MAINTENANCE'],
                ['champ' => 'kilometrage', 'valeur' => 50000],
            ],
        ]);

        $ok = app(SynchronisationVehiculeService::class)->compenser(
            $outbox,
            new AuthUser('gestionnaire-1', AuthUser::GESTIONNAIRE)
        );

        $this->assertTrue($ok);
        $this->assertSame(IntegrationOutbox::COMPENSE, $outbox->fresh()->statut);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/api/vehicules/1/statut')
            && $r['statut'] === 'EN_MAINTENANCE');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/vehicules/1/kilometrage')
            && $r->method() === 'PATCH');
    }
}
