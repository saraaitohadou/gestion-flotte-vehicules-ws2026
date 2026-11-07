<?php

namespace Tests\Unit;

use App\Enums\StatutEntretien;
use App\Services\EntretienService;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EntretienServiceTest extends TestCase
{
    private EntretienService $service;

    protected function setUp(): void
    {
        $this->service = new EntretienService();
    }

    // ---------- calculerCoutTotal ----------

    public function test_cout_total_cas_normal(): void
    {
        $this->assertSame(350.5, $this->service->calculerCoutTotal([100, 250.5]));
    }

    public function test_cout_total_liste_vide_cas_limite(): void
    {
        $this->assertSame(0.0, $this->service->calculerCoutTotal([]));
    }

    public function test_cout_negatif_cas_erreur(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->calculerCoutTotal([100, -5]);
    }

    // ---------- estEnRetard ----------

    public function test_en_retard_date_passee_cas_normal(): void
    {
        $aujourdhui = new DateTimeImmutable('2026-11-07');

        $this->assertTrue($this->service->estEnRetard(new DateTimeImmutable('2026-11-01'), $aujourdhui));
    }

    public function test_pas_en_retard_le_jour_meme_cas_limite(): void
    {
        $aujourdhui = new DateTimeImmutable('2026-11-07');

        $this->assertFalse($this->service->estEnRetard(new DateTimeImmutable('2026-11-07 23:59'), $aujourdhui));
    }

    public function test_pas_en_retard_date_future(): void
    {
        $aujourdhui = new DateTimeImmutable('2026-11-07');

        $this->assertFalse($this->service->estEnRetard(new DateTimeImmutable('2026-12-01'), $aujourdhui));
    }

    // ---------- peutChangerStatut ----------

    public function test_transitions_valides_cas_normal(): void
    {
        $this->assertTrue($this->service->peutChangerStatut(StatutEntretien::Planifie, StatutEntretien::EnCours));
        $this->assertTrue($this->service->peutChangerStatut(StatutEntretien::EnCours, StatutEntretien::Termine));
    }

    public function test_aucun_retour_en_arriere_cas_limite(): void
    {
        $this->assertFalse($this->service->peutChangerStatut(StatutEntretien::Termine, StatutEntretien::Planifie));
        $this->assertFalse($this->service->peutChangerStatut(StatutEntretien::Planifie, StatutEntretien::Termine));
    }

    // ---------- changerStatut ----------

    public function test_changer_statut_valide(): void
    {
        $this->assertSame(
            StatutEntretien::EnCours,
            $this->service->changerStatut(StatutEntretien::Planifie, StatutEntretien::EnCours)
        );
    }

    public function test_changer_statut_interdit_cas_erreur(): void
    {
        $this->expectException(DomainException::class);
        $this->service->changerStatut(StatutEntretien::Termine, StatutEntretien::Planifie);
    }
}
