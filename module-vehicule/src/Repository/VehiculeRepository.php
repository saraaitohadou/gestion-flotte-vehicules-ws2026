<?php
namespace App\Repository;

use App\Database;
use App\Exceptions\ServiceException;
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

    /**
     * Mise à jour conditionnelle (compare-and-swap) : une transition concurrente
     * déjà appliquée ne peut pas être écrasée par un rejou de la saga.
     */
    public function updateStatut(int $id, StatutVehicule $statut): void
    {
        $lecture = $this->pdo->prepare('SELECT statut FROM vehicules WHERE id = :id FOR UPDATE');
        $lecture->execute(['id' => $id]);
        $actuel = $lecture->fetchColumn();
        if ($actuel === false) {
            throw ServiceException::notFound();
        }
        if ($actuel === $statut->value) {
            return; // déjà appliqué par une requête concurrente
        }

        $stmt = $this->pdo->prepare(
            'UPDATE vehicules SET statut = :statut WHERE id = :id AND statut = :actuel'
        );
        $stmt->execute(['statut' => $statut->value, 'id' => $id, 'actuel' => $actuel]);
        if ($stmt->rowCount() === 0) {
            throw ServiceException::conflict('Transition impossible : le statut a changé entre-temps');
        }
    }

    public function updateKilometrage(int $id, float $km): void
    {
        $lecture = $this->pdo->prepare('SELECT kilometrage FROM vehicules WHERE id = :id FOR UPDATE');
        $lecture->execute(['id' => $id]);
        $actuel = $lecture->fetchColumn();
        if ($actuel === false) {
            throw ServiceException::notFound();
        }
        if ((float) $actuel === $km) {
            return; // déjà appliqué par une requête concurrente
        }
        if ($km < (float) $actuel) {
            throw ServiceException::invalid('Le kilométrage ne peut pas diminuer');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE vehicules SET kilometrage = :km WHERE id = :id AND kilometrage = :actuel'
        );
        $stmt->execute(['km' => $km, 'id' => $id, 'actuel' => $actuel]);
        if ($stmt->rowCount() === 0) {
            throw ServiceException::conflict('Le kilométrage a changé entre-temps');
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM vehicules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}