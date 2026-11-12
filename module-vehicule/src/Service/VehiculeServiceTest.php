<?php
declare(strict_types=1);

namespace Tests;

use App\Exceptions\ServiceException;
use App\Models\StatutVehicule;
use App\Models\Vehicule;
use App\Repository\VehiculeRepository;
use App\Service\VehiculeService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du service métier VehiculeService.
 * Le repository est simulé (mock) : aucun accès à MySQL.
 */
class VehiculeServiceTest extends TestCase
{
    private VehiculeRepository $repo;
    private VehiculeService $service;

    protected function setUp(): void
    {
        // Arrange commun : un faux repository, sans connexion à la base
        $this->repo = $this->createMock(VehiculeRepository::class);
        $this->service = new VehiculeService($this->repo);
    }

    // ---------- Outils ----------

    private function donneesValides(array $surcharge = []): array
    {
        return array_merge([
            'immatriculation' => '12345-A-6',
            'marque'          => 'Dacia',
            'modele'          => 'Logan',
            'annee'           => 2021,
            'kilometrage'     => 45200,
            'typeCarburant'   => 'Diesel',
        ], $surcharge);
    }

    private function vehicule(
        int $id = 1,
        StatutVehicule $statut = StatutVehicule::DISPONIBLE,
        float $km = 45200
    ): Vehicule {
        return new Vehicule($id, '12345-A-6', 'Dacia', 'Logan', 2021, $km, 'Diesel', $statut);
    }

    /** Vérifie qu'une ServiceException avec le code HTTP attendu est levée. */
    private function assertErreur(int $codeHttp, callable $action): void
    {
        try {
            $action();
            $this->fail("Une ServiceException $codeHttp était attendue.");
        } catch (ServiceException $e) {
            $this->assertSame($codeHttp, $e->getStatus());
        }
    }

    // ---------- UT-VEH-01 à 05 : création ----------

    public function testCreerVehiculeValideAvecStatutDisponible(): void
    {
        $this->repo->method('findByImmatriculation')->willReturn(null);
        $this->repo->expects($this->once())->method('create')
            ->willReturnCallback(fn(Vehicule $v) => $v);

        $v = $this->service->creer($this->donneesValides());

        $this->assertSame('12345-A-6', $v->immatriculation);
        $this->assertSame(StatutVehicule::DISPONIBLE, $v->statut);
    }

    public function testCreerRefuseImmatriculationDejaUtilisee(): void
    {
        $this->repo->method('findByImmatriculation')->willReturn($this->vehicule());
        $this->repo->expects($this->never())->method('create');

        $this->assertErreur(409, fn() => $this->service->creer($this->donneesValides()));
    }

    public function testCreerRefuseChampObligatoireManquant(): void
    {
        $donnees = $this->donneesValides();
        unset($donnees['marque']);
        $this->repo->expects($this->never())->method('create');

        $this->assertErreur(422, fn() => $this->service->creer($donnees));
    }

    public function testCreerRefuseAnneeDansLeFutur(): void
    {
        $this->repo->expects($this->never())->method('create');
        $anneeFuture = (int) date('Y') + 1;

        $this->assertErreur(422, fn() => $this->service->creer($this->donneesValides(['annee' => $anneeFuture])));
    }

    public function testCreerRefuseKilometrageNegatif(): void
    {
        $this->repo->expects($this->never())->method('create');

        $this->assertErreur(422, fn() => $this->service->creer($this->donneesValides(['kilometrage' => -10])));
    }

    // ---------- UT-VEH-06 à 07 : consultation ----------

    public function testObtenirRetourneLeVehiculeExistant(): void
    {
        $this->repo->method('findById')->with(1)->willReturn($this->vehicule(1));

        $this->assertSame(1, $this->service->obtenir(1)->id);
    }

    public function testObtenirRefuseIdInexistant(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->assertErreur(404, fn() => $this->service->obtenir(99999));
    }

    // ---------- UT-VEH-08 à 10 : modification ----------

