<?php

declare(strict_types=1);

namespace LogService\Logging;

use LogService\Models\LogEntry;

/** Where the {@see ServerLogger} stores its entries. */
interface SelfLogSinkInterface
{
    /** Store through the normal ingest path (buffered when the buffer is in use). */
    public function write(LogEntry $entry): void;

    /** Store right away, bypassing the buffer — for fatal errors and shutdown. */
    public function writeNow(LogEntry $entry): void;
}
