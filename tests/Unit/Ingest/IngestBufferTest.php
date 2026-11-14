<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Ingest\IngestBuffer;
use LogService\Models\LogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

final class IngestBufferTest extends TestCase
{
    private FakeBatchWriter $writer;
    private StreamSelectLoop $loop;
    private float $now = 1000.0;

    /** @var list<string> ids passed to onPersisted */
    private array $broadcast = [];

    protected function setUp(): void
    {
        $this->writer = new FakeBatchWriter();
        $this->loop   = new StreamSelectLoop();
    }

    private function buffer(int $batchSize = 3, int $capacity = 10, int $isolateAfter = 3): IngestBuffer
    {
        return new IngestBuffer(
            writer:               $this->writer,
            batchSize:            $batchSize,
            capacity:             $capacity,
            flushIntervalSeconds: 0.01,
            onPersisted:          function (array $entries) {
                array_push($this->broadcast, ...array_map(static fn(LogEntry $e) => $e->id, $entries));
            },
            loop:                 $this->loop,
            clock:                fn() => $this->now,
            isolateAfterFailures: $isolateAfter,
        );
    }

    // ── Queueing ──────────────────────────────────────────────────────────────

    #[Test]
    public function enqueued_entries_are_not_written_until_a_flush(): void
    {
        $buffer = $this->buffer();
        $ticket = $buffer->enqueue($this->entries('A', 'B'));

        self::assertNotNull($ticket);
        self::assertSame([], $this->writer->batches);
        self::assertSame(2, $buffer->pending());
        self::assertFalse($ticket->isSettled());
    }

    #[Test]
    public function a_flush_writes_the_queue_as_one_batch_and_broadcasts_it(): void
    {
        $buffer = $this->buffer();
        $ticket = $buffer->enqueue($this->entries('A', 'B'));

        $buffer->flush();

        self::assertSame([['A', 'B']], $this->writer->batches);
        self::assertSame(['A', 'B'], $this->broadcast);
        self::assertTrue($ticket->isSettled());
        self::assertSame(0, $buffer->pending());
    }

    #[Test]
    public function a_full_batch_is_written_without_waiting_for_the_timer(): void
    {
        $buffer = $this->buffer(batchSize: 3);

        $buffer->enqueue($this->entries('A', 'B'));
        self::assertSame([], $this->writer->batches);

        $buffer->enqueue($this->entries('C'));
        self::assertSame([['A', 'B', 'C']], $this->writer->batches);
    }

    #[Test]
    public function batches_never_exceed_the_batch_size(): void
    {
        $buffer = $this->buffer(batchSize: 2, capacity: 10);
        $this->writer->holdWrites = true;

        $buffer->enqueue($this->entries('A', 'B', 'C', 'D', 'E'));
        $this->writer->releaseHeld();
        $this->loop->futureTick(fn() => $this->loop->stop());
        $this->loop->run(); // the follow-up flush is scheduled on the next tick

        self::assertSame(['A', 'B'], $this->writer->batches[0]);
        foreach ($this->writer->batches as $batch) {
            self::assertLessThanOrEqual(2, count($batch));
        }
    }

    #[Test]
    public function only_one_batch_is_in_flight_at_a_time(): void
    {
        $buffer = $this->buffer(batchSize: 2);
        $this->writer->holdWrites = true;

        $buffer->enqueue($this->entries('A', 'B'));
        $buffer->enqueue($this->entries('C', 'D'));
        $buffer->flush();

        self::assertSame([['A', 'B']], $this->writer->batches);
        self::assertSame(2, $buffer->stats()['in_flight']);
        self::assertSame(2, $buffer->stats()['queued']);

        $this->writer->releaseHeld();
        $buffer->flush();

        self::assertSame([['A', 'B'], ['C', 'D']], $this->writer->batches);
    }

