<?php

declare(strict_types=1);

namespace LogService\Storage;

use LogService\Models\LogEntry;

interface StorageInterface
{
    public function save(LogEntry $entry): void;

    /**
     * Persist several entries at once (used by the buffered ingest path).
     * Implementations should write them in as few operations as possible.
     *
     * @param list<LogEntry> $entries
     */
    public function saveMany(array $entries): void;

    /**
     * Search log entries, newest first.
     *
     * @return array{total: int, limit: int, offset: int, entries: list<array>}
     */
    public function search(SearchFilters $filters, int $limit = 100, int $offset = 0): array;

    /** Find by internal ID or trace_id */
    public function findById(string $id): ?LogEntry;
}
