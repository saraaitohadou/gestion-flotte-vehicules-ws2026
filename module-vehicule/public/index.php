<?php
ini_set('display_errors', '0');

require __DIR__ . '/../vendor/autoload.php';

use App\Controller\VehiculeController;
use App\Exceptions\ServiceException;
use App\Auth\JwtAuth;
use App\Idempotency\IdempotencyStore;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$path = preg_replace('#^/api(?=/|$)#', '', $path);

// Lecture du corps JSON de la requête
$raw = file_get_contents('php://input');
$body = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['erreur' => 'Corps JSON invalide']);
    exit;
}

// Idempotence des mutations : une clé déjà traitée rejoue sa réponse exacte.
$cleIdempotence = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
$stockIdempotence = null;

try {
    if ($method !== 'OPTIONS') {
        JwtAuth::exiger();
    }

    if ($cleIdempotence !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        $stockIdempotence = new IdempotencyStore();
    }

    if ($stockIdempotence !== null) {
        $precedent = $stockIdempotence->get($cleIdempotence);
        $routeCourante = $method . ' ' . $path;
        if ($precedent !== null) {
            if ($precedent['route'] !== $routeCourante) {
                http_response_code(409);
                echo json_encode(['erreur' => 'Idempotency-Key déjà utilisée pour une autre opération']);
                exit;
            }
            http_response_code($precedent['status']);
            echo $precedent['body'];
            exit;
        }
    }

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

$corps = $data !== null
    ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    : '';

if ($stockIdempotence !== null) {
    $stockIdempotence->put($cleIdempotence, $method . ' ' . $path, $status, (string) $corps);
}

http_response_code($status);
echo $corps;