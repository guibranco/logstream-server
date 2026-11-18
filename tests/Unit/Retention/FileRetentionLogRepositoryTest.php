<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\FileRetentionLogRepository;
use LogService\Retention\RetentionResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileRetentionLogRepositoryTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir  = sys_get_temp_dir() . '/retention-log-' . bin2hex(random_bytes(4));
        $this->path = $this->dir . '/nested/retention_log.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        @rmdir($this->dir);
    }

    #[Test]
    public function recent_is_empty_when_nothing_was_recorded(): void
    {
        $repo = new FileRetentionLogRepository($this->path);

        self::assertSame([], $repo->recent(new \DateTimeImmutable('-90 days')));
    }

    #[Test]
    public function it_records_runs_and_reads_them_back_newest_first(): void
    {
        $repo = new FileRetentionLogRepository($this->path);
        $repo->record(new RetentionResult(policy: 'debug-7d', pruned: 42, durationMs: 15));
        $repo->record(new RetentionResult(
            policy:   'broken',
            pruned:   0,
            error:    'boom',
            dryRun:   true,
            summary:  'Error: boom',
            warnings: ['careful'],
        ));

        $entries = $repo->recent(new \DateTimeImmutable('-1 day'));

        self::assertCount(2, $entries);
        self::assertSame('broken', $entries[0]->policy);
        self::assertSame(2, $entries[0]->id);
        self::assertTrue($entries[0]->dryRun);
        self::assertSame(['careful'], $entries[0]->warnings);
        self::assertSame('boom', $entries[0]->error);
        self::assertSame('debug-7d', $entries[1]->policy);
        self::assertSame(42, $entries[1]->deleted);
        self::assertSame('42 entries pruned', $entries[1]->summary);
    }

    #[Test]
    public function recent_filters_by_policy_date_and_limit(): void
    {
        mkdir(dirname($this->path), 0775, true);
        file_put_contents($this->path, implode("\n", [
            json_encode(['id' => 1, 'policy_name' => 'a', 'deleted' => 1, 'ran_at' => '2020-01-01 00:00:00']),
            'not json',
            json_encode(['id' => 2, 'policy_name' => 'a', 'deleted' => 2, 'ran_at' => gmdate('Y-m-d H:i:s', time() - 60)]),
            json_encode(['id' => 3, 'policy_name' => 'b', 'deleted' => 3, 'ran_at' => gmdate('Y-m-d H:i:s', time() - 30)]),
            json_encode(['id' => 4, 'policy_name' => 'a', 'deleted' => 4, 'ran_at' => gmdate('Y-m-d H:i:s')]),
        ]) . "\n");

        $repo = new FileRetentionLogRepository($this->path);

        self::assertSame([4, 3, 2], array_map(fn($e) => $e->id, $repo->recent(new \DateTimeImmutable('-1 day'))));
        self::assertSame([4, 2], array_map(fn($e) => $e->id, $repo->recent(new \DateTimeImmutable('-1 day'), 'a')));
        self::assertSame([4], array_map(fn($e) => $e->id, $repo->recent(new \DateTimeImmutable('-1 day'), 'a', 1)));
    }

    #[Test]
    public function recording_drops_lines_older_than_the_keep_window(): void
    {
        mkdir(dirname($this->path), 0775, true);
        file_put_contents($this->path, json_encode([
            'id' => 7, 'policy_name' => 'ancient', 'ran_at' => '2020-01-01 00:00:00',
        ]) . "\n");

        $repo = new FileRetentionLogRepository($this->path, keepDays: 30);
        $repo->record(new RetentionResult(policy: 'fresh', pruned: 1));

        $lines = array_filter(explode("\n", (string) file_get_contents($this->path)));
        self::assertCount(1, $lines);

        $row = json_decode((string) reset($lines), true);
        self::assertSame('fresh', $row['policy_name']);
        // Ids keep increasing even after old lines are dropped.
        self::assertSame(8, $row['id']);
    }
}
