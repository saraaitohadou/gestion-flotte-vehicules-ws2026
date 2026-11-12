<?php
require __DIR__ . '/vendor/autoload.php';

use App\Models\StatutVehicule;
use App\Models\Vehicule;
use App\Repository\VehiculeRepository;

$repo = new VehiculeRepository();

echo "Nombre de véhicules : " . count($repo->findAll()) . "\n";

$v = $repo->findById(1);
echo "Véhicule 1 : " . $v->marque . " " . $v->modele . " (" . $v->statut->value . ")\n";

$nouveau = $repo->create(new Vehicule(null, 'TEST-1-A', 'Peugeot', '208', 2022, 1000, 'Essence'));
echo "Créé avec l'id : " . $nouveau->id . "\n";

$repo->updateKilometrage($nouveau->id, 1500);
$repo->updateStatut($nouveau->id, StatutVehicule::EN_MISSION);
$modifie = $repo->findById($nouveau->id);
echo "Après modification : " . $modifie->kilometrage . " km, " . $modifie->statut->value . "\n";

$repo->delete($nouveau->id);
echo "Supprimé : " . ($repo->findById($nouveau->id) === null ? 'oui' : 'non') . "\n";