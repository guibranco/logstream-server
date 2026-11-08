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
}
