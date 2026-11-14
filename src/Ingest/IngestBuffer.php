<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Models\LogEntry;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * In-memory queue between POST /api/logs and storage.
 *
 * Requests enqueue validated entries and are answered straight away; a
 * periodic timer (or a full batch) writes the queue to storage in batches
 * through a BatchWriterInterface. Only one batch is in flight at a time.
 *
 * Failure handling:
 *   - A failed batch goes back to the front of the queue and is retried with
 *     exponential backoff (0.5s, 1s, 2s … capped at 30s).
 *   - After `isolateAfterFailures` failures in a row the next batch is written
 *     entry by entry. If some entries succeed, the ones that fail are bad data
 *     (they would block the queue forever) and are dropped and reported. If
 *     none succeed the storage is down and everything stays queued.
 *   - `capacity` bounds queued + in-flight entries; enqueue() refuses beyond
 *     it so a long outage turns into 503s instead of an out-of-memory crash.
 *
 * Entries are lost only if the process dies without draining (kill -9, OOM,
 * host crash): at most one flush interval's worth under normal load.
 */
final class IngestBuffer
{
    private const MAX_BACKOFF_SECONDS = 30.0;

    /** Individual writes that fail in a row, with none succeeding, before isolation gives up. */
    private const ISOLATION_PROBE_LIMIT = 3;

    /** @var list<array{0: LogEntry, 1: IngestTicket}> */
    private array $queue = [];

    private int $inFlight = 0;
    private bool $flushing = false;
    private bool $accepting = true;
    private int $consecutiveFailures = 0;
    private float $retryAt = 0.0;
    private ?TimerInterface $timer = null;

    private int $acceptedTotal = 0;
    private int $persistedTotal = 0;
    private int $rejectedTotal = 0;
    private int $droppedTotal = 0;
    private int $failedFlushesTotal = 0;
    private ?string $lastError = null;
    private ?\DateTimeImmutable $lastFlushAt = null;
    private ?float $lastFlushMs = null;

    /** @var \Closure(list<LogEntry>): void */
    private readonly \Closure $onPersisted;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private readonly LoopInterface $loop;

    /** @var \Closure(string, string, array<string, mixed>): void */
    private readonly \Closure $log;

    /**
     * @param (callable(list<LogEntry>): void)|null $onPersisted Called with each stored batch (e.g. WebSocket broadcast).
     * @param (callable(): float)|null              $clock       Monotonic seconds; injectable for tests.
     * @param (callable(string $level, string $message, array<string, mixed> $context): void)|null $log
     *        Where failed flushes and dropped entries are reported; STDERR by default.
     */
    public function __construct(
        private readonly BatchWriterInterface $writer,
        private readonly int $batchSize = 500,
        private readonly int $capacity = 50_000,
        private readonly float $flushIntervalSeconds = 0.5,
        ?callable $onPersisted = null,
        ?LoopInterface $loop = null,
        ?callable $clock = null,
        private readonly int $isolateAfterFailures = 3,
        ?callable $log = null,
    ) {
        $this->onPersisted = \Closure::fromCallable($onPersisted ?? static function (array $entries): void {});
        $this->clock       = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => hrtime(true) / 1e9;
        $this->loop        = $loop ?? Loop::get();
        $this->log         = $log !== null
            ? \Closure::fromCallable($log)
            : static function (string $level, string $message, array $context = []): void {
                fwrite(STDERR, '[Ingest]   ' . $message . "\n");
            };
    }

    public static function fromConfig(
        BatchWriterInterface $writer,
        IngestConfig $config,
        ?callable $onPersisted = null,
        ?callable $log = null,
    ): self {
        return new self(
            writer:               $writer,
            batchSize:            $config->batchSize,
            capacity:             $config->capacity,
            flushIntervalSeconds: $config->flushIntervalMs / 1000,
            onPersisted:          $onPersisted,
            log:                  $log,
        );
    }

    /** Start the periodic flush timer. */
    public function start(): void
    {
        $this->timer ??= $this->loop->addPeriodicTimer($this->flushIntervalSeconds, fn() => $this->flush());
    }

    public function stop(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }

    /**
     * Queue the entries of one request. All or nothing: returns null (and
     * queues nothing) when they don't fit or the buffer is draining.
     *
     * @param list<LogEntry> $entries
     */
    public function enqueue(array $entries): ?IngestTicket
    {
        $count = count($entries);

        if (!$this->accepting || $this->pending() + $count > $this->capacity) {
            $this->rejectedTotal += $count;
            return null;
        }

        $ticket = new IngestTicket($count);
        foreach ($entries as $entry) {
            $this->queue[] = [$entry, $ticket];
        }
        $this->acceptedTotal += $count;

        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }

