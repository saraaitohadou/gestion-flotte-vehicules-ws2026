<?php
namespace App\Models;

class Vehicule
{
    public function __construct(
        public ?int $id,
        public string $immatriculation,
        public string $marque,
        public string $modele,
        public int $annee,
        public float $kilometrage,
        public string $typeCarburant,
        public StatutVehicule $statut = StatutVehicule::DISPONIBLE
    ) {}
}