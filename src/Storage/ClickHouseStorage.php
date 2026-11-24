<?php

declare(strict_types=1);

namespace LogService\Storage;

use LogService\Models\LogEntry;
use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseQuery;

/**
 * Log entries in ClickHouse (LOG_STORE=clickhouse); schema in
 * migrations/clickhouse/001_log_entries.sql.
 *
 * Reads use FINAL, so an entry written twice (a retried batch) is returned
 * once even before ClickHouse merges the duplicates away.
 */
final class ClickHouseStorage implements StorageInterface, ReportsHealth
{
    public const TABLE = 'log_entries';

    /** Columns written and read, in schema order (created_at defaults on insert). */
    public const COLUMNS = [
        'id', 'trace_id', 'batch_id', 'app_key', 'app_id',
        'user_agent', 'sender_ip', 'transport', 'level', 'category', 'message', 'message_template',
        'context', 'timestamp', 'created_at',
    ];

    public function __construct(private readonly ClickHouseClient $client)
    {
    }

    public function save(LogEntry $entry): void
    {
        $this->saveMany([$entry]);
    }

    /** @param list<LogEntry> $entries */
    public function saveMany(array $entries): void
    {
        $this->client->insert(self::TABLE, self::COLUMNS, array_map(self::row(...), $entries));
    }

    /**
     * One entry as a JSONEachRow object. Same values as MariaDBStorage::row():
     * UTC timestamps with microseconds, context as JSON text (here without
     * \u escapes, so context_search sees the real characters).
     *
     * @return array<string, string|null>
     */
    public static function row(LogEntry $entry): array
    {
        $values = MariaDBStorage::row($entry);
        $row    = array_combine(MariaDBStorage::INSERT_COLUMNS, $values);

        $row['context'] = $entry->context !== null
            ? json_encode($entry->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        return array_intersect_key($row, array_flip(self::COLUMNS));
    }

    public function search(SearchFilters $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = ClickHouseQuery::where($filters);
        $whereClause = $where !== [] ? 'WHERE ' . implode("\n  AND ", $where) : '';
        $columns     = implode(', ', self::COLUMNS);

        $rows = $this->client->select(
            "SELECT {$columns} FROM " . self::TABLE . " FINAL\n{$whereClause}\n"
            . 'ORDER BY timestamp DESC, id DESC LIMIT {limit:UInt32} OFFSET {offset:UInt32}',
            $params + ['limit' => max(0, $limit), 'offset' => max(0, $offset)],
        );

        $total = (int) $this->client->value(
            'SELECT count() FROM ' . self::TABLE . " FINAL\n{$whereClause}",
            $params,
        );

        return [
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'entries' => array_map(static fn(array $row): array => LogEntry::fromArray($row)->toArray(), $rows),
        ];
    }

    /** By id first, then by trace_id (the newest entry of that trace). */
    public function findById(string $id): ?LogEntry
    {
        $columns = implode(', ', self::COLUMNS);

        $rows = $this->client->select(
            "SELECT {$columns} FROM " . self::TABLE . ' FINAL WHERE id = {id:String} OR trace_id = {id:String} '
            . 'ORDER BY id = {id:String} DESC, timestamp DESC LIMIT 1',
            ['id' => $id],
        );

        return $rows === [] ? null : LogEntry::fromArray($rows[0]);
    }

    public function health(): array
    {
        $start     = hrtime(true);
        $reachable = $this->client->ping();

        return [
            'type'       => 'clickhouse',
            'reachable'  => $reachable,
            'latency_ms' => $reachable ? round((hrtime(true) - $start) / 1e6, 1) : null,
        ];
    }
}
