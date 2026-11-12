<?php
namespace App\Controller;

use App\Models\Vehicule;
use App\Service\VehiculeService;

class VehiculeController
{
    private VehiculeService $service;

    public function __construct(?VehiculeService $service = null)
    {
        $this->service = $service ?? new VehiculeService();
    }

    /** @return array [code HTTP, données] */
    public function lister(): array
    {
        return [200, array_map([$this, 'versTableau'], $this->service->lister())];
    }

    public function obtenir(int $id): array
    {
        return [200, $this->versTableau($this->service->obtenir($id))];
    }

    public function creer(array $body): array
    {
        return [201, $this->versTableau($this->service->creer($body))];
    }

    public function modifier(int $id, array $body): array
    {
        return [200, $this->versTableau($this->service->modifier($id, $body))];
    }

    public function supprimer(int $id): array
    {
        $this->service->supprimer($id);
        return [204, null];
    }

    public function changerStatut(int $id, array $body): array
    {
        $statut = (string) ($body['statut'] ?? '');
        return [200, $this->versTableau($this->service->changerStatut($id, $statut))];
    }

    public function disponibilite(int $id): array
    {
        return [200, ['id' => $id, 'disponible' => $this->service->estDisponible($id)]];
    }

    public function mettreAJourKilometrage(int $id, array $body): array
    {
        $km = $body['kilometrage'] ?? null;
        return [200, $this->versTableau($this->service->mettreAJourKilometrage($id, $km))];
    }

    private function versTableau(Vehicule $v): array
    {
        return [
            'id'              => $v->id,
            'immatriculation' => $v->immatriculation,
            'marque'          => $v->marque,
            'modele'          => $v->modele,
            'annee'           => $v->annee,
            'kilometrage'     => $v->kilometrage,
            'typeCarburant'   => $v->typeCarburant,
            'statut'          => $v->statut->value,
        ];
    }
}