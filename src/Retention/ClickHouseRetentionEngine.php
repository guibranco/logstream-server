<?php

declare(strict_types=1);

namespace LogService\Retention;

use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouseStorage;

/**
 * Retention for the ClickHouse log store (both the engine behind the runner
 * and the pruner behind bin/prune.php).
 *
 *   - Age-only policy ("everything older than N days"): each daily partition
 *     that ends before the cutoff is dropped whole (instant, no rewrite), then
 *     a lightweight DELETE removes what is left before the cutoff on the
 *     boundary day, if anything.
 *   - Any field filter: a lightweight DELETE with the compiled conditions.
 *   - Dry run: only the count, never a write.
 *
 * The reported number is the count of matching rows taken just before the
 * delete. Like DatabasePruner, an empty policy does nothing.
 */
final class ClickHouseRetentionEngine implements RetentionEngineInterface, PrunerInterface
{
    public function __construct(private readonly ClickHouseClient $client)
    {
    }

    public function purge(RetentionPolicy $policy, bool $dryRun = false): RetentionResult
    {
        $start = hrtime(true);

        if ($policy->isEmpty()) {
            return new RetentionResult(
                policy:     $policy->name,
                pruned:     0,
                dryRun:     $dryRun,
                durationMs: 0,
                summary:    'Policy is empty — nothing to do',
            );
        }

        $count = $dryRun ? $this->count($policy) : $this->delete($policy);

        $prefix = $dryRun ? '[dry-run] ' : '';

        return new RetentionResult(
            policy:     $policy->name,
            pruned:     $count,
            dryRun:     $dryRun,
            durationMs: (int) ((hrtime(true) - $start) / 1_000_000),
            summary:    "{$prefix}{$count} entries pruned",
        );
    }

    public function prune(RetentionPolicy $policy): int
    {
        return $policy->isEmpty() ? 0 : $this->delete($policy);
    }

    /** Rows the policy would delete now. */
    private function count(RetentionPolicy $policy): int
    {
        [$where, $params] = self::where($policy->frozen());

        return (int) $this->client->value('SELECT count() FROM ' . ClickHouseStorage::TABLE . " WHERE {$where}", $params);
    }

    /** Delete what the policy matches; returns how many rows that was. */
    private function delete(RetentionPolicy $policy): int
    {
        // One cutoff for the count, the partition drops and the DELETE.
        $policy = $policy->frozen();
        $count  = $this->count($policy);
        if ($count === 0) {
            return 0;
        }

        $cutoff = $policy->getCutoffDate();
        if ($cutoff !== null && !$policy->hasFieldFilters()) {
            $this->dropPartitionsBefore(new \DateTimeImmutable($cutoff->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')));

            if ($this->count($policy) === 0) {
                return $count;   // the cutoff fell on a day boundary: nothing left
            }
        }

        [$where, $params] = self::where($policy);
        $this->client->request(
            'DELETE FROM ' . ClickHouseStorage::TABLE . " WHERE {$where}",
            $params,
            // Return once the rows are gone, so the next count and search agree.
            ['lightweight_deletes_sync' => '2'],
        );

        return $count;
    }

    /** Drop every daily partition whose whole day is before $cutoff (UTC). */
    private function dropPartitionsBefore(\DateTimeImmutable $cutoff): void
    {
        $partitions = $this->client->select(
            'SELECT DISTINCT partition FROM system.parts '
            . "WHERE database = currentDatabase() AND table = {table:String} AND active ORDER BY partition",
            ['table' => ClickHouseStorage::TABLE],
        );

        foreach ($partitions as $row) {
            $partition = (string) $row['partition'];
            $day       = \DateTimeImmutable::createFromFormat('!Ymd', $partition, new \DateTimeZone('UTC'));
            // toYYYYMMDD partitions are 8 digits; anything else isn't ours to drop.
            if (!preg_match('/^\d{8}$/', $partition) || $day === false || $day->modify('+1 day') > $cutoff) {
                continue;
            }

            $this->client->request('ALTER TABLE ' . ClickHouseStorage::TABLE . " DROP PARTITION {$partition}");
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function where(RetentionPolicy $policy): array
    {
        $builder    = new ClickHouseRetentionConditions();
        $conditions = $builder->build($policy);

        return [implode(' AND ', $conditions), $builder->params()];
    }
}
