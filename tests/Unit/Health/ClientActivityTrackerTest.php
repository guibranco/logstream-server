<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Health;

use LogService\Health\ClientActivityTracker;
use LogService\Repository\ClientRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientActivityTrackerTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-04-17T16:45:00Z');
    }

    private function tracker(ClientRepository $repo, array &$logs = []): ClientActivityTracker
    {
        return new ClientActivityTracker(
            $repo,
            fn() => $this->now,
            function (string $level, string $message) use (&$logs): void {
                $logs[] = [$level, $message];
            },
        );
    }

    #[Test]
    public function touch_keeps_newest_time_in_memory(): void
    {
        $tracker = $this->tracker($this->createStub(ClientRepository::class));

        $tracker->touch('billing-api', 'billing-api', 'auth-service');

        self::assertSame(2, $tracker->pendingCount());
        self::assertEquals($this->now, $tracker->lastSeen('billing-api'));
        self::assertNull($tracker->lastSeen('ghost'));
    }

    #[Test]
    public function touch_ignores_reserved_and_empty_keys(): void
    {
        $tracker = $this->tracker($this->createStub(ClientRepository::class));

        $tracker->touch('logstream-server', '');

        self::assertSame(0, $tracker->pendingCount());
    }

    #[Test]
    public function flush_writes_pending_once(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->expects(self::once())
            ->method('touchLastSeen')
            ->with(['billing-api' => $this->now])
            ->willReturn(1);

        $tracker = $this->tracker($repo);
        $tracker->touch('billing-api');

        self::assertSame(1, $tracker->flush());
        self::assertSame(0, $tracker->pendingCount());
        self::assertSame(0, $tracker->flush());
    }

    #[Test]
    public function failed_flush_keeps_pending_and_logs(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('touchLastSeen')->willThrowException(new \RuntimeException('gone away'));

        $logs    = [];
        $tracker = $this->tracker($repo, $logs);
        $tracker->touch('billing-api');

        self::assertSame(0, $tracker->flush());
        self::assertSame(1, $tracker->pendingCount());
        self::assertEquals($this->now, $tracker->lastSeen('billing-api'));
        self::assertSame('warning', $logs[0][0]);
        self::assertStringContainsString('gone away', $logs[0][1]);
    }
}
