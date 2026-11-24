<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Auth\DatabaseWriteAuth;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Ingest\AsyncMysqlBatchWriter;
use LogService\Ingest\IngestConfig;
use LogService\Ingest\StorageBatchWriter;
use LogService\Repository\ClientRepository;
use LogService\Retention\DatabasePruner;
use LogService\Retention\DatabaseRetentionEngine;
use LogService\Retention\DatabaseRetentionLogRepository;
use LogService\Retention\DatabaseRetentionPolicyRepository;
use LogService\Retention\DatabaseRetentionVolumeAnalyzer;
use LogService\Retention\FileRetentionEngine;
use LogService\Retention\FileRetentionLogRepository;
use LogService\Retention\FileRetentionPolicyRepository;
use LogService\Retention\FileRetentionVolumeAnalyzer;
use LogService\Retention\FileStoragePruner;
use LogService\Storage\ClickHouse\ClickHouseException;
use LogService\Storage\ClickHouseStorage;
use LogService\Storage\FileStorage;
use LogService\Storage\MariaDBStorage;
use LogService\Storage\StorageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which implementation each role gets. MariaDB connections are lazy, so the
 * database-backed services can be built here without a server.
 */
final class StorageFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logstream-factory-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function factory(array $env): StorageFactory
    {
        return StorageFactory::fromEnv($env + ['LOG_PATH' => $this->dir . '/logs'], $this->dir);
    }

    #[Test]
    public function file_mode_builds_file_services_only(): void
    {
        $factory = $this->factory(['API_SECRET' => 's3cret']);
        $factory->connect(); // nothing to connect to

        self::assertInstanceOf(SingleKeyWriteAuth::class, $factory->writeAuth());
        self::assertNull($factory->databaseWriteAuth());
        self::assertNull($factory->clientRepository());
        self::assertInstanceOf(FileRetentionPolicyRepository::class, $factory->policyRepository($this->dir . '/retention.json'));
        self::assertInstanceOf(FileRetentionLogRepository::class, $factory->retentionLog());

        self::assertInstanceOf(FileStorage::class, $factory->logStorage());
        self::assertInstanceOf(FileRetentionEngine::class, $factory->retentionEngine());
        self::assertInstanceOf(FileStoragePruner::class, $factory->pruner());
        self::assertInstanceOf(FileRetentionVolumeAnalyzer::class, $factory->volumeAnalyzer());
        self::assertSame("File ({$this->dir}/logs)", $factory->logStoreLabel());
    }

    #[Test]
    public function mariadb_mode_builds_database_services_for_both_roles(): void
    {
        $factory = $this->factory(['STORAGE_TYPE' => 'mariadb', 'DB_HOST' => 'db', 'DB_PORT' => '3307', 'DB_NAME' => 'logs']);

        self::assertInstanceOf(DatabaseWriteAuth::class, $factory->writeAuth());
        self::assertSame($factory->writeAuth(), $factory->databaseWriteAuth());
        self::assertInstanceOf(ClientRepository::class, $factory->clientRepository());
        self::assertInstanceOf(DatabaseRetentionPolicyRepository::class, $factory->policyRepository('/unused.json'));
        self::assertInstanceOf(DatabaseRetentionLogRepository::class, $factory->retentionLog());

        self::assertInstanceOf(MariaDBStorage::class, $factory->logStorage());
        self::assertInstanceOf(DatabaseRetentionEngine::class, $factory->retentionEngine());
        self::assertInstanceOf(DatabasePruner::class, $factory->pruner());
        self::assertInstanceOf(DatabaseRetentionVolumeAnalyzer::class, $factory->volumeAnalyzer());
        self::assertSame('MariaDB (db:3307/logs)', $factory->logStoreLabel());
    }

    #[Test]
    public function clients_in_mariadb_with_entries_in_files(): void
    {
        $factory = $this->factory(['STORAGE_TYPE' => 'mariadb', 'LOG_STORE' => 'file']);

        self::assertInstanceOf(DatabaseWriteAuth::class, $factory->writeAuth());
        self::assertInstanceOf(DatabaseRetentionPolicyRepository::class, $factory->policyRepository('/unused.json'));
        self::assertInstanceOf(DatabaseRetentionLogRepository::class, $factory->retentionLog());

        self::assertInstanceOf(FileStorage::class, $factory->logStorage());
        self::assertInstanceOf(FileRetentionEngine::class, $factory->retentionEngine());
        self::assertInstanceOf(FileStoragePruner::class, $factory->pruner());
    }

    #[Test]
    public function single_key_with_entries_in_mariadb(): void
    {
        $factory = $this->factory(['STORAGE_TYPE' => 'file', 'LOG_STORE' => 'mariadb']);

        self::assertInstanceOf(SingleKeyWriteAuth::class, $factory->writeAuth());
        self::assertNull($factory->clientRepository());
        self::assertInstanceOf(FileRetentionPolicyRepository::class, $factory->policyRepository($this->dir . '/retention.json'));
        self::assertInstanceOf(FileRetentionLogRepository::class, $factory->retentionLog());

        self::assertInstanceOf(MariaDBStorage::class, $factory->logStorage());
        self::assertInstanceOf(DatabaseRetentionEngine::class, $factory->retentionEngine());
        self::assertInstanceOf(DatabasePruner::class, $factory->pruner());
    }

    #[Test]
    public function clickhouse_log_store_with_a_mariadb_control_plane(): void
    {
        $factory = $this->factory([
            'STORAGE_TYPE' => 'mariadb', 'LOG_STORE' => 'clickhouse',
            'CLICKHOUSE_URL' => 'http://ch.internal:8123', 'CLICKHOUSE_DATABASE' => 'logs',
        ]);

        self::assertInstanceOf(DatabaseWriteAuth::class, $factory->writeAuth());
        self::assertInstanceOf(DatabaseRetentionPolicyRepository::class, $factory->policyRepository('/unused.json'));
        self::assertInstanceOf(ClickHouseStorage::class, $factory->logStorage());
        self::assertSame('ClickHouse (http://ch.internal:8123/logs)', $factory->logStoreLabel());
        self::assertSame('clickhouse-http', $factory->batchWriter(IngestConfig::fromEnv([]), $factory->logStorage())->name());
    }

    #[Test]
    public function clickhouse_has_no_retention_engine_yet(): void
    {
        $factory = $this->factory(['LOG_STORE' => 'clickhouse']);

        self::assertFalse($factory->logStoreSupportsRetention());
        self::assertTrue($this->factory([])->logStoreSupportsRetention());

        foreach (['retentionEngine', 'pruner', 'volumeAnalyzer'] as $method) {
            try {
                $factory->{$method}();
                self::fail("{$method}() should refuse ClickHouse");
            } catch (\LogicException $e) {
                self::assertStringContainsString('#158', $e->getMessage());
            }
        }
    }

    #[Test]
    public function connect_fails_fast_when_clickhouse_is_unreachable(): void
    {
        // Port 1 on loopback refuses connections immediately.
        $factory = $this->factory(['LOG_STORE' => 'clickhouse', 'CLICKHOUSE_URL' => 'http://127.0.0.1:1', 'CLICKHOUSE_TIMEOUT' => '1']);

        $this->expectException(ClickHouseException::class);
        $this->expectExceptionMessage('Cannot reach ClickHouse at http://127.0.0.1:1');

        $factory->connect();
    }

    #[Test]
    public function the_batch_writer_goes_through_the_storage_unless_mariadb_async(): void
    {
        $file    = $this->factory([]);
        $storage = $file->logStorage();
        $async   = IngestConfig::fromEnv(['DB_DRIVER' => 'async']);

        $writer = $file->batchWriter($async, $storage);

        self::assertInstanceOf(StorageBatchWriter::class, $writer);
        self::assertSame('file', $writer->name());

        $mariadb = $this->factory(['STORAGE_TYPE' => 'file', 'LOG_STORE' => 'mariadb']);
        $writer  = $mariadb->batchWriter(IngestConfig::fromEnv(['DB_DRIVER' => 'pdo']), $mariadb->logStorage());
        self::assertSame('pdo-mysql', $writer->name());

        // The async client connects lazily, on the first write.
        $writer = $mariadb->batchWriter($async, $mariadb->logStorage());
        self::assertInstanceOf(AsyncMysqlBatchWriter::class, $writer);
    }

    #[Test]
    public function the_retention_log_defaults_next_to_the_log_directory(): void
    {
        $default = $this->factory([])->retentionLog();
        $custom  = $this->factory(['RETENTION_LOG_PATH' => $this->dir . '/custom.jsonl'])->retentionLog();

        self::assertSame($this->dir . '/retention_log.jsonl', self::path($default));
        self::assertSame($this->dir . '/custom.jsonl', self::path($custom));
    }

    #[Test]
    public function log_path_defaults_under_the_project_root(): void
    {
        $factory = StorageFactory::fromEnv([], $this->dir);

        self::assertSame($this->dir . '/storage/logs', $factory->logPath());
    }

    #[Test]
    public function an_invalid_combination_fails_before_anything_is_built(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StorageFactory::fromEnv(['LOG_STORE' => 'clickhouse', 'CLICKHOUSE_URL' => 'localhost:8123'], $this->dir);
    }

    private static function path(object $repository): string
    {
        return (new \ReflectionProperty($repository, 'path'))->getValue($repository);
    }
}
