<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Database\Connection;

/**
 * Aggregates log_entries by DATE(timestamp) in one statement.
 *
 * Per row, the inner query flags which policies' filters match (m{i}) and
 * which policies would delete it now (d{i}); the middle query ORs the d{i}
 * flags so overlapping policies are not counted twice; the outer query sums
 * per day. Each placeholder gets its own suffix because native prepared
 * statements do not allow a named parameter to appear twice.
 *
 * Bytes are an approximation: the length of message, message_template and
 * context, not the on-disk row size.
 */
final class DatabaseRetentionVolumeAnalyzer implements RetentionVolumeAnalyzerInterface
{
    private const BYTES_EXPR = '(COALESCE(LENGTH(message), 0) + COALESCE(LENGTH(message_template), 0) + COALESCE(LENGTH(context), 0))';

    private const FLAG_OPEN = 'CASE WHEN ';
    private const FLAG_CLOSE = ' THEN 1 ELSE 0 END';

    public function __construct(private readonly Connection $db) {}

    public function analyze(array $policies, int $days): RetentionVolumeReport
    {
        $active = array_values(array_filter($policies, fn(RetentionPolicy $p) => !$p->isEmpty()));

        $inner  = ['DATE(timestamp) AS day', self::BYTES_EXPR . ' AS b'];
        $outer  = ['day', 'COUNT(*) AS cnt', 'SUM(b) AS bytes', 'SUM(due) AS due_cnt', 'SUM(due * b) AS due_bytes'];
        $params = [];
        $dueAny = [];

        foreach ($active as $i => $policy) {
            [$matchConds, $matchParams] = DatabaseConditionBuilder::build($policy, "_m{$i}", includeCutoff: false);
            [$dueConds, $dueParams]     = DatabaseConditionBuilder::build($policy, "_d{$i}");

            $inner[] = self::flag(self::all($matchConds)) . " AS m{$i}";
            $inner[] = self::flag(self::all($dueConds)) . " AS d{$i}";
            $dueAny[] = "d{$i} = 1";

            $outer[] = "SUM(m{$i}) AS m{$i}_cnt";
            $outer[] = "SUM(m{$i} * b) AS m{$i}_bytes";
            $outer[] = "SUM(d{$i}) AS d{$i}_cnt";
            $outer[] = "SUM(d{$i} * b) AS d{$i}_bytes";

            $params += $matchParams + $dueParams;
        }

        $dueExpr = $dueAny === [] ? '0' : self::flag(implode(' OR ', $dueAny));

        $sql = 'SELECT ' . implode(', ', $outer)
             . ' FROM (SELECT t.*, ' . $dueExpr . ' AS due'
             . ' FROM (SELECT ' . implode(', ', $inner) . ' FROM log_entries) t) u'
             . ' GROUP BY day';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        $daily = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $stats = [];
            foreach ($active as $i => $policy) {
                $stats[$policy->name] = [
                    'matched_count' => (int) $row["m{$i}_cnt"],
                    'matched_bytes' => (int) $row["m{$i}_bytes"],
                    'due_count'     => (int) $row["d{$i}_cnt"],
                    'due_bytes'     => (int) $row["d{$i}_bytes"],
                ];
            }

            $daily[(string) $row['day']] = [
                'count'     => (int) $row['cnt'],
                'bytes'     => (int) $row['bytes'],
                'due_count' => (int) $row['due_cnt'],
                'due_bytes' => (int) $row['due_bytes'],
                'policies'  => $stats,
            ];
        }

        return RetentionVolumeReport::fromDailyTotals(
            $policies,
            $daily,
            $days,
            RetentionVolumeReport::SIZE_BASIS_PAYLOAD,
        );
    }

    /** 1 when the condition holds for the row, else 0. */
    private static function flag(string $condition): string
    {
        return self::FLAG_OPEN . $condition . self::FLAG_CLOSE;
    }

    /** @param string[] $conditions */
    private static function all(array $conditions): string
    {
        return $conditions === [] ? '1 = 1' : '(' . implode(' AND ', $conditions) . ')';
    }
}
