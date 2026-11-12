<?php
require __DIR__ . '/vendor/autoload.php';

use App\Exceptions\ServiceException;
use App\Service\VehiculeService;

function essayer(string $titre, callable $action): void
{
    try {
        $action();
        echo "OK      - $titre\n";
    } catch (ServiceException $e) {
        echo "REFUSE ({$e->getStatus()}) - $titre : {$e->getMessage()}\n";
    }
}

$service = new VehiculeService();
$id = null;

essayer('Creer un vehicule valide', function () use ($service, &$id) {
    $v = $service->creer([
        'immatriculation' => 'TEST-9-Z', 'marque' => 'Peugeot', 'modele' => '208',
        'annee' => 2022, 'kilometrage' => 1000, 'typeCarburant' => 'Essence',
    ]);
    $id = $v->id;
});

essayer('Creer avec une immatriculation existante (attendu 409)', fn() => $service->creer([
    'immatriculation' => 'TEST-9-Z', 'marque' => 'Renault', 'modele' => 'Clio',
    'annee' => 2020, 'typeCarburant' => 'Diesel',
]));

essayer('Creer sans marque (attendu 422)', fn() => $service->creer([
    'immatriculation' => 'AAA-1-B', 'modele' => 'Clio',
    'annee' => 2020, 'typeCarburant' => 'Diesel',
]));

essayer('Diminuer le kilometrage (attendu 422)', fn() => $service->mettreAJourKilometrage($id, 500));
essayer('Augmenter le kilometrage a 1800', fn() => $service->mettreAJourKilometrage($id, 1800));
essayer('DISPONIBLE vers EN_MISSION', fn() => $service->changerStatut($id, 'EN_MISSION'));
essayer('EN_MISSION vers EN_MAINTENANCE (attendu 409)', fn() => $service->changerStatut($id, 'EN_MAINTENANCE'));
essayer('Supprimer un vehicule en mission (attendu 409)', fn() => $service->supprimer($id));
essayer('EN_MISSION vers DISPONIBLE', fn() => $service->changerStatut($id, 'DISPONIBLE'));

echo 'Disponible ? ' . ($service->estDisponible($id) ? 'oui' : 'non') . "\n";

essayer('Supprimer le vehicule de test', fn() => $service->supprimer($id));
essayer('Consulter un id inexistant (attendu 404)', fn() => $service->obtenir(99999));