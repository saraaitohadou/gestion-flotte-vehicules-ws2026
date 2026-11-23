<?php
declare(strict_types=1);

namespace Tests;

use App\Idempotency\IdempotencyStore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test isolé du stockage d'idempotence : SQLite en mémoire, aucune base réelle
 * et aucun véhicule n'est modifié.
 */
final class IdempotencyStoreTest extends TestCase
{
    private PDO $pdo;
    private IdempotencyStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->store = new IdempotencyStore($this->pdo);
    }

    public function testUneCleInconnueNEstPasRetrouvee(): void
    {
        $this->assertNull($this->store->get('cle-absente'));
    }

    public function testUneReponseMiseEnCacheEstRejoueeExactement(): void
    {
        $this->store->put('patch-statut-1', 'PATCH /vehicules/1/statut', 200, '{"statut":"EN_MAINTENANCE"}');

        $this->assertSame(
            ['status' => 200, 'route' => 'PATCH /vehicules/1/statut', 'body' => '{"statut":"EN_MAINTENANCE"}'],
            $this->store->get('patch-statut-1')
        );
    }

    public function testUneErreurServeurNestPasMiseEnCache(): void
    {
        $this->store->put('patch-erreur', 'PATCH /vehicules/1/statut', 500, '{"erreur":"interne"}');

        $this->assertNull($this->store->get('patch-erreur'));
    }

    public function testUneNouvelleReponseEcraseLAnciennePourLaMemeCle(): void
    {
        $this->store->put('cle', 'PATCH /vehicules/1/statut', 200, '{"statut":"A"}');
        $this->store->put('cle', 'PATCH /vehicules/1/statut', 200, '{"statut":"B"}');

        $this->assertSame('{"statut":"B"}', $this->store->get('cle')['body']);
    }

    public function testLaTableEstCreeeSansDetruireLesLignesExistantes(): void
    {
        $this->store->put('cle-1', 'PATCH /vehicules/1/statut', 200, '{"statut":"A"}');
        $this->store->ensureTable();
        $this->store->put('cle-2', 'PATCH /vehicules/1/kilometrage', 200, '{"kilometrage":50000}');

        $this->assertSame('{"statut":"A"}', $this->store->get('cle-1')['body']);
        $this->assertSame('{"kilometrage":50000}', $this->store->get('cle-2')['body']);
    }
}
