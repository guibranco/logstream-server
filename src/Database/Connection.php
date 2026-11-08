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
 * pdo() pings the connection with `SELECT 1` once it has been idle for longer
 * than $idleCheckSeconds and transparently reconnects if the ping fails. The
 * connection is opened lazily on first use.
 */
final class Connection
{
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
