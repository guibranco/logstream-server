<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Models\LogEntry;
use React\Promise\PromiseInterface;

/**
 * Persists a batch of entries for the {@see IngestBuffer}.
 *
 * A batch is all-or-nothing: the promise fulfils when every entry is stored
 * and rejects when none should be considered stored. Writing an id that is
 * already stored must succeed without duplicating it, because a batch may be
 * retried after the storage committed it.
 */
interface BatchWriterInterface
{
    /**
     * @param  list<LogEntry> $entries
     * @return PromiseInterface<null>
     */
    public function write(array $entries): PromiseInterface;

    /** Short name shown in /api/health and /api/info, e.g. "async-mysql". */
    public function name(): string;
}
