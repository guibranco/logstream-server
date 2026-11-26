<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Retention;

use LogService\Database\Connection;
use LogService\Retention\DatabasePruner;
use LogService\Retention\DatabaseRetentionEngine;
use LogService\Retention\DatabaseRetentionVolumeAnalyzer;
use LogService\Retention\PrunerInterface;
use LogService\Retention\RetentionEngineInterface;
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Storage\MariaDBStorage;
use LogService\Storage\StorageInterface;
use LogService\Tests\Integration\Support\MariaDBTestDatabase;

/** Skipped unless TEST_DB_DSN is set; see MariaDBTestDatabase. */
final class MariaDBRetentionContractTest extends RetentionContractTestCase
{
    private ?Connection $db = null;

    protected function setUp(): void
    {
        $pdo = MariaDBTestDatabase::pdo();
        if ($pdo === null) {
            self::markTestSkipped('TEST_DB_DSN not set; skipping MariaDB integration tests.');
        }
        $pdo->exec('DELETE FROM log_entries');
        $this->db = Connection::wrap($pdo);

        parent::setUp();
    }

    protected function storage(): StorageInterface
    {
        return new MariaDBStorage($this->db);
    }

    protected function engine(): RetentionEngineInterface
    {
        return new DatabaseRetentionEngine($this->db);
    }

    protected function pruner(): PrunerInterface
    {
        return new DatabasePruner($this->db);
    }

    protected function analyzer(): RetentionVolumeAnalyzerInterface
    {
        return new DatabaseRetentionVolumeAnalyzer($this->db);
    }
}
