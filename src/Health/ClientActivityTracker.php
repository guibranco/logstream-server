<?php

declare(strict_types=1);

namespace LogService\Health;

use LogService\Logging\SelfLogConfig;
use LogService\Repository\ClientRepository;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Records when each client last had entries accepted, without a database
 * write per request: touch() only updates memory, and flush() — run by a
 * timer every few seconds — writes one UPDATE per client that was seen since.
 *
 * Until it is flushed, lastSeen() still returns the newest time, so the
 * health endpoints are never behind.
 */
final class ClientActivityTracker
{
    public const DEFAULT_FLUSH_SECONDS = 5.0;

    /** @var array<string, \DateTimeImmutable> app_key => newest time not yet written */
    private array $pending = [];

    private ?TimerInterface $timer = null;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @var \Closure(string, string, array<string, mixed>): void */
    private readonly \Closure $log;

    /**
     * @param (callable(): \DateTimeImmutable)|null                        $clock
     * @param (callable(string, string, array<string, mixed>): void)|null $log   fn(level, message, context)
     */
    public function __construct(
        private readonly ClientRepository $clients,
        ?callable $clock = null,
        ?callable $log   = null,
    ) {
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->log = $log !== null
            ? \Closure::fromCallable($log)
            : static function (string $level, string $message, array $context = []): void {};
    }

    /** Mark the app_keys as seen now. The reserved self-log key is ignored. */
    public function touch(string ...$appKeys): void
    {
        $now = ($this->clock)();
        foreach (array_unique($appKeys) as $appKey) {
            if ($appKey === '' || SelfLogConfig::isReservedAppKey($appKey)) {
                continue;
            }
            $current = $this->pending[$appKey] ?? null;
            if ($current === null || $now > $current) {
                $this->pending[$appKey] = $now;
            }
        }
    }

    /** Newest seen time not yet written for this app_key, or null. */
    public function lastSeen(string $appKey): ?\DateTimeImmutable
    {
        return $this->pending[$appKey] ?? null;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * Write the pending times. On failure they are kept (unless a newer
     * touch replaced them meanwhile) and retried on the next flush.
     *
     * @return int Rows updated.
     */
    public function flush(): int
    {
        if ($this->pending === []) {
            return 0;
        }

        $batch         = $this->pending;
        $this->pending = [];

        try {
            return $this->clients->touchLastSeen($batch);
        } catch (\Throwable $e) {
            foreach ($batch as $appKey => $at) {
                $current = $this->pending[$appKey] ?? null;
                if ($current === null || $at > $current) {
                    $this->pending[$appKey] = $at;
                }
            }
            ($this->log)('warning', 'Could not record client last-seen times: ' . $e->getMessage(), [
                'clients' => count($batch),
            ]);
            return 0;
        }
    }

    /** Flush every $seconds on the loop. Idempotent. */
    public function start(LoopInterface $loop, float $seconds = self::DEFAULT_FLUSH_SECONDS): void
    {
        if ($this->timer !== null) {
            return;
        }
        $this->timer = $loop->addPeriodicTimer(max(0.1, $seconds), function (): void {
            $this->flush();
        });
    }

    public function stop(LoopInterface $loop): void
    {
        if ($this->timer !== null) {
            $loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }
}
