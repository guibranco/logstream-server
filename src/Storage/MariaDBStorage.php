<?php

declare(strict_types=1);

namespace LogService\Storage;

use LogService\Models\LogEntry;

final class MariaDBStorage implements StorageInterface
{
    private \PDO $pdo;

    public function __construct(string $dsn, string $user, string $password)
    {
        $this->pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function save(LogEntry $entry): void
    {
        $sql = <<<SQL
            INSERT INTO log_entries
                (id, trace_id, batch_id, app_key, app_id,
                 user_agent, level, category, message, context, timestamp)
            VALUES
                (:id, :trace_id, :batch_id, :app_key, :app_id,
                 :user_agent, :level, :category, :message, :context, :timestamp)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'         => $entry->id,
            ':trace_id'   => $entry->traceId,
            ':batch_id'   => $entry->batchId,
            ':app_key'    => $entry->appKey,
            ':app_id'     => $entry->appId,
            ':user_agent' => $entry->userAgent,
            ':level'      => $entry->level,
            ':category'   => $entry->category,
            ':message'    => $entry->message,
            ':context'    => $entry->context !== null ? json_encode($entry->context) : null,
            ':timestamp'  => $entry->timestamp->format('Y-m-d H:i:s.u'),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────

    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Data query
        $sql = "SELECT * FROM log_entries {$whereClause} ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit',  $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Count query
        $countSql  = "SELECT COUNT(*) FROM log_entries {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
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
        $stmt = $this->pdo->prepare(
            "SELECT * FROM log_entries WHERE id = :id OR trace_id = :trace_id LIMIT 1"
        );
        $stmt->execute([':id' => $id, ':trace_id' => $id]);
        $row = $stmt->fetch();

        return $row ? LogEntry::fromArray($row) : null;
    }

    // ──────────────────────────────────────────────────────────────────────────

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
        ];

        foreach ($exact as $col => $placeholder) {
            if (!empty($filters[$col])) {
                $where[]              = "{$col} = {$placeholder}";
                $params[$placeholder] = $filters[$col];
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
