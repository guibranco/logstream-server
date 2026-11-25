<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Database;

use LogService\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    private float $now = 1000.0;
    private int $opened = 0;

    /** @param list<\PDO> $handles returned in order by successive (re)connects */
    private function connection(array $handles, float $idle = 10.0): Connection
    {
        return new Connection(
            function () use (&$handles): \PDO {
                $this->opened++;
                return array_shift($handles) ?? throw new \LogicException('unexpected reconnect');
            },
            $idle,
            fn(): float => $this->now,
        );
    }

    private function deadPdo(): \PDO
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('query')->willThrowException(
            new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
        );
        return $pdo;
    }

    private function livePdo(): \PDO
    {
        return new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    #[Test]
    public function it_opens_the_connection_lazily(): void
    {
        $conn = $this->connection([$this->livePdo()]);

        self::assertSame(0, $this->opened);
        $conn->pdo();
        self::assertSame(1, $this->opened);
    }

    #[Test]
    public function it_reuses_the_connection_without_pinging_inside_the_idle_window(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::never())->method('query');
        $conn = $this->connection([$pdo]);

        self::assertSame($pdo, $conn->pdo());
        $this->now += 5;
        self::assertSame($pdo, $conn->pdo());
        self::assertSame(1, $this->opened);
    }

    #[Test]
    public function it_keeps_a_live_connection_after_a_successful_ping(): void
    {
        $pdo  = $this->livePdo();
        $conn = $this->connection([$pdo]);

        $conn->pdo();
        $this->now += 3600;

        self::assertSame($pdo, $conn->pdo());
        self::assertSame(1, $this->opened);
    }

    #[Test]
    public function it_reconnects_when_the_server_has_gone_away(): void
    {
        $fresh = $this->livePdo();
        $conn  = $this->connection([$this->deadPdo(), $fresh]);

        $conn->pdo();
        $this->now += 3600;

        self::assertSame($fresh, $conn->pdo());
        self::assertSame(2, $this->opened);
    }

    #[Test]
    public function it_never_swaps_the_connection_during_a_transaction(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('query');
        $conn = $this->connection([$pdo]);

        $conn->pdo();
        $this->now += 3600;

        self::assertSame($pdo, $conn->pdo());
    }

    #[Test]
    public function wrap_always_returns_the_given_pdo(): void
    {
        $pdo  = $this->livePdo();
        $conn = Connection::wrap($pdo);

        self::assertSame($pdo, $conn->pdo());
        self::assertSame($pdo, $conn->pdo());
    }

    #[Test]
    public function run_retries_once_on_a_fresh_connection_when_the_query_finds_it_gone(): void
    {
        // Used within the idle window, so pdo() never pings — the case seen in
        // production, where a periodic flush kept the dead handle "recent".
        $dead  = $this->deadPdo();
        $fresh = $this->livePdo();
        $conn  = $this->connection([$dead, $fresh]);
        $conn->pdo();

        $result = $conn->run(static fn(\PDO $pdo) => $pdo->query('SELECT 42')->fetchColumn());

        self::assertSame(42, (int) $result);
        self::assertSame(2, $this->opened);
        self::assertSame($fresh, $conn->pdo(), 'the fresh handle is kept for later calls');
    }

    #[Test]
    public function run_without_retry_drops_the_dead_handle_and_rethrows(): void
    {
        $fresh = $this->livePdo();
        $conn  = $this->connection([$this->deadPdo(), $fresh]);
        $conn->pdo();

        try {
            $conn->run(static fn(\PDO $pdo) => $pdo->query('SELECT 1'), retry: false);
            self::fail('expected the PDOException to propagate');
        } catch (\PDOException $e) {
            self::assertStringContainsString('gone away', $e->getMessage());
        }

        self::assertSame(1, $this->opened);
        self::assertSame($fresh, $conn->pdo(), 'the next call reconnects');
    }

    #[Test]
    public function run_does_not_retry_other_errors(): void
    {
        $conn  = $this->connection([$this->livePdo()]);
        $calls = 0;

        try {
            $conn->run(static function (\PDO $pdo) use (&$calls) {
                $calls++;
                return $pdo->query('SELECT * FROM no_such_table');
            });
            self::fail('expected the PDOException to propagate');
        } catch (\PDOException) {
        }

        self::assertSame(1, $calls);
        self::assertSame(1, $this->opened);
    }

    #[Test]
    public function run_does_not_retry_inside_a_callers_transaction(): void
    {
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $conn = $this->connection([$pdo]);
        $conn->pdo();

        $calls = 0;
        try {
            $conn->run(static function () use (&$calls) {
                $calls++;
                throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            });
            self::fail('expected the PDOException to propagate');
        } catch (\PDOException) {
        }

        self::assertSame(1, $calls);
    }

    #[Test]
    public function it_recognises_lost_connection_errors(): void
    {
        $byCode = new \PDOException('whatever');
        $byCode->errorInfo = ['HY000', 2013, 'Lost connection to MySQL server during query'];

        self::assertTrue(Connection::isConnectionLost($byCode));
        self::assertTrue(Connection::isConnectionLost(
            new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
        ));
        self::assertTrue(Connection::isConnectionLost(
            new \RuntimeException('wrapped', previous: new \PDOException('MySQL server has gone away')),
        ));
        self::assertFalse(Connection::isConnectionLost(
            new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'x' doesn't exist"),
        ));
        self::assertFalse(Connection::isConnectionLost(new \RuntimeException('MySQL server has gone away')));
    }
}
