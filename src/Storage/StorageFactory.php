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

    /**
     * @param array<string, mixed> $env         Usually $_ENV after Dotenv.
     * @param string               $projectRoot Base for the default file paths.
     */
    public function __construct(
        public readonly StorageConfig $config,
        private readonly array $env,
        private readonly string $projectRoot,
    ) {
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

    public function logStoreIsDatabase(): bool
    {
        return $this->config->logStore === StorageConfig::MARIADB;
    }

    public function logStorage(): StorageInterface
    {
        return $this->logStoreIsDatabase()
            ? new MariaDBStorage($this->logDb())
            : new FileStorage($this->logPath());
    }

    /** Human-readable location of the log store, for startup messages. */
    public function logStoreLabel(): string
    {
        if (!$this->logStoreIsDatabase()) {
            return "File ({$this->logPath()})";
        }

        return sprintf(
            'MariaDB (%s:%s/%s)',
            $this->env['DB_HOST'] ?? '127.0.0.1',
            $this->env['DB_PORT'] ?? '3306',
            $this->env['DB_NAME'] ?? 'logservice',
        );
    }

    /**
     * Writer used by the ingest buffer. MariaDB + INGEST_DB_DRIVER=async uses the
     * non-blocking ReactPHP client; everything else writes through the storage.
     */
    public function batchWriter(IngestConfig $ingest, StorageInterface $storage): BatchWriterInterface
    {
        if ($this->logStoreIsDatabase() && $ingest->dbDriver === IngestConfig::DRIVER_ASYNC) {
            return AsyncMysqlBatchWriter::connect(
                host:     (string) ($this->env['DB_HOST'] ?? '127.0.0.1'),
                port:     (int) ($this->env['DB_PORT'] ?? 3306),
                database: (string) ($this->env['DB_NAME'] ?? 'logservice'),
                user:     (string) ($this->env['DB_USER'] ?? 'root'),
                password: (string) ($this->env['DB_PASS'] ?? ''),
            );
        }

        return new StorageBatchWriter($storage, $this->logStoreIsDatabase() ? 'pdo-mysql' : 'file');
    }

    public function retentionEngine(): RetentionEngineInterface
    {
        return $this->logStoreIsDatabase()
            ? new DatabaseRetentionEngine($this->logDb())
            : new FileRetentionEngine($this->logPath());
    }

    public function pruner(): PrunerInterface
    {
        return $this->logStoreIsDatabase()
            ? new DatabasePruner($this->logDb())
            : new FileStoragePruner($this->logPath());
    }

    /** Uncached; the server wraps it in CachedRetentionVolumeAnalyzer. */
    public function volumeAnalyzer(): RetentionVolumeAnalyzerInterface
    {
        return $this->logStoreIsDatabase()
            ? new DatabaseRetentionVolumeAnalyzer($this->logDb())
            : new FileRetentionVolumeAnalyzer($this->logPath());
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
        if ($this->logStoreIsDatabase()) {
            $this->logDb()->pdo();
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
        [$user, $password] = $this->credentials();

        return $this->controlDb ??= Connection::mysql($this->dsn(), $user, $password);
    }

    /** Log-store connection: timestamps are stored and compared in UTC. */
    private function logDb(): Connection
    {
        [$user, $password] = $this->credentials();

        return $this->logDb ??= Connection::mysql($this->dsn(), $user, $password, ["SET time_zone = '+00:00'"]);
    }

    private function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->env['DB_HOST'] ?? '127.0.0.1',
            $this->env['DB_PORT'] ?? '3306',
            $this->env['DB_NAME'] ?? 'logservice',
        );
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        return [(string) ($this->env['DB_USER'] ?? 'root'), (string) ($this->env['DB_PASS'] ?? '')];
    }
}
