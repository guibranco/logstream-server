<?php

declare(strict_types=1);

namespace LogService\Storage;

use LogService\Database\Connection;
use LogService\Models\LogEntry;

final class MariaDBStorage implements StorageInterface
{
    /**
     * @param Connection $db Must run "SET time_zone = '+00:00'" on connect —
     *                       timestamps are stored and compared in UTC.
     */
    public function __construct(private readonly Connection $db) {}

    // ──────────────────────────────────────────────────────────────────────────

    /** Columns written for every entry, in the order of {@see self::row()}. */
    public const INSERT_COLUMNS = [
        'id', 'trace_id', 'batch_id', 'app_key', 'app_id',
        'user_agent', 'sender_ip', 'level', 'category', 'message', 'message_template',
        'context', 'timestamp', 'created_at',
    ];

    public function save(LogEntry $entry): void
    {
        $this->saveMany([$entry]);
    }

    /**
     * Insert all entries with one multi-row INSERT.
     *
     * Re-inserting an id that is already stored is a no-op, so a batch that is
     * retried after the database committed it (e.g. the reply was lost) does
     * not fail on the primary key forever.
     *
     * @param list<LogEntry> $entries
     */
    public function saveMany(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $pdo    = $this->db->pdo();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        $params = [];
        foreach ($entries as $entry) {
            array_push($params, ...self::row($entry));
        }

        $pdo->prepare(self::insertSql(count($entries), $driver))->execute($params);
    }

    /**
     * The INSERT statement for $rows entries, with positional placeholders.
     * Shared with the async writer so both drivers store rows identically.
     */
    public static function insertSql(int $rows, string $driver = 'mysql'): string
    {
        $tuple  = '(' . implode(', ', array_fill(0, count(self::INSERT_COLUMNS), '?')) . ')';
        $values = implode(",\n", array_fill(0, max(1, $rows), $tuple));
        $cols   = implode(', ', self::INSERT_COLUMNS);

        // SQLite backs the unit tests; it has no ON DUPLICATE KEY.
        return $driver === 'sqlite'
            ? "INSERT INTO log_entries ({$cols}) VALUES\n{$values}\nON CONFLICT(id) DO NOTHING"
            : "INSERT INTO log_entries ({$cols}) VALUES\n{$values}\nON DUPLICATE KEY UPDATE id = id";
    }

    /**
     * One entry as positional values in {@see self::INSERT_COLUMNS} order.
     *
     * Dates are converted to UTC here rather than relying on the session time
     * zone, so the value stored is the same whichever connection writes it.
     *
     * @return list<string|null>
     */
    public static function row(LogEntry $entry): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            $entry->id,
            $entry->traceId,
            $entry->batchId,
            $entry->appKey,
            $entry->appId,
            $entry->userAgent,
            $entry->senderIp,
            $entry->level,
            $entry->category,
            $entry->message,
            $entry->messageTemplate,
            $entry->context !== null ? json_encode($entry->context) : null,
            $entry->timestamp->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            $entry->createdAt->setTimezone($utc)->format('Y-m-d H:i:s.u'),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Data query
        $sql = "SELECT * FROM log_entries {$whereClause} ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit',  $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Count query
        $countSql  = "SELECT COUNT(*) FROM log_entries {$whereClause}";
        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($params as $key => $val) {
            $countStmt->bindValue($key, $val);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return [
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'entries' => array_map(fn($row) => LogEntry::fromArray($row)->toArray(), $rows),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function findById(string $id): ?LogEntry
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM log_entries WHERE id = :id OR trace_id = :trace_id LIMIT 1"
        );
        $stmt->execute([':id' => $id, ':trace_id' => $id]);
        $row = $stmt->fetch();

        return $row ? LogEntry::fromArray($row) : null;
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * `col = :p` for one value, `col IN (:p_0, :p_1, …)` for a list
     * (level[]=error&level[]=critical matches any of its values).
     *
     * @param string|list<string>  $value
     * @param array<string, mixed> $params Receives the bound values.
     */
    private function exactCondition(string $col, string $placeholder, string|array $value, array &$params): string
    {
        if (!is_array($value)) {
            $params[$placeholder] = $value;
            return "{$col} = {$placeholder}";
        }

        $placeholders = [];
        foreach (array_values($value) as $i => $item) {
            $name           = "{$placeholder}_{$i}";
            $placeholders[] = $name;
            $params[$name]  = $item;
        }
        return "{$col} IN (" . implode(', ', $placeholders) . ')';
    }

    private function buildWhere(array $filters): array
    {
        $where  = [];
        $params = [];

        $exact = [
            'app_key'  => ':app_key',
            'app_id'   => ':app_id',
            'level'    => ':level',
            'trace_id' => ':trace_id',
            'batch_id' => ':batch_id',
            'sender_ip' => ':sender_ip',
            'message_template' => ':message_template',
        ];

        foreach ($exact as $col => $placeholder) {
            if (!empty($filters[$col])) {
                $where[] = $this->exactCondition($col, $placeholder, $filters[$col], $params);
            }
        }

        // LIKE filters
        if (!empty($filters['user_agent'])) {
            $where[]             = 'user_agent LIKE :user_agent';
            $params[':user_agent'] = '%' . $filters['user_agent'] . '%';
        }
        if (!empty($filters['category'])) {
            $where[]            = 'category LIKE :category';
            $params[':category'] = '%' . $filters['category'] . '%';
        }
        if (!empty($filters['search'])) {
            $where[]          = 'message LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // Context properties: compare the unquoted JSON value as a string.
        // The JSON path is bound as a parameter; ContextFilter quotes every segment.
        foreach ($filters['context'] ?? [] as $i => $contextFilter) {
            $path      = ":ctx_path_{$i}";
            $value     = ":ctx_value_{$i}";
            $extracted = "JSON_UNQUOTE(JSON_EXTRACT(context, {$path}))";

            $where[] = $contextFilter->negate
                ? "(JSON_EXTRACT(context, {$path}) IS NULL OR {$extracted} <> {$value})"
                : "{$extracted} = {$value}";
            $params[$path]  = $contextFilter->jsonPath();
            $params[$value] = $contextFilter->value;
        }

        // Free text across the whole context document (ft_context, migration 006).
        if (!empty($filters['context_search'])) {
            $where[]                   = 'MATCH(context) AGAINST(:context_search IN BOOLEAN MODE)';
            $params[':context_search'] = ContextFilter::fulltextPhrase($filters['context_search']);
        }

        // Date range
        if (!empty($filters['date_from'])) {
            $where[]             = 'timestamp >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]           = 'timestamp <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        return [$where, $params];
    }
}
