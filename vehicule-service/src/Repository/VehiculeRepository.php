<?php
namespace App\Repository;

use App\Database;
use App\Models\StatutVehicule;
use App\Models\Vehicule;
use PDO;

class VehiculeRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    private function hydrate(array $row): Vehicule
    {
        return new Vehicule(
            (int) $row['id'],
            $row['immatriculation'],
            $row['marque'],
            $row['modele'],
            (int) $row['annee'],
            (float) $row['kilometrage'],
            $row['type_carburant'],
            StatutVehicule::from($row['statut'])
        );
    }

    /** @return Vehicule[] */
    public function findAll(): array
    {
        $rows = $this->pdo->query('SELECT * FROM vehicules ORDER BY id')->fetchAll();
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    public function findById(int $id): ?Vehicule
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vehicules WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findByImmatriculation(string $immat): ?Vehicule
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vehicules WHERE immatriculation = :immat');
        $stmt->execute(['immat' => $immat]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function create(Vehicule $v): Vehicule
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO vehicules (immatriculation, marque, modele, annee, kilometrage, type_carburant, statut)
             VALUES (:immat, :marque, :modele, :annee, :km, :carburant, :statut)'
        );
        $stmt->execute([
            'immat'     => $v->immatriculation,
            'marque'    => $v->marque,
            'modele'    => $v->modele,
            'annee'     => $v->annee,
            'km'        => $v->kilometrage,
            'carburant' => $v->typeCarburant,
            'statut'    => $v->statut->value,
        ]);
        $v->id = (int) $this->pdo->lastInsertId();
        return $v;
    }

    public function update(Vehicule $v): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE vehicules SET immatriculation = :immat, marque = :marque, modele = :modele,
             annee = :annee, kilometrage = :km, type_carburant = :carburant WHERE id = :id'
        );
        $stmt->execute([
            'immat'     => $v->immatriculation,
            'marque'    => $v->marque,
            'modele'    => $v->modele,
            'annee'     => $v->annee,
            'km'        => $v->kilometrage,
            'carburant' => $v->typeCarburant,
            'id'        => $v->id,
        ]);
    }

    public function updateStatut(int $id, StatutVehicule $statut): void
    {
        $stmt = $this->pdo->prepare('UPDATE vehicules SET statut = :statut WHERE id = :id');
        $stmt->execute(['statut' => $statut->value, 'id' => $id]);
    }

    public function updateKilometrage(int $id, float $km): void
    {
        $stmt = $this->pdo->prepare('UPDATE vehicules SET kilometrage = :km WHERE id = :id');
        $stmt->execute(['km' => $km, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM vehicules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}