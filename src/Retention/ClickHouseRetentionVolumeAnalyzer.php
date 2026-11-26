<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseException;
use LogService\Storage\ClickHouseStorage;

/**
 * The Seq-style volume report for the ClickHouse log store, in the same
 * shape as {@see DatabaseRetentionVolumeAnalyzer} so the UI needs no change:
 *
 *   - per UTC day: entries and payload bytes (message, template and context
 *     lengths), what each policy's filters match (m{i}) and would delete now
 *     (d{i}), and what any policy would delete (due, so overlapping policies
 *     are counted once) — one SELECT over log_entries FINAL;
 *   - storage: the table's real size from system.parts, compressed data vs
 *     marks, primary key and skip indexes.
 */
final class ClickHouseRetentionVolumeAnalyzer implements RetentionVolumeAnalyzerInterface
{
    private const BYTES_EXPR = "length(message) + length(ifNull(message_template, '')) + length(ifNull(context, ''))";

    public function __construct(private readonly ClickHouseClient $client)
    {
    }

    public function analyze(array $policies, int $days): RetentionVolumeReport
    {
        $active  = array_values(array_filter($policies, static fn(RetentionPolicy $p) => !$p->isEmpty()));
        $builder = new ClickHouseRetentionConditions();

        $inner  = ['toString(toDate(timestamp)) AS day', self::BYTES_EXPR . ' AS b'];
        $outer  = ['day', 'count() AS cnt', 'sum(b) AS bytes', 'countIf(due) AS due_cnt', 'sumIf(b, due) AS due_bytes'];
        $dueAny = [];

        foreach ($active as $i => $policy) {
            $policy  = $policy->frozen();
            $inner[] = self::all($builder->build($policy, includeCutoff: false)) . " AS m{$i}";
            $inner[] = self::all($builder->build($policy)) . " AS d{$i}";
            $dueAny[] = "d{$i}";

            $outer[] = "countIf(m{$i}) AS m{$i}_cnt";
            $outer[] = "sumIf(b, m{$i}) AS m{$i}_bytes";
            $outer[] = "countIf(d{$i}) AS d{$i}_cnt";
            $outer[] = "sumIf(b, d{$i}) AS d{$i}_bytes";
        }

        $inner[] = ($dueAny === [] ? '0' : '(' . implode(' OR ', $dueAny) . ')') . ' AS due';

        $rows = $this->client->select(
            'SELECT ' . implode(', ', $outer)
            . ' FROM (SELECT ' . implode(', ', $inner) . ' FROM ' . ClickHouseStorage::TABLE . ' FINAL)'
            . ' GROUP BY day',
            $builder->params(),
        );

        return RetentionVolumeReport::fromDailyTotals(
            $policies,
            RetentionVolumeReport::dailyFromAggregateRows($active, $rows),
            $days,
            RetentionVolumeReport::SIZE_BASIS_PAYLOAD,
            storage: $this->tableStorage(),
        );
    }

    /**
     * On-disk size of log_entries from its active parts: compressed column
     * data as "data"; marks, primary key and skip indexes as "index".
     * ClickHouse has no allocated-but-free space to report. Null when
     * system.parts can't be read; the chart then has no breakdown.
     *
     * @return array{table: string, engine: ?string, data_bytes: int, index_bytes: int, free_bytes: int, total_bytes: int, rows_estimate: int}|null
     */
    private function tableStorage(): ?array
    {
        try {
            $row = $this->client->select(
                'SELECT sum(data_compressed_bytes) AS data_bytes,'
                . ' sum(marks_bytes + primary_key_size + secondary_indices_compressed_bytes) AS index_bytes,'
                . ' sum(rows) AS table_rows,'
                . " (SELECT engine FROM system.tables WHERE database = currentDatabase() AND name = {table:String}) AS engine"
                . ' FROM system.parts WHERE database = currentDatabase() AND table = {table:String} AND active',
                ['table' => ClickHouseStorage::TABLE],
            )[0] ?? null;
        } catch (ClickHouseException $e) {
            error_log('[Retention] Could not read table size from system.parts: ' . $e->getMessage());
            return null;
        }

        if ($row === null) {
            return null;
        }

        $data  = (int) ($row['data_bytes'] ?? 0);
        $index = (int) ($row['index_bytes'] ?? 0);

        return [
            'table'         => ClickHouseStorage::TABLE,
            'engine'        => isset($row['engine']) && $row['engine'] !== '' ? (string) $row['engine'] : null,
            'data_bytes'    => $data,
            'index_bytes'   => $index,
            'free_bytes'    => 0,
            'total_bytes'   => $data + $index,
            'rows_estimate' => (int) ($row['table_rows'] ?? 0),
        ];
    }

    /**
     * 1 when every condition holds, else 0 — never NULL, even when a condition
     * reads a nullable column (transport, message_template).
     *
     * @param list<string> $conditions
     */
    private static function all(array $conditions): string
    {
        return $conditions === [] ? '1' : 'ifNull((' . implode(' AND ', $conditions) . '), 0)';
    }
}
