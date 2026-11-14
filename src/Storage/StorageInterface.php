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
     * Search log entries.
     *
     * Supported $filters keys:
     *   app_key, app_id, user_agent, level, category,
     *   trace_id, batch_id, sender_ip, date_from, date_to,
     *   search (full-text on message),
     *   app_key, app_id and level may also be a list of strings: an entry
     *   matches when its value is any of them,
     *   context (list<ContextFilter>: equality / inequality on context properties),
     *   context_search (free text across context keys and values)
     *
     * @return array{total: int, limit: int, offset: int, entries: list<array>}
     */
    public function search(array $filters, int $limit = 100, int $offset = 0): array;

    /** Find by internal ID or trace_id */
    public function findById(string $id): ?LogEntry;
}