    public function testModifierMetAJourLeVehicule(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1));
        $this->repo->method('findByImmatriculation')->willReturn(null);
        $this->repo->expects($this->once())->method('update');

        $v = $this->service->modifier(1, $this->donneesValides(['marque' => 'Renault', 'kilometrage' => 50000]));

        $this->assertSame('Renault', $v->marque);
        $this->assertSame(50000.0, $v->kilometrage);
    }

    public function testModifierRefuseKilometrageInferieur(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::DISPONIBLE, 45200));
        $this->repo->method('findByImmatriculation')->willReturn(null);
        $this->repo->expects($this->never())->method('update');

        $this->assertErreur(422, fn() => $this->service->modifier(1, $this->donneesValides(['kilometrage' => 1000])));
    }

    public function testModifierRefuseImmatriculationDunAutreVehicule(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1));
        $this->repo->method('findByImmatriculation')->willReturn($this->vehicule(2));
        $this->repo->expects($this->never())->method('update');

        $this->assertErreur(409, fn() => $this->service->modifier(1, $this->donneesValides()));
    }

    // ---------- UT-VEH-11 à 12 : suppression ----------

    public function testSupprimerVehiculeDisponible(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::DISPONIBLE));
        $this->repo->expects($this->once())->method('delete')->with(1);

        $this->service->supprimer(1);
    }

    public function testSupprimerRefuseVehiculeEnMission(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::EN_MISSION));
        $this->repo->expects($this->never())->method('delete');

        $this->assertErreur(409, fn() => $this->service->supprimer(1));
    }

    // ---------- UT-VEH-13 à 15 : changement de statut ----------

    public function testChangerStatutDisponibleVersEnMission(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::DISPONIBLE));
        $this->repo->expects($this->once())->method('updateStatut')->with(1, StatutVehicule::EN_MISSION);

        $v = $this->service->changerStatut(1, 'EN_MISSION');

        $this->assertSame(StatutVehicule::EN_MISSION, $v->statut);
    }

    public function testChangerStatutRefuseEnMissionVersEnMaintenance(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::EN_MISSION));
        $this->repo->expects($this->never())->method('updateStatut');

        $this->assertErreur(409, fn() => $this->service->changerStatut(1, 'EN_MAINTENANCE'));
    }

    public function testChangerStatutRefuseStatutInconnu(): void
    {
        $this->repo->expects($this->never())->method('updateStatut');

        $this->assertErreur(422, fn() => $this->service->changerStatut(1, 'VOLE'));
    }

    // ---------- UT-VEH-16 à 17 : disponibilité ----------

    public function testEstDisponibleVraiPourVehiculeDisponible(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::DISPONIBLE));

        $this->assertTrue($this->service->estDisponible(1));
    }

    public function testEstDisponibleFauxPourVehiculeEnMission(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::EN_MISSION));

        $this->assertFalse($this->service->estDisponible(1));
    }

    // ---------- UT-VEH-18 à 20 : kilométrage ----------

    public function testMettreAJourKilometrageValide(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::DISPONIBLE, 45200));
        $this->repo->expects($this->once())->method('updateKilometrage')->with(1, 45650.0);

        $v = $this->service->mettreAJourKilometrage(1, 45650);

        $this->assertSame(45650.0, $v->kilometrage);
    }

    public function testMettreAJourKilometrageRefuseValeurInferieure(): void
    {
        $this->repo->method('findById')->willReturn($this->vehicule(1, StatutVehicule::DISPONIBLE, 45200));
        $this->repo->expects($this->never())->method('updateKilometrage');

        $this->assertErreur(422, fn() => $this->service->mettreAJourKilometrage(1, 4560));
    }

    public function testMettreAJourKilometrageRefuseValeurNonNumerique(): void
    {
        $this->repo->expects($this->never())->method('updateKilometrage');

        $this->assertErreur(422, fn() => $this->service->mettreAJourKilometrage(1, 'abc'));
    }
}
