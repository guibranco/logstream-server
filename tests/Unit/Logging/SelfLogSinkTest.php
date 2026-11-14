<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Logging;

use LogService\Ingest\IngestBuffer;
use LogService\Logging\SelfLogSink;
use LogService\Models\LogEntry;
use LogService\Storage\StorageInterface;
use LogService\Tests\Unit\Ingest\FakeBatchWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

final class SelfLogSinkTest extends TestCase
{
    private function entry(string $id = 'E1'): LogEntry
    {
        return LogEntry::fromArray([
            'id'         => $id,
            'trace_id'   => 'T',
            'batch_id'   => null,
            'app_key'    => 'logstream-server',
            'app_id'     => 'test',
            'user_agent' => null,
            'level'      => 'info',
            'category'   => 'startup',
            'message'    => 'started',
        ]);
    }

    private function buffer(FakeBatchWriter $writer, int $capacity = 10): IngestBuffer
    {
        return new IngestBuffer(
            writer:               $writer,
            batchSize:            5,
            capacity:             $capacity,
            flushIntervalSeconds: 0.01,
            loop:                 new StreamSelectLoop(),
        );
    }

    #[Test]
    public function without_a_buffer_entries_are_saved_directly(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('save');

        (new SelfLogSink($storage))->write($this->entry());
    }

    #[Test]
    public function with_a_buffer_entries_are_queued(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('save');
        $buffer  = $this->buffer(new FakeBatchWriter());

        (new SelfLogSink($storage, null, $buffer))->write($this->entry());

        self::assertSame(1, $buffer->pending());
    }

    #[Test]
    public function a_full_buffer_is_reported(): void
    {
        $writer             = new FakeBatchWriter();
        $writer->holdWrites = true; // the full batch stays in flight
        $buffer             = $this->buffer($writer, capacity: 5);
        $buffer->enqueue([$this->entry('A'), $this->entry('B'), $this->entry('C'), $this->entry('D')]);
        $buffer->enqueue([$this->entry('E')]);

        $this->expectException(\RuntimeException::class);

        (new SelfLogSink($this->createStub(StorageInterface::class), null, $buffer))->write($this->entry('F'));
    }

    #[Test]
    public function while_the_buffer_drains_entries_are_saved_directly(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('save');
        $buffer  = $this->buffer(new FakeBatchWriter());
        $buffer->drain(1.0);

        (new SelfLogSink($storage, null, $buffer))->write($this->entry());
    }

    #[Test]
    public function write_now_bypasses_the_buffer(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('save');
        $buffer  = $this->buffer(new FakeBatchWriter());

        (new SelfLogSink($storage, null, $buffer))->writeNow($this->entry());

        self::assertSame(0, $buffer->pending());
    }
}
