<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Ingest\BatchWriterInterface;
use LogService\Models\LogEntry;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Records every batch. By default writes succeed immediately; tests can make
 * all writes fail, make writes containing given ids fail, or hold writes
 * pending until released.
 */
final class FakeBatchWriter implements BatchWriterInterface
{
    /** @var list<list<string>> ids of every batch, in call order */
    public array $batches = [];

    /** @var list<string> ids stored so far */
    public array $stored = [];

    public bool $down = false;

    /** @var list<string> any batch containing one of these ids fails */
    public array $poisonIds = [];

    /** @var (\Closure(LogEntry): bool)|null any batch containing a matching entry fails */
    public ?\Closure $poisonIf = null;

    public bool $holdWrites = false;

    /** @var list<Deferred> */
    private array $held = [];

    public function write(array $entries): PromiseInterface
    {
        $ids             = array_map(static fn(LogEntry $e) => $e->id, $entries);
        $this->batches[] = $ids;

        if ($this->holdWrites) {
            $deferred     = new Deferred();
            $this->held[] = $deferred;
            return $deferred->promise()->then(function () use ($ids) {
                array_push($this->stored, ...$ids);
                return null;
            });
        }

        if ($this->down) {
            return reject(new \RuntimeException('storage down'));
        }
        $poisoned = $this->poisonIf !== null && array_filter($entries, $this->poisonIf) !== [];
        if ($poisoned || array_intersect($ids, $this->poisonIds) !== []) {
            return reject(new \RuntimeException('Data too long for column message'));
        }

        array_push($this->stored, ...$ids);
        return resolve(null);
    }

    public function releaseHeld(): void
    {
        $held       = $this->held;
        $this->held = [];
        foreach ($held as $deferred) {
            $deferred->resolve(null);
        }
    }

    public function name(): string
    {
        return 'fake';
    }
}
