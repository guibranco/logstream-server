<?php

declare(strict_types=1);

namespace LogService\Storage;

use LogService\Auth\DatabaseWriteAuth;
use LogService\Auth\SecretGenerator;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Auth\WriteAuthInterface;
use LogService\Database\Connection;
use LogService\Ingest\AsyncMysqlBatchWriter;
use LogService\Ingest\BatchWriterInterface;
use LogService\Ingest\ClickHouseBatchWriter;
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
use LogService\Retention\PrunerInterface;
use LogService\Retention\RetentionEngineInterface;
use LogService\Retention\RetentionLogRepositoryInterface;
use LogService\Retention\RetentionPolicyRepositoryInterface;
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseException;
use LogService\Storage\ClickHouse\ClickHouseSettings;

/**
 * Builds the storage-dependent services for bin/server.php, bin/retention.php
 * and bin/prune.php from one {@see StorageConfig}.
 *
 *   Control plane (STORAGE_TYPE): write auth, clients, retention policies, retention log.
 *   Log store     (LOG_STORE):    StorageInterface, batch writer, retention engine,
 *                                 pruner, volume analyzer — everything that reads or
 *                                 deletes log entries.
 *
 * MariaDB connections are opened lazily and shared: one for the control plane
 * and one (in UTC) for the log store, both from the DB_* settings.
 */
final class StorageFactory
{
    private ?Connection $controlDb = null;
    private ?Connection $logDb     = null;
    private ?WriteAuthInterface $writeAuth = null;
    private readonly MariaDbSettings $db;
    /**
     * Set when LOG_STORE=clickhouse. Built at construction (no I/O yet), so a
     * bad CLICKHOUSE_* setting fails at boot.
     */
    private readonly ?ClickHouseClient $clickhouse;

    private const NO_RETENTION = 'Retention for LOG_STORE=clickhouse is not available yet (guibranco/logstream-server#158)';

    /**
     * @param array<string, mixed> $env         Usually $_ENV after Dotenv.
     * @param string               $projectRoot Base for the default file paths.
     */
    public function __construct(
        public readonly StorageConfig $config,
        private readonly array $env,
        private readonly string $projectRoot,
    ) {
        $this->db         = MariaDbSettings::fromEnv($env);
        $this->clickhouse = $config->logStore === StorageConfig::CLICKHOUSE
            ? new ClickHouseClient(ClickHouseSettings::fromEnv($env))
            : null;
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env, string $projectRoot): self
    {
        return new self(StorageConfig::fromEnv($env), $env, $projectRoot);
    }

    // ─── Control plane ────────────────────────────────────────────────────────

    public function controlPlaneIsDatabase(): bool
    {
        return $this->config->controlPlane === StorageConfig::MARIADB;
    }

    /** Per-client keys (MariaDB) or the single API_SECRET (file). */
    public function writeAuth(): WriteAuthInterface
    {
        return $this->writeAuth ??= $this->controlPlaneIsDatabase()
            ? new DatabaseWriteAuth($this->controlDb())
            : new SingleKeyWriteAuth((string) ($this->env['API_SECRET'] ?? ''));
    }

    /** The database-backed auth (token lookups, cache flushes); null in file mode. */
    public function databaseWriteAuth(): ?DatabaseWriteAuth
    {
        $auth = $this->writeAuth();

        return $auth instanceof DatabaseWriteAuth ? $auth : null;
    }

    public function authModeLabel(): string
    {
        return $this->controlPlaneIsDatabase()
            ? 'per-client (X-Api-Key / X-Api-Token  →  clients table)'
            : 'single key (Authorization: Bearer API_SECRET)';
    }

    /**
     * Client management; null in file mode.
     *
     * @throws \InvalidArgumentException when APP_TOKEN_PREFIX is invalid
     */
    public function clientRepository(): ?ClientRepository
    {
        if (!$this->controlPlaneIsDatabase()) {
            return null;
        }

        return new ClientRepository($this->controlDb(), SecretGenerator::forAppTokens($this->env));
    }

    /**
     * Where retention policies are kept: the retention_policies table, or the
     * JSON config file.
     */
    public function policyRepository(string $retentionConfigPath): RetentionPolicyRepositoryInterface
    {
        return $this->controlPlaneIsDatabase()
            ? new DatabaseRetentionPolicyRepository($this->controlDb())
            : new FileRetentionPolicyRepository($retentionConfigPath);
    }

    /** History of retention runs: the retention_log table, or a JSONL file next to LOG_PATH. */
    public function retentionLog(): RetentionLogRepositoryInterface
    {
        if ($this->controlPlaneIsDatabase()) {
            return new DatabaseRetentionLogRepository($this->controlDb());
        }

        $path = $this->env['RETENTION_LOG_PATH'] ?? null;

        return new FileRetentionLogRepository(
            is_string($path) && $path !== '' ? $path : dirname($this->logPath()) . '/retention_log.jsonl',
        );
    }