    #[Test]
    public function the_periodic_timer_flushes_the_queue(): void
    {
        $buffer = $this->buffer();
        $buffer->start();
        $buffer->enqueue($this->entries('A'));

        $this->loop->addTimer(0.05, fn() => $this->loop->stop());
        $this->loop->run();
        $buffer->stop();

        self::assertSame(['A'], $this->writer->stored);
    }

    // ── Capacity ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_request_that_does_not_fit_is_refused_whole(): void
    {
        $buffer = $this->buffer(batchSize: 3, capacity: 4);
        $this->writer->down = true;

        self::assertNotNull($buffer->enqueue($this->entries('A', 'B', 'C'))); // flushes, fails, requeued
        self::assertNull($buffer->enqueue($this->entries('D', 'E')));

        self::assertSame(3, $buffer->pending());
        self::assertSame(2, $buffer->stats()['rejected_total']);
        self::assertNotNull($buffer->enqueue($this->entries('F')));
    }

    // ── Failures ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_failed_batch_is_requeued_in_order_and_retried_after_a_backoff(): void
    {
        $buffer = $this->buffer();
        $ticket = $buffer->enqueue($this->entries('A', 'B'));

        $this->writer->down = true;
        $buffer->flush();
        $buffer->enqueue($this->entries('C'));

        self::assertSame(1, $buffer->stats()['consecutive_failures']);
        self::assertSame('storage down', $buffer->stats()['last_error']);
        self::assertFalse($ticket->isSettled());

        // Storage is back, but the 0.5s backoff hasn't elapsed: nothing is written.
        $this->writer->down = false;
        $this->now += 0.4;
        $buffer->flush();
        self::assertCount(1, $this->writer->batches);

        $this->now += 0.2;
        $buffer->flush();

        self::assertSame(['A', 'B', 'C'], $this->writer->stored);
        self::assertTrue($ticket->isSettled());
        self::assertSame(0, $buffer->stats()['consecutive_failures']);
        self::assertNull($buffer->stats()['last_error']);
    }

    #[Test]
    public function the_backoff_doubles_with_each_failure(): void
    {
        $buffer = $this->buffer();
        $buffer->enqueue($this->entries('A'));
        $this->writer->down = true;

        $buffer->flush();          // fails → retry in 0.5s
        $this->now += 0.5;
        $buffer->flush();          // fails → retry in 1s
        $this->now += 0.9;
        $buffer->flush();          // still backing off

        self::assertCount(2, $this->writer->batches);
        self::assertSame(2, $buffer->stats()['failed_flushes_total']);
    }

    #[Test]
    public function after_repeated_failures_bad_entries_are_dropped_and_the_rest_stored(): void
    {
        $buffer = $this->buffer(batchSize: 5, isolateAfter: 2);
        $ticket = $buffer->enqueue($this->entries('A', 'BAD', 'C'));
        $this->writer->poisonIds = ['BAD'];

        $buffer->flush();
        $this->now += 1;
        $buffer->flush();
        self::assertSame([], $this->writer->stored);

        // Third attempt goes entry by entry.
        $this->now += 2;
        $buffer->flush();

        self::assertSame(['A', 'C'], $this->writer->stored);
        self::assertSame(['A', 'C'], $this->broadcast);
        self::assertSame(1, $buffer->stats()['dropped_total']);
        self::assertSame(0, $buffer->pending());

        $dropped = null;
        $ticket->wait(1, $this->loop)->then(function ($ids) use (&$dropped) { $dropped = $ids; });
        self::assertSame(['BAD'], $dropped);
    }

    #[Test]
    public function isolation_keeps_everything_queued_when_the_storage_is_down(): void
    {
        $buffer = $this->buffer(batchSize: 10, capacity: 10, isolateAfter: 1);
        $buffer->enqueue($this->entries('A', 'B', 'C', 'D', 'E'));
        $this->writer->down = true;

        $buffer->flush();          // whole batch fails
        $this->now += 1;
        $buffer->flush();          // entry by entry: gives up after 3 failed probes

        self::assertCount(1 + 3, $this->writer->batches);
        self::assertSame(5, $buffer->pending());
        self::assertSame(0, $buffer->stats()['dropped_total']);

        $this->writer->down = false;
        $this->now += 5;
        $buffer->flush();

        self::assertEqualsCanonicalizing(['A', 'B', 'C', 'D', 'E'], $this->writer->stored);
    }

