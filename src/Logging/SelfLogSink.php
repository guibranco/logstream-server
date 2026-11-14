<?php

declare(strict_types=1);

namespace LogService\Logging;

use LogService\Ingest\IngestBuffer;
use LogService\Models\LogEntry;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;

/**
 * Stores the server's own entries the way POST /api/logs stores client ones:
 * queued in the ingest buffer (which broadcasts them once written) or, in
 * sync mode and while the buffer drains at shutdown, written directly and
 * broadcast.
 */
final class SelfLogSink implements SelfLogSinkInterface
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?LogHub          $hub    = null,
        private readonly ?IngestBuffer    $buffer = null,
    ) {}

    public function write(LogEntry $entry): void
    {
        if ($this->buffer !== null && $this->buffer->isAccepting()) {
            if ($this->buffer->enqueue([$entry]) === null) {
                throw new \RuntimeException('ingest buffer is full');
            }
            return;
        }

        $this->writeNow($entry);
    }

    public function writeNow(LogEntry $entry): void
    {
        $this->storage->save($entry);

        try {
            $this->hub?->broadcast($entry);
        } catch (\Throwable) {
            // The entry is stored; a failed live broadcast is not worth reporting.
        }
    }
}
