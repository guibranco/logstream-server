<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Models\LogEntry;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Tracks the entries of one ingest request through the buffer, so a request
 * that sent `Prefer: return=representation` can wait until they are written.
 */
final class IngestTicket
{
    private int $remaining;

    /** @var list<string> ids of entries that could not be stored */
    private array $droppedIds = [];

    private readonly Deferred $deferred;

    public function __construct(int $entries)
    {
        $this->remaining = $entries;
        $this->deferred  = new Deferred();
        if ($entries === 0) {
            $this->deferred->resolve([]);
        }
    }

    public function persisted(LogEntry $entry): void
    {
        $this->settleOne();
    }

    public function dropped(LogEntry $entry): void
    {
        if ($this->remaining === 0) {
            return;
        }
        $this->droppedIds[] = $entry->id;
        $this->settleOne();
    }

    public function isSettled(): bool
    {
        return $this->remaining === 0;
    }

    /**
     * Fulfils with the ids of entries that could not be stored (usually []),
     * or with null if they are not all settled within $timeoutSeconds.
     *
     * @return PromiseInterface<list<string>|null>
     */
    public function wait(float $timeoutSeconds, ?LoopInterface $loop = null): PromiseInterface
    {
        if ($this->isSettled()) {
            return $this->deferred->promise();
        }

        $loop  ??= Loop::get();
        $result  = new Deferred();
        $timer   = $loop->addTimer($timeoutSeconds, static fn() => $result->resolve(null));

        $this->deferred->promise()->then(static function (array $dropped) use ($result, $loop, $timer) {
            $loop->cancelTimer($timer);
            $result->resolve($dropped);
        });

        return $result->promise();
    }

    private function settleOne(): void
    {
        if ($this->remaining === 0) {
            return;
        }
        if (--$this->remaining === 0) {
            $this->deferred->resolve($this->droppedIds);
        }
    }
}