    #[Test]
    public function a_writer_that_throws_is_treated_as_a_failed_flush(): void
    {
        $writer = new class implements \LogService\Ingest\BatchWriterInterface {
            public function write(array $entries): \React\Promise\PromiseInterface
            {
                throw new \LogicException('boom');
            }
            public function name(): string
            {
                return 'throwing';
            }
        };
        $buffer = new IngestBuffer($writer, loop: $this->loop, clock: fn() => $this->now);
        $buffer->enqueue($this->entries('A'));

        $buffer->flush();

        self::assertSame(1, $buffer->pending());
        self::assertSame('boom', $buffer->stats()['last_error']);
    }

    // ── Drain ─────────────────────────────────────────────────────────────────

    #[Test]
    public function drain_writes_everything_and_refuses_new_entries(): void
    {
        $buffer = $this->buffer(batchSize: 2, capacity: 10);
        $buffer->enqueue($this->entries('A'));
        $buffer->enqueue($this->entries('B', 'C', 'D'));

        $drained = null;
        $buffer->drain(1.0)->then(function (bool $ok) use (&$drained) { $drained = $ok; });
        $this->loop->addTimer(0.5, fn() => $this->loop->stop());
        $this->loop->run();

        self::assertTrue($drained);
        self::assertSame(['A', 'B', 'C', 'D'], $this->writer->stored);
        self::assertFalse($buffer->isAccepting());
        self::assertNull($buffer->enqueue($this->entries('E')));
    }

    #[Test]
    public function drain_ignores_the_backoff_and_reports_a_timeout(): void
    {
        $buffer = $this->buffer();
        $buffer->enqueue($this->entries('A'));
        $this->writer->down = true;
        $buffer->flush(); // fails, retry 0.5s away on the frozen clock

        $drained = null;
        $buffer->drain(0.1)->then(function (bool $ok) use (&$drained) { $drained = $ok; });
        $this->loop->addTimer(0.5, fn() => $this->loop->stop());
        $this->loop->run();

        self::assertFalse($drained);
        self::assertGreaterThan(2, count($this->writer->batches), 'drain keeps retrying despite the backoff');
    }

    #[Test]
    public function draining_an_empty_buffer_resolves_immediately(): void
    {
        $drained = null;
        $this->buffer()->drain(1.0)->then(function (bool $ok) use (&$drained) { $drained = $ok; });

        self::assertTrue($drained);
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    #[Test]
    public function stats_report_counters_and_configuration(): void
    {
        $buffer = $this->buffer(batchSize: 3, capacity: 10);
        $buffer->enqueue($this->entries('A', 'B'));
        $buffer->flush();

        $stats = $buffer->stats();

        self::assertSame('fake', $stats['writer']);
        self::assertSame(2, $stats['accepted_total']);
        self::assertSame(2, $stats['persisted_total']);
        self::assertSame(3, $stats['batch_size']);
        self::assertSame(10, $stats['capacity']);
        self::assertSame(10, $stats['flush_interval_ms']);
        self::assertNotNull($stats['last_flush_at']);
        self::assertTrue($stats['accepting']);
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<LogEntry> */
    private function entries(string ...$ids): array
    {
        return array_map(static fn(string $id) => LogEntry::fromArray([
            'id'         => $id,
            'trace_id'   => "trace-{$id}",
            'batch_id'   => null,
            'app_key'    => 'app',
            'app_id'     => 'test',
            'user_agent' => null,
            'level'      => 'info',
            'message'    => "Entry {$id}",
        ]), $ids);
    }
}
