<?php
namespace App\Service;

use App\Exceptions\ServiceException;
use App\Models\StatutVehicule;
use App\Models\Vehicule;
use App\Repository\VehiculeRepository;

class VehiculeService
{
    /** Transitions de statut autorisées (RG-V8 à RG-V11) */
    private const TRANSITIONS = [
        'DISPONIBLE'     => ['EN_MISSION', 'EN_MAINTENANCE', 'HORS_SERVICE'],
        'EN_MISSION'     => ['DISPONIBLE'],
        'EN_MAINTENANCE' => ['DISPONIBLE', 'HORS_SERVICE'],
        'HORS_SERVICE'   => ['DISPONIBLE'],
    ];

    private VehiculeRepository $repo;

    public function __construct(?VehiculeRepository $repo = null)
    {
        $this->repo = $repo ?? new VehiculeRepository();
    }

    /** UC-V2 : lister */
    public function lister(): array
    {
        return $this->repo->findAll();
    }

    /** UC-V2 : consulter par id */
    public function obtenir(int $id): Vehicule
    {
        $vehicule = $this->repo->findById($id);
        if ($vehicule === null) {
            throw ServiceException::notFound();
        }
        return $vehicule;
    }

    /** UC-V1 : ajouter (RG-V1, V2, V3, V4) */
    public function creer(array $data): Vehicule
    {
        $data['kilometrage'] = $data['kilometrage'] ?? 0;
        $this->valider($data);

        if ($this->repo->findByImmatriculation(trim($data['immatriculation'])) !== null) {
            throw ServiceException::conflict('Cette immatriculation existe déjà');
        }

        $vehicule = new Vehicule(
            null,
            trim($data['immatriculation']),
            trim($data['marque']),
            trim($data['modele']),
            (int) $data['annee'],
            (float) $data['kilometrage'],
            trim($data['typeCarburant']),
            StatutVehicule::DISPONIBLE // RG-V3
        );

        return $this->repo->create($vehicule);
    }

    /** UC-V3 : modifier (RG-V1, V2, V4, V5) */
    public function modifier(int $id, array $data): Vehicule
    {
        $vehicule = $this->obtenir($id);

        $data['kilometrage'] = $data['kilometrage'] ?? $vehicule->kilometrage;
        $this->valider($data);

        $autre = $this->repo->findByImmatriculation(trim($data['immatriculation']));
        if ($autre !== null && $autre->id !== $id) {
            throw ServiceException::conflict('Cette immatriculation existe déjà');
        }

        if ((float) $data['kilometrage'] < $vehicule->kilometrage) {
            throw ServiceException::invalid('Le kilométrage ne peut pas diminuer');
        }

        $vehicule->immatriculation = trim($data['immatriculation']);
        $vehicule->marque = trim($data['marque']);
        $vehicule->modele = trim($data['modele']);
        $vehicule->annee = (int) $data['annee'];
        $vehicule->kilometrage = (float) $data['kilometrage'];
        $vehicule->typeCarburant = trim($data['typeCarburant']);

        $this->repo->update($vehicule);
        return $vehicule;
    }

    /** UC-V4 : supprimer (RG-V10) */
    public function supprimer(int $id): void
    {
        $vehicule = $this->obtenir($id);

        if ($vehicule->statut === StatutVehicule::EN_MISSION) {
            throw ServiceException::conflict('Un véhicule en mission ne peut pas être supprimé');
        }

        $this->repo->delete($id);
    }

    /** UC-V5 : changer le statut (RG-V6, V8 à V11) */
    public function changerStatut(int $id, string $nouveauStatut): Vehicule
    {
        $nouveau = StatutVehicule::tryFrom($nouveauStatut);
        if ($nouveau === null) {
            throw ServiceException::invalid('Statut inconnu : ' . $nouveauStatut);
        }

        $vehicule = $this->obtenir($id);
        $actuel = $vehicule->statut->value;

        if ($actuel === $nouveau->value) {
            return $vehicule;
        }

        if (!in_array($nouveau->value, self::TRANSITIONS[$actuel], true)) {
            throw ServiceException::conflict("Transition impossible : $actuel vers {$nouveau->value}");
        }

        // Synchroniser avec chauffeur-service si l'appel ne vient pas déjà de chauffeur-service
        $source = strtolower((string) ($_SERVER['HTTP_X_SOURCE_SERVICE'] ?? ''));
        if ($source !== 'chauffeur') {
            $this->synchroniserChauffeur($id, $nouveau->value);
        }

        $this->repo->updateStatut($id, $nouveau);
        $vehicule->statut = $nouveau;
        return $vehicule;
    }

