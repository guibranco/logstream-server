<?php

declare(strict_types=1);

namespace LogService\Database;

/**
 * Holds a PDO connection for a long-running process and re-opens it when the
 * server has dropped it.
 *
 * The HTTP server lives for days, but MariaDB closes idle sessions after
 * `wait_timeout`, and firewalls/NAT/VPN tunnels may silently drop idle TCP
 * connections earlier. A plain PDO then fails every query with
 * "MySQL server has gone away" until the process restarts.
 *
 * Two layers keep it usable:
 *
 *  - pdo() pings the connection with `SELECT 1` once it has been idle for
 *    longer than $idleCheckSeconds and reconnects if the ping fails. The
 *    connection is opened lazily on first use.
 *  - run() executes a unit of work and, when it fails because the connection
 *    was lost, drops the dead handle and retries once on a fresh one. The idle
 *    ping alone is not enough: a periodic writer (e.g. the last-seen flush)
 *    keeps the connection "recently used", so a session the server killed was
 *    never pinged again and every query failed until the process restarted.
 *
 * Prefer run() over pdo() for anything that talks to the database.
 */
final class Connection
{
    /**
     * Client error codes meaning the session is gone and must be reopened:
     * 2006 server has gone away, 2013 lost connection during query,
     * 2055 lost connection at '...', 4031 disconnected for inactivity (MySQL 8+).
     */
    private const LOST_CONNECTION_CODES = [2006, 2013, 2055, 4031];

    /** Fallback when the driver code is missing (e.g. exceptions raised by mysqlnd internals). */
    private const LOST_CONNECTION_MESSAGES = [
        'server has gone away',
        'lost connection to',
        'error while sending',
        'error reading result set',
        'disconnected by the server',
        'no connection to the server',
    ];

    private ?\PDO $pdo = null;
    private float $lastUsed = 0.0;

    /** @var \Closure(): \PDO */
    private readonly \Closure $factory;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param callable(): \PDO     $factory          Opens a new, configured connection.
     * @param float                $idleCheckSeconds Ping before reuse after this much idle time.
     * @param (callable(): float)|null $clock        Monotonic seconds; injectable for tests.
     */
    public function __construct(
        callable $factory,
        private readonly float $idleCheckSeconds = 10.0,
        ?callable $clock = null,
    ) {
        $this->factory = \Closure::fromCallable($factory);
        $this->clock   = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => hrtime(true) / 1e9;
    }

    /** Wrap an existing PDO without reconnect support (tests, short-lived CLI scripts). */
    public static function wrap(\PDO $pdo): self
    {
        return new self(static fn(): \PDO => $pdo, PHP_FLOAT_MAX);
    }

    /**
     * Build a MySQL/MariaDB connection with the project's standard options.
     *
     * @param list<string> $initStatements SQL run on every (re)connect, e.g. "SET time_zone = '+00:00'".
     */
    public static function mysql(string $dsn, string $user, string $password, array $initStatements = []): self
    {
        return new self(static function () use ($dsn, $user, $password, $initStatements): \PDO {
            $pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            foreach ($initStatements as $sql) {
                $pdo->exec($sql);
            }
            return $pdo;
        });
    }

    public function pdo(): \PDO
    {
        $now = ($this->clock)();

        if ($this->pdo === null) {
            $this->pdo = ($this->factory)();
        } elseif (
            $now - $this->lastUsed >= $this->idleCheckSeconds
            && !$this->pdo->inTransaction()
            && !$this->isAlive($this->pdo)
        ) {
            $this->pdo = null; // release the dead handle before opening a new one
            $this->pdo = ($this->factory)();
        }

        $this->lastUsed = $now;
        return $this->pdo;
    }

    /**
     * Run $work against the connection. When it fails because the connection
     * was lost, the dead handle is dropped; if $retry is true the work runs once
     * more on a fresh connection.
     *
     * Retrying is safe for reads and idempotent writes. Pass $retry = false for
     * writes that must not run twice (the handle is still dropped, so the next
     * call reconnects). A unit started inside a caller's open transaction is
     * never retried: that transaction died with the connection.
     *
     * A transaction begun and finished inside $work is safe to retry: the
     * server rolls back an open transaction when the session ends.
     *
     * @template T
     * @param callable(\PDO): T $work
     * @return T
     */
    public function run(callable $work, bool $retry = true): mixed
    {
        $insideOuterTransaction = $this->pdo !== null && $this->pdo->inTransaction();

        try {
            return $work($this->pdo());
        } catch (\Throwable $e) {
            if (!self::isConnectionLost($e)) {
                throw $e;
            }
            $this->invalidate();
            if (!$retry || $insideOuterTransaction) {
                throw $e;
            }
        }

        return $work($this->pdo());
    }

    /** Drop the current handle; the next pdo()/run() opens a new connection. */
    public function invalidate(): void
    {
        $this->pdo = null;
    }

    /** Whether $e (or anything it wraps) says the database connection was lost. */
    public static function isConnectionLost(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof \PDOException) {
                continue;
            }

            $code = $current->errorInfo[1] ?? null;
            if (is_int($code) && in_array($code, self::LOST_CONNECTION_CODES, true)) {
                return true;
            }

            $message = strtolower($current->getMessage());
            foreach (self::LOST_CONNECTION_MESSAGES as $needle) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isAlive(\PDO $pdo): bool
    {
        try {
            // mysqlnd also raises an E_WARNING on a dead socket; the exception is what we act on.
            return @$pdo->query('SELECT 1') !== false;
        } catch (\PDOException) {
            // A failed `SELECT 1` means the session is unusable, whatever the reason.
            return false;
        }
    }
}
