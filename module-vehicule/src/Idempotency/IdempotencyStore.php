<?php
declare(strict_types=1);

namespace App\Idempotency;

use App\Database;
use PDO;

/**
 * Idempotence persistante des appels inter-services (en-tête Idempotency-Key).
 *
 * La table est créée avec IF NOT EXISTS dans la base commune gestion_flotte :
 * aucune table ni donnée existante n'est modifiée. Une clé déjà vue renvoie
 * exactement la même réponse, sans réexécuter la mutation.
 */
class IdempotencyStore
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
    }

    public function ensureTable(): void
    {
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $suffix = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS integrations_idempotence (
                cle VARCHAR(128) NOT NULL PRIMARY KEY,
                route VARCHAR(255) NOT NULL,
                statut INT NOT NULL,
                reponse TEXT NOT NULL,
                cree_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )' . $suffix
        );
    }

    /** @return array{status:int, route:string, body:string}|null */
    public function get(string $cle): ?array
    {
        if ($cle === '') {
            return null;
        }
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT statut, route, reponse FROM integrations_idempotence WHERE cle = :cle'
        );
        $stmt->execute(['cle' => $cle]);
        $ligne = $stmt->fetch();

        if ($ligne === false) {
            return null;
        }

        return [
            'status' => (int) $ligne['statut'],
            'route'  => (string) $ligne['route'],
            'body'   => (string) $ligne['reponse'],
        ];
    }

    public function put(string $cle, string $route, int $statut, string $reponse): void
    {
        if ($cle === '' || $statut >= 500) {
            // Une erreur serveur reste rejouable : on ne la mémorise pas.
            return;
        }
        $this->ensureTable();

        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $sql = $sqlite
            ? 'INSERT OR REPLACE INTO integrations_idempotence (cle, route, statut, reponse) VALUES (:cle, :route, :statut, :reponse)'
            : 'INSERT INTO integrations_idempotence (cle, route, statut, reponse)
               VALUES (:cle, :route, :statut, :reponse)
               ON DUPLICATE KEY UPDATE route = VALUES(route), statut = VALUES(statut), reponse = VALUES(reponse)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'cle'     => mb_substr($cle, 0, 128),
            'route'   => mb_substr($route, 0, 255),
            'statut'  => $statut,
            'reponse' => $reponse,
        ]);
    }
}
