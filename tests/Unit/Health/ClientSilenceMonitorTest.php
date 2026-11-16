<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Health;

use LogService\Health\ClientActivityTracker;
use LogService\Health\ClientSilenceMonitor;
use LogService\Health\SilenceNotifierInterface;
use LogService\Repository\Client;
use LogService\Repository\ClientRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientSilenceMonitorTest extends TestCase
{
    private \DateTimeImmutable $now;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<Client> */
    private array $clients = [];

    protected function setUp(): void
    {
        $this->now     = new \DateTimeImmutable('2026-04-17T17:00:00Z');
        $this->sent    = [];
        $this->clients = [];
    }

    private function client(
        string  $appKey,
        ?string $lastSeen,
        ?int    $timeout,
        bool    $active = true,
    ): Client {
        return new Client(
            id:                  1,
            name:                ucfirst($appKey),
            appKey:              $appKey,
            active:              $active,
            createdAt:           '2026-01-01 00:00:00',
            updatedAt:           '2026-01-01 00:00:00',
            lastSeenAt:          $lastSeen === null ? null : new \DateTimeImmutable($lastSeen),
            heartbeatTimeoutMin: $timeout,
        );
    }

    private function monitor(?ClientActivityTracker $tracker = null, ?SilenceNotifierInterface $notifier = null): ClientSilenceMonitor
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('all')->willReturnCallback(fn() => $this->clients);

        $notifier ??= new class ($this->sent) implements SilenceNotifierInterface {
            public function __construct(private array &$sent) {}
            public function notify(array $payload): void
            {
                $this->sent[] = $payload;
            }
        };

        return new ClientSilenceMonitor($repo, $notifier, $tracker, fn() => $this->now);
    }

    #[Test]
    public function alerts_silent_client_with_payload(): void
    {
        $this->clients = [$this->client('billing-api', '2026-04-17T16:13:00Z', 10)];

        $alerts = $this->monitor()->check();

        self::assertCount(1, $alerts);
        self::assertSame([
            'alert'           => 'client-silent',
            'triggered'       => '2026-04-17T17:00:00+00:00',
            'window'          => '10m',
            'app_key'         => 'billing-api',
            'name'            => 'Billing-api',
            'status'          => 'silent',
            'last_seen'       => '2026-04-17T16:13:00+00:00',
            'silent_for'      => '47m 0s',
            'timeout_minutes' => 10,
        ], $this->sent[0]);
    }

    #[Test]
    public function ignores_active_never_seen_unmonitored_and_disabled_clients(): void
    {
        $this->clients = [
            $this->client('active', '2026-04-17T16:58:00Z', 10),
            $this->client('never', null, 10),
            $this->client('unmonitored', '2026-01-01T00:00:00Z', null),
            $this->client('disabled', '2026-04-17T10:00:00Z', 10, active: false),
        ];

        self::assertSame([], $this->monitor()->check());
    }

    #[Test]
    public function alerts_once_per_silence_and_again_after_recovery(): void
    {
        $monitor       = $this->monitor();
        $this->clients = [$this->client('billing-api', '2026-04-17T16:00:00Z', 10)];

        self::assertCount(1, $monitor->check());
        self::assertCount(0, $monitor->check(), 'cooldown: still the same silence');

        $this->clients = [$this->client('billing-api', '2026-04-17T16:59:00Z', 10)];
        self::assertCount(0, $monitor->check(), 'recovered');

        $this->now = $this->now->modify('+30 minutes');
        self::assertCount(1, $monitor->check(), 'silent again');
    }

    #[Test]
    public function unflushed_activity_counts_as_seen(): void
    {
        $tracker = new ClientActivityTracker($this->createStub(ClientRepository::class), fn() => $this->now);
        $tracker->touch('billing-api');
        $this->clients = [$this->client('billing-api', '2026-04-17T16:00:00Z', 10)];

        self::assertSame([], $this->monitor($tracker)->check());
    }

    #[Test]
    public function failed_notification_is_retried_next_check(): void
    {
        $calls    = 0;
        $notifier = new class ($calls) implements SilenceNotifierInterface {
            public function __construct(private int &$calls) {}
            public function notify(array $payload): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('webhook down');
                }
            }
        };
        $monitor       = $this->monitor(notifier: $notifier);
        $this->clients = [$this->client('billing-api', '2026-04-17T16:00:00Z', 10)];

        self::assertCount(0, $monitor->check());
        self::assertCount(1, $monitor->check());
        self::assertSame(2, $calls);
    }

    #[Test]
    public function repository_failure_does_not_throw(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('all')->willThrowException(new \RuntimeException('db down'));
        $monitor = new ClientSilenceMonitor(
            $repo,
            $this->createStub(SilenceNotifierInterface::class),
            clock: fn() => $this->now,
        );

        self::assertSame([], $monitor->check());
    }
}
