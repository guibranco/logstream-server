<?php

declare(strict_types=1);

namespace LogService\Ingest;

/**
 * How POST /api/logs persists entries.
 *
 *   INGEST_MODE=buffered (default)
 *     Entries are validated, queued in memory and answered with 202 Accepted.
 *     A timer writes the queue to storage in batches. Clients that need to
 *     know the entry is stored send `Prefer: return=representation` and get
 *     201 Created once their entries are written.
 *
 *   INGEST_MODE=sync
 *     The original behaviour: every entry is written before the response,
 *     which is always 201 Created with the stored entries.
 *
 *   DB_DRIVER=async (default) | pdo
 *     Which client the buffered path uses to write to MariaDB. `async` uses a
 *     non-blocking ReactPHP connection, so flushing never stalls the event
 *     loop; `pdo` reuses the blocking PDO connection. Reads, client
 *     management and retention always use PDO. Ignored for file storage and
 *     in sync mode (the sync path is blocking by definition).
 */
final class IngestConfig
{
    public const MODE_BUFFERED = 'buffered';
    public const MODE_SYNC     = 'sync';

    public const DRIVER_ASYNC = 'async';
    public const DRIVER_PDO   = 'pdo';

    public function __construct(
        public readonly string $mode              = self::MODE_BUFFERED,
        public readonly string $dbDriver          = self::DRIVER_ASYNC,
        /** Maximum entries per INSERT / file write. */
        public readonly int    $batchSize         = 500,
        /** How often the buffer is flushed when it is not full. */
        public readonly int    $flushIntervalMs   = 500,
        /** Queued + in-flight entries above which new requests get 503. */
        public readonly int    $capacity          = 50_000,
        /** How long a `Prefer: return=representation` request waits for its write. */
        public readonly int    $waitTimeoutMs     = 10_000,
        /** How long SIGTERM/SIGINT waits for the buffer to drain before exiting. */
        public readonly int    $shutdownTimeoutMs = 8_000,
    ) {
        if (!in_array($mode, [self::MODE_BUFFERED, self::MODE_SYNC], true)) {
            throw new \InvalidArgumentException("INGEST_MODE must be 'buffered' or 'sync', got '{$mode}'");
        }
        if (!in_array($dbDriver, [self::DRIVER_ASYNC, self::DRIVER_PDO], true)) {
            throw new \InvalidArgumentException("DB_DRIVER must be 'async' or 'pdo', got '{$dbDriver}'");
        }
        foreach ([
            'INGEST_BATCH_SIZE'          => $batchSize,
            'INGEST_FLUSH_INTERVAL_MS'   => $flushIntervalMs,
            'INGEST_BUFFER_CAPACITY'     => $capacity,
            'INGEST_WAIT_TIMEOUT_MS'     => $waitTimeoutMs,
            'INGEST_SHUTDOWN_TIMEOUT_MS' => $shutdownTimeoutMs,
        ] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException("{$name} must be a positive integer, got {$value}");
            }
        }
        if ($capacity < $batchSize) {
            throw new \InvalidArgumentException(
                "INGEST_BUFFER_CAPACITY ({$capacity}) must be at least INGEST_BATCH_SIZE ({$batchSize})",
            );
        }
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env): self
    {
        return new self(
            mode:              strtolower(trim((string) ($env['INGEST_MODE'] ?? '') ?: self::MODE_BUFFERED)),
            dbDriver:          strtolower(trim((string) ($env['DB_DRIVER'] ?? '') ?: self::DRIVER_ASYNC)),
            batchSize:         self::int($env, 'INGEST_BATCH_SIZE', 500),
            flushIntervalMs:   self::int($env, 'INGEST_FLUSH_INTERVAL_MS', 500),
            capacity:          self::int($env, 'INGEST_BUFFER_CAPACITY', 50_000),
            waitTimeoutMs:     self::int($env, 'INGEST_WAIT_TIMEOUT_MS', 10_000),
            shutdownTimeoutMs: self::int($env, 'INGEST_SHUTDOWN_TIMEOUT_MS', 8_000),
        );
    }

    public function isBuffered(): bool
    {
        return $this->mode === self::MODE_BUFFERED;
    }

    /** @param array<string, mixed> $env */
    private static function int(array $env, string $name, int $default): int
    {
        $raw = trim((string) ($env[$name] ?? ''));
        if ($raw === '') {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new \InvalidArgumentException("{$name} must be an integer, got '{$raw}'");
        }
        return (int) $raw;
    }
}
