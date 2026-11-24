<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Storage;

use LogService\Database\Connection;
use LogService\Storage\MariaDBStorage;
use LogService\Storage\StorageInterface;
use LogService\Tests\Integration\Support\MariaDBTestDatabase;

/** Skipped unless TEST_DB_DSN is set; see MariaDBTestDatabase. */
final class MariaDBStorageContractTest extends StorageContractTestCase
{
    private ?MariaDBStorage $storage = null;

    protected function setUp(): void
    {
        $pdo = MariaDBTestDatabase::pdo();
        if ($pdo === null) {
            self::markTestSkipped('TEST_DB_DSN not set; skipping MariaDB integration tests.');
        }
        $pdo->exec('DELETE FROM log_entries');

        parent::setUp();
    }

    protected function storage(): StorageInterface
    {
        return $this->storage ??= new MariaDBStorage(Connection::wrap(MariaDBTestDatabase::pdo()));
    }
}