    private function synchroniserChauffeur(int $vehiculeId, string $statutVehicule): void
    {
        $baseUrl = getenv('CHAUFFEUR_SERVICE_URL');
        if ($baseUrl === false || trim($baseUrl) === '') {
            // En tests unitaires (PHPUnit) où CHAUFFEUR_SERVICE_URL n'est pas défini, on n'appelle pas le réseau
            return;
        }

        $url = rtrim($baseUrl, '/') . '/api/chauffeurs/sync-vehicule/' . $vehiculeId;
        $headers = ["Content-Type: application/json\r\n"];

        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($auth !== '') {
            $headers[] = "Authorization: $auth\r\n";
        }
        $apiKey = getenv('SERVICE_API_KEY');
        if ($apiKey !== false && $apiKey !== '') {
            $headers[] = "X-API-Key: $apiKey\r\n";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode('', $headers),
                'content'       => json_encode(['statut' => $statutVehicule]),
                'timeout'       => 4,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $statusCode = (int) $m[1];
        }

        if ($statusCode === 0) {
            throw ServiceException::conflict('Service Chauffeur injoignable pour synchroniser la mission.');
        }

        if ($statusCode === 409 || $statusCode === 422) {
            $json = is_string($raw) ? json_decode($raw, true) : null;
            $msg = $json['detail']['message'] ?? $json['detail'] ?? 'Synchronisation refusée par le service Chauffeur.';
            throw ServiceException::conflict((string) $msg);
        }

        if ($statusCode >= 400) {
            throw ServiceException::conflict("Erreur de synchronisation avec le service Chauffeur (HTTP $statusCode).");
        }
    }
    /** UC-V6 : vérifier la disponibilité (RG-V7) */
    public function estDisponible(int $id): bool
    {
        return $this->obtenir($id)->statut === StatutVehicule::DISPONIBLE;
    }

    /** UC-V7 : mettre à jour le kilométrage (RG-V4, V5) */
    public function mettreAJourKilometrage(int $id, mixed $km): Vehicule
    {
        if (!is_numeric($km) || (float) $km < 0) {
            throw ServiceException::invalid('Le kilométrage doit être un nombre positif ou nul');
        }

        $vehicule = $this->obtenir($id);

        if ((float) $km < $vehicule->kilometrage) {
            throw ServiceException::invalid('Le kilométrage ne peut pas diminuer');
        }

        // Rejouer le même compteur est sans effet : on répond 200 sans écriture.
        if ((float) $km === $vehicule->kilometrage) {
            return $vehicule;
        }

        $this->repo->updateKilometrage($id, (float) $km);
        $vehicule->kilometrage = (float) $km;
        return $vehicule;
    }

    /** RG-V2 et RG-V4 : validation des champs */
    private function valider(array $data): void
    {
        foreach (['immatriculation', 'marque', 'modele', 'annee', 'typeCarburant'] as $champ) {
            if (!isset($data[$champ]) || trim((string) $data[$champ]) === '') {
                throw ServiceException::invalid("Le champ $champ est obligatoire");
            }
        }

        $anneeMax = (int) date('Y');
        if (!is_numeric($data['annee']) || (int) $data['annee'] < 1950 || (int) $data['annee'] > $anneeMax) {
            throw ServiceException::invalid("L'année doit être comprise entre 1950 et $anneeMax");
        }

        if (!is_numeric($data['kilometrage']) || (float) $data['kilometrage'] < 0) {
            throw ServiceException::invalid('Le kilométrage doit être un nombre positif ou nul');
        }
    }
}