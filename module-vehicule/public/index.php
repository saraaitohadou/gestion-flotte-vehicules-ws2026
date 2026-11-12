<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Controller\VehiculeController;
use App\Exceptions\ServiceException;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Lecture du corps JSON de la requête
$raw = file_get_contents('php://input');
$body = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['erreur' => 'Corps JSON invalide']);
    exit;
}

try {
    $controller = new VehiculeController();

    if ($path === '/vehicules') {
        [$status, $data] = match ($method) {
            'GET'   => $controller->lister(),
            'POST'  => $controller->creer($body),
            default => [405, ['erreur' => 'Méthode non autorisée']],
        };
    } elseif (preg_match('#^/vehicules/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];
        [$status, $data] = match ($method) {
            'GET'    => $controller->obtenir($id),
            'PUT'    => $controller->modifier($id, $body),
            'DELETE' => $controller->supprimer($id),
            default  => [405, ['erreur' => 'Méthode non autorisée']],
        };
    } elseif (preg_match('#^/vehicules/(\d+)/statut$#', $path, $m)) {
        $id = (int) $m[1];
        [$status, $data] = match ($method) {
            'PATCH' => $controller->changerStatut($id, $body),
            default => [405, ['erreur' => 'Méthode non autorisée']],
        };
    } elseif (preg_match('#^/vehicules/(\d+)/disponibilite$#', $path, $m)) {
        $id = (int) $m[1];
        [$status, $data] = match ($method) {
            'GET'   => $controller->disponibilite($id),
            default => [405, ['erreur' => 'Méthode non autorisée']],
        };
    } elseif (preg_match('#^/vehicules/(\d+)/kilometrage$#', $path, $m)) {
        $id = (int) $m[1];
        [$status, $data] = match ($method) {
            'PATCH' => $controller->mettreAJourKilometrage($id, $body),
            default => [405, ['erreur' => 'Méthode non autorisée']],
        };
    } else {
        [$status, $data] = [404, ['erreur' => 'Route introuvable']];
    }
} catch (ServiceException $e) {
    [$status, $data] = [$e->getStatus(), ['erreur' => $e->getMessage()]];
} catch (Throwable $e) {
    error_log((string) $e); // le détail s'affiche dans la fenêtre du serveur
    [$status, $data] = [500, ['erreur' => 'Erreur interne du serveur']];
}

http_response_code($status);
if ($data !== null) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}