        return $ticket;
    }

    /** Queued + in-flight entries. */
    public function pending(): int
    {
        return count($this->queue) + $this->inFlight;
    }

    public function isAccepting(): bool
    {
        return $this->accepting;
    }

    /**
     * Write the next batch, unless one is already in flight or a retry
     * backoff is running ($force skips the backoff, used while draining).
     */
    public function flush(bool $force = false): void
    {
        if ($this->flushing || $this->queue === []) {
            return;
        }
        if (!$force && ($this->clock)() < $this->retryAt) {
            return;
        }

        $this->flushing = true;
        $items          = array_splice($this->queue, 0, $this->batchSize);
        $this->inFlight = count($items);
        $startedAt      = ($this->clock)();
        $isolate        = $this->consecutiveFailures >= $this->isolateAfterFailures && count($items) > 1;

        $outcome = $isolate ? $this->writeIndividually($items) : $this->writeBatch($items);

        $outcome->then(function (array $result) use ($isolate, $startedAt) {
            $this->settle($result['ok'], $result['failed'], $result['error'], $isolate, $startedAt);
        });
    }

    /**
     * Stop accepting entries and write everything still queued.
     * Fulfils with true when the buffer is empty, false on timeout.
     *
     * @return PromiseInterface<bool>
     */
    public function drain(float $timeoutSeconds): PromiseInterface
    {
        $this->accepting = false;
        $this->stop();

        if ($this->pending() === 0) {
            return resolve(true);
        }

        $deferred = new Deferred();
        $poll     = null;
        $deadline = $this->loop->addTimer($timeoutSeconds, function () use ($deferred, &$poll) {
            $this->loop->cancelTimer($poll);
            $deferred->resolve(false);
        });
        $poll = $this->loop->addPeriodicTimer(0.05, function () use ($deferred, &$poll, $deadline) {
            if ($this->pending() === 0) {
                $this->loop->cancelTimer($poll);
                $this->loop->cancelTimer($deadline);
                $deferred->resolve(true);
                return;
            }
            $this->flush(force: true);
        });
        $this->flush(force: true);

        return $deferred->promise();
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return [
            'writer'                => $this->writer->name(),
            'accepting'             => $this->accepting,
            'queued'                => count($this->queue),
            'in_flight'             => $this->inFlight,
            'capacity'              => $this->capacity,
            'batch_size'            => $this->batchSize,
            'flush_interval_ms'     => (int) round($this->flushIntervalSeconds * 1000),
            'accepted_total'        => $this->acceptedTotal,
            'persisted_total'       => $this->persistedTotal,
            'rejected_total'        => $this->rejectedTotal,
            'dropped_total'         => $this->droppedTotal,
            'failed_flushes_total'  => $this->failedFlushesTotal,
            'consecutive_failures'  => $this->consecutiveFailures,
            'last_flush_at'         => $this->lastFlushAt?->format(\DateTimeInterface::RFC3339_EXTENDED),
            'last_flush_ms'         => $this->lastFlushMs,
            'last_error'            => $this->lastError,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array{0: LogEntry, 1: IngestTicket}> $items
     * @return PromiseInterface<array{ok: list<array{0: LogEntry, 1: IngestTicket}>, failed: list<array{0: LogEntry, 1: IngestTicket}>, error: ?\Throwable}>
     */
    private function writeBatch(array $items): PromiseInterface
    {
        return $this->safeWrite(array_map(static fn(array $item) => $item[0], $items))->then(
            static fn() => ['ok' => $items, 'failed' => [], 'error' => null],
            static fn(\Throwable $e) => ['ok' => [], 'failed' => $items, 'error' => $e],
        );
    }

    /**
     * Write entries one at a time to find the ones storage rejects. Stops
     * early — keeping the rest for later — when the first few all fail,
     * because then the storage is down rather than the data bad.
     *
     * @param  list<array{0: LogEntry, 1: IngestTicket}> $items
     * @return PromiseInterface<array{ok: list<array{0: LogEntry, 1: IngestTicket}>, failed: list<array{0: LogEntry, 1: IngestTicket}>, error: ?\Throwable}>
     */
    private function writeIndividually(array $items): PromiseInterface
    {
        $deferred = new Deferred();
        $ok       = [];
        $failed   = [];
        $error    = null;

        $next = function (int $i) use (&$next, $items, &$ok, &$failed, &$error, $deferred): void {
            $storageDown = $ok === [] && count($failed) >= self::ISOLATION_PROBE_LIMIT;

            if ($i >= count($items) || $storageDown) {
                $deferred->resolve([
                    'ok'      => $ok,
                    'failed'  => $failed,
                    'untried' => array_slice($items, $i),
                    'error'   => $error,
                ]);
                return;
            }

            $this->safeWrite([$items[$i][0]])->then(
                function () use (&$ok, $items, $i, $next) {
                    $ok[] = $items[$i];
                    $next($i + 1);
                },
                function (\Throwable $e) use (&$failed, &$error, $items, $i, $next) {
                    $failed[] = $items[$i];
                    $error    = $e;
                    $next($i + 1);
                },
            );
        };
        $next(0);

        return $deferred->promise()->then(function (array $result) {
            if ($result['ok'] === []) {
                // Nothing could be stored: the storage is down, not the data bad.
                return [
                    'ok'     => [],
                    'failed' => array_merge($result['failed'], $result['untried']),
                    'error'  => $result['error'],
                ];
            }

            // Some entries were stored, so the ones that failed are bad data
            // that would block the queue forever: drop and report them.
            foreach ($result['failed'] as [$entry, $ticket]) {
                $this->droppedTotal++;
                $ticket->dropped($entry);
                $reason = $result['error']?->getMessage() ?? 'rejected by storage';
                ($this->log)('error', sprintf('Dropped entry %s (app_key=%s): %s', $entry->id, $entry->appKey, $reason), [
                    'entry_id' => $entry->id,
                    'app_key'  => $entry->appKey,
                    'error'    => $reason,
                ]);
            }
            return ['ok' => $result['ok'], 'failed' => $result['untried'], 'error' => null];
        });
    }

    /**
     * @param  list<array{0: LogEntry, 1: IngestTicket}> $ok
     * @param  list<array{0: LogEntry, 1: IngestTicket}> $failed Requeued at the front; counts as a
     *                                                    failed flush only when nothing was stored.
     */
    private function settle(array $ok, array $failed, ?\Throwable $error, bool $isolated, float $startedAt): void
    {
        $now               = ($this->clock)();
        $this->lastFlushMs = round(($now - $startedAt) * 1000, 2);
        $this->lastFlushAt = new \DateTimeImmutable();

        if ($ok !== []) {
            foreach ($ok as [$entry, $ticket]) {
                $ticket->persisted($entry);
            }
            $this->persistedTotal      += count($ok);
            $this->consecutiveFailures  = 0;
            $this->retryAt              = 0.0;
            $this->lastError            = null;

            try {
                ($this->onPersisted)(array_map(static fn(array $item) => $item[0], $ok));
            } catch (\Throwable $e) {
                ($this->log)('warning', 'onPersisted callback failed: ' . $e->getMessage(), []);
            }
        }

        if ($failed !== []) {
            array_unshift($this->queue, ...$failed);

            if ($ok === []) {
                $this->consecutiveFailures++;
                $this->failedFlushesTotal++;
                $this->lastError = $error?->getMessage() ?? 'unknown error';
                $backoff         = min(self::MAX_BACKOFF_SECONDS, 0.5 * (2 ** ($this->consecutiveFailures - 1)));
                $this->retryAt   = $now + $backoff;
                ($this->log)('warning', sprintf(
                    'Flush of %d entries failed (%d in a row%s), retrying in %.1fs: %s',
                    count($failed),
                    $this->consecutiveFailures,
                    $isolated ? ', entry by entry' : '',
                    $backoff,
                    $this->lastError,
                ), [
                    'entries'              => count($failed),
                    'consecutive_failures' => $this->consecutiveFailures,
                    'retry_in_seconds'     => $backoff,
                    'error'                => $this->lastError,
                ]);
            }
        }

        $this->inFlight = 0;
        $this->flushing = false;

        // Keep up under load: don't wait for the timer while a full batch is ready.
        if (count($this->queue) >= $this->batchSize && ($this->clock)() >= $this->retryAt) {
            $this->loop->futureTick(fn() => $this->flush());
        }
    }

    /**
     * The writer's promise, with a synchronous throw turned into a rejection.
     *
     * @param list<LogEntry> $entries
     */
    private function safeWrite(array $entries): PromiseInterface
    {
        try {
            return $this->writer->write($entries);
        } catch (\Throwable $e) {
            return reject($e);
        }
    }
}