    // ─── Log store ────────────────────────────────────────────────────────────

    public function logStorage(): StorageInterface
    {
        return match ($this->config->logStore) {
            StorageConfig::MARIADB    => new MariaDBStorage($this->logDb()),
            StorageConfig::CLICKHOUSE => new ClickHouseStorage($this->clickhouse),
            default                   => new FileStorage($this->logPath()),
        };
    }

    /** Human-readable location of the log store, for startup messages. */
    public function logStoreLabel(): string
    {
        return match ($this->config->logStore) {
            StorageConfig::MARIADB    => "MariaDB ({$this->db->label()})",
            StorageConfig::CLICKHOUSE => "ClickHouse ({$this->clickhouse?->settings->label()})",
            default                   => "File ({$this->logPath()})",
        };
    }

    /**
     * Whether the retention engine, pruner and volume analyzer exist for this
     * log store. Not yet for ClickHouse (guibranco/logstream-server#158); its
     * 35-day TTL backstop still removes old entries meanwhile.
     */
    public function logStoreSupportsRetention(): bool
    {
        return $this->config->logStore !== StorageConfig::CLICKHOUSE;
    }

    /**
     * Writer used by the ingest buffer. MariaDB + INGEST_DB_DRIVER=async uses the
     * non-blocking ReactPHP client; everything else writes through the storage.
     */
    public function batchWriter(IngestConfig $ingest, StorageInterface $storage): BatchWriterInterface
    {
        if ($this->config->logStore === StorageConfig::MARIADB && $ingest->dbDriver === IngestConfig::DRIVER_ASYNC) {
            return AsyncMysqlBatchWriter::connect(
                host:     $this->db->host,
                port:     $this->db->port,
                database: $this->db->database,
                user:     $this->db->user,
                password: $this->db->password,
            );
        }

        if ($this->clickhouse !== null && $ingest->dbDriver === IngestConfig::DRIVER_ASYNC) {
            return new ClickHouseBatchWriter($this->clickhouse);
        }

        // DB_DRIVER=pdo: blocking writes through the storage (blocking HTTP for ClickHouse).
        return new StorageBatchWriter($storage, match ($this->config->logStore) {
            StorageConfig::MARIADB    => 'pdo-mysql',
            StorageConfig::CLICKHOUSE => 'clickhouse-http',
            default                   => 'file',
        });
    }

    public function retentionEngine(): RetentionEngineInterface
    {
        return match ($this->config->logStore) {
            StorageConfig::MARIADB    => new DatabaseRetentionEngine($this->logDb()),
            StorageConfig::CLICKHOUSE => throw new \LogicException(self::NO_RETENTION),
            default                   => new FileRetentionEngine($this->logPath()),
        };
    }

    public function pruner(): PrunerInterface
    {
        return match ($this->config->logStore) {
            StorageConfig::MARIADB    => new DatabasePruner($this->logDb()),
            StorageConfig::CLICKHOUSE => throw new \LogicException(self::NO_RETENTION),
            default                   => new FileStoragePruner($this->logPath()),
        };
    }

    /** Uncached; the server wraps it in CachedRetentionVolumeAnalyzer. */
    public function volumeAnalyzer(): RetentionVolumeAnalyzerInterface
    {
        return match ($this->config->logStore) {
            StorageConfig::MARIADB    => new DatabaseRetentionVolumeAnalyzer($this->logDb()),
            StorageConfig::CLICKHOUSE => throw new \LogicException(self::NO_RETENTION),
            default                   => new FileRetentionVolumeAnalyzer($this->logPath()),
        };
    }

    // ─── Connections ──────────────────────────────────────────────────────────

    /**
     * Open every MariaDB connection this configuration needs, so bad
     * credentials fail at boot rather than on the first request.
     */
    public function connect(): void
    {
        if ($this->controlPlaneIsDatabase()) {
            $this->controlDb()->pdo();
        }
        if ($this->config->logStore === StorageConfig::MARIADB) {
            $this->logDb()->pdo();
        }
        if ($this->clickhouse !== null && !$this->clickhouse->ping()) {
            throw new ClickHouseException("Cannot reach ClickHouse at {$this->clickhouse->settings->url}");
        }
    }

    public function logPath(): string
    {
        $path = $this->env['LOG_PATH'] ?? null;

        return is_string($path) && $path !== '' ? $path : $this->projectRoot . '/storage/logs';
    }

    /** Control-plane connection (session time zone left as configured on the server). */
    private function controlDb(): Connection
    {
        return $this->controlDb ??= Connection::mysql($this->db->dsn(), $this->db->user, $this->db->password);
    }

    /** Log-store connection: timestamps are stored and compared in UTC. */
    private function logDb(): Connection
    {
        return $this->logDb ??= Connection::mysql(
            $this->db->dsn(),
            $this->db->user,
            $this->db->password,
            ["SET time_zone = '+00:00'"],
        );
    }
}
