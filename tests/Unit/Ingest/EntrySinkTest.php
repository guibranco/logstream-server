<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Health\ClientActivityTracker;
use LogService\Ingest\EntryFactory;
use LogService\Ingest\EntrySink;
use LogService\Ingest\IngestBuffer;
use LogService\Models\LogEntry;
use LogService\Repository\ClientRepository;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

final class EntrySinkTest extends TestCase
{
    /** @return list<LogEntry> */
    private static function entries(string ...$messages): array
    {
        [$entries] = EntryFactory::build(
            array_map(static fn(string $m) => ['message' => $m], $messages),
            false, null, 'billing-api', 'web-1', null, null, '10.0.0.7',
        );
        return array_values($entries);
    }

    #[Test]
    public function save_now_writes_broadcasts_and_records_last_seen(): void
    {
        $saved   = [];
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('save')->willReturnCallback(function (LogEntry $e) use (&$saved): void {
            if ($e->message === 'bad') {
                throw new \RuntimeException('Data too long');
            }
            $saved[] = $e->message;
        });
        $tracker = new ClientActivityTracker($this->createStub(ClientRepository::class));
        $sink    = new EntrySink($storage, new LogHub(''), null, $tracker);

        [$stored, $errors] = $sink->saveNow(self::entries('a', 'bad', 'c'));

        self::assertSame(['a', 'c'], $saved);
        self::assertSame(['a', 'c'], array_column($stored, 'message'));
        self::assertSame(['Entry #1: Data too long'], $errors);
        self::assertNotNull($tracker->lastSeen('billing-api'));
    }

    #[Test]
    public function accept_without_buffer_writes_now(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::exactly(2))->method('save');
        $sink = new EntrySink($storage, new LogHub(''));

        self::assertTrue($sink->accept(self::entries('a', 'b')));
        self::assertFalse($sink->accept([]));
        self::assertNull($sink->enqueue(self::entries('c')));
    }

    #[Test]
    public function accept_with_buffer_queues_and_reports_full(): void
    {
        $writer = new FakeBatchWriter();
        $buffer = new IngestBuffer(
            writer: $writer, batchSize: 10, capacity: 10, flushIntervalSeconds: 60.0,
            onPersisted: static function (): void {}, loop: new StreamSelectLoop(),
        );
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('save');
        $sink = new EntrySink($storage, new LogHub(''), $buffer);

        self::assertTrue($sink->accept(self::entries('a', 'b')));
        self::assertSame(2, $buffer->pending());
        self::assertSame($buffer, $sink->buffer());
        self::assertFalse($sink->accept(self::entries(...array_fill(0, 9, 'x'))), 'over capacity');
    }
}
