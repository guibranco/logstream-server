<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Models\LogEntry;
use LogService\Storage\FileStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileStorageTest extends TestCase
{
    private string $tmpDir;
    private FileStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/logservice_test_' . uniqid();
        $this->storage = new FileStorage($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // save()
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_the_base_directory_on_construction(): void
    {
        self::assertDirectoryExists($this->tmpDir);
    }

    #[Test]
    public function it_writes_a_jsonl_file_for_each_day(): void
    {
        $entry = $this->makeEntry('01AAA');
        $this->storage->save($entry);

        $expected = sprintf(
            '%s/%s/%s/%s.jsonl',
            $this->tmpDir,
            $entry->timestamp->format('Y'),
            $entry->timestamp->format('m'),
            $entry->timestamp->format('d'),
        );

        self::assertFileExists($expected);
    }

    #[Test]
    public function it_appends_multiple_entries_to_the_same_day_file(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';

        $this->storage->save($this->makeEntry('01AAA', timestamp: $ts));
        $this->storage->save($this->makeEntry('01BBB', timestamp: $ts));
        $this->storage->save($this->makeEntry('01CCC', timestamp: $ts));

        $file  = $this->tmpDir . '/2024/03/01.jsonl';
        $lines = array_filter(explode("\n", trim(file_get_contents($file))));

        self::assertCount(3, $lines);
    }

    #[Test]
    public function it_writes_valid_json_per_line(): void
    {
        $entry = $this->makeEntry('01AAA');
        $this->storage->save($entry);

        $file  = $this->tmpDir . '/' . $entry->timestamp->format('Y/m/d') . '.jsonl';
        $line  = trim(file_get_contents($file));
        $data  = json_decode($line, true);

        self::assertIsArray($data);
        self::assertSame($entry->id,      $data['id']);
        self::assertSame($entry->message, $data['message']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // findById()
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_finds_an_entry_by_id(): void
    {
        $entry = $this->makeEntry('01FINDME');
        $this->storage->save($entry);

        $found = $this->storage->findById($entry->id);

        self::assertNotNull($found);
        self::assertSame($entry->id, $found->id);
    }

    #[Test]
    public function it_finds_an_entry_by_trace_id(): void
    {
        $entry = $this->makeEntry('01TRACE', traceId: 'my-trace-uuid-1234');
        $this->storage->save($entry);

        $found = $this->storage->findById('my-trace-uuid-1234');

        self::assertNotNull($found);
        self::assertSame($entry->traceId, $found->traceId);
    }

    #[Test]
    public function it_returns_null_when_entry_not_found(): void
    {
        $found = $this->storage->findById('nonexistent-id');
        self::assertNull($found);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // search() — exact filters
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_all_entries_with_no_filters(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        $this->storage->save($this->makeEntry('01A', timestamp: $ts));
        $this->storage->save($this->makeEntry('01B', timestamp: $ts));
        $this->storage->save($this->makeEntry('01C', timestamp: $ts));

        $result = $this->storage->search([]);

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['entries']);
    }

    #[Test]
    public function it_filters_by_app_key(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        $this->storage->save($this->makeEntry('01A', appKey: 'api-one', timestamp: $ts));
        $this->storage->save($this->makeEntry('01B', appKey: 'api-two', timestamp: $ts));
        $this->storage->save($this->makeEntry('01C', appKey: 'api-one', timestamp: $ts));

        $result = $this->storage->search(['app_key' => 'api-one']);

        self::assertSame(2, $result['total']);
        foreach ($result['entries'] as $e) {
            self::assertSame('api-one', $e['app_key']);
        }
    }

    #[Test]
    public function it_filters_by_level(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        $this->storage->save($this->makeEntry('01A', level: 'error',   timestamp: $ts));
        $this->storage->save($this->makeEntry('01B', level: 'info',    timestamp: $ts));
        $this->storage->save($this->makeEntry('01C', level: 'error',   timestamp: $ts));
        $this->storage->save($this->makeEntry('01D', level: 'warning', timestamp: $ts));

        $result = $this->storage->search(['level' => 'error']);

        self::assertSame(2, $result['total']);
    }

    #[Test]
    public function it_filters_by_trace_id(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        $this->storage->save($this->makeEntry('01A', traceId: 'trace-aaa', timestamp: $ts));
        $this->storage->save($this->makeEntry('01B', traceId: 'trace-bbb', timestamp: $ts));

        $result = $this->storage->search(['trace_id' => 'trace-aaa']);

        self::assertSame(1, $result['total']);
        self::assertSame('trace-aaa', $result['entries'][0]['trace_id']);
    }

    #[Test]
    public function it_filters_by_batch_id(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        $this->storage->save($this->makeEntry('01A', batchId: 'batch-x', timestamp: $ts));
        $this->storage->save($this->makeEntry('01B', batchId: 'batch-x', timestamp: $ts));
        $this->storage->save($this->makeEntry('01C', batchId: 'batch-y', timestamp: $ts));

        $result = $this->storage->search(['batch_id' => 'batch-x']);

        self::assertSame(2, $result['total']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // search() — partial / LIKE filters
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_filters_by_partial_category(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        $this->storage->save($this->makeEntry('01A', category: 'payments.charge', timestamp: $ts));
        $this->storage->save($this->makeEntry('01B', category: 'auth.login',      timestamp: $ts));

        $result = $this->storage->search(['category' => 'payments']);

        self::assertSame(1, $result['total']);
    }

    #[Test]
    public function it_filters_by_message_substring(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        $this->storage->save($this->makeEntry('01A', message: 'User logged in',   timestamp: $ts));
        $this->storage->save($this->makeEntry('01B', message: 'Charge failed',    timestamp: $ts));
        $this->storage->save($this->makeEntry('01C', message: 'User logged out',  timestamp: $ts));

        $result = $this->storage->search(['search' => 'User logged']);

        self::assertSame(2, $result['total']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // search() — date range
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_filters_by_date_from(): void
    {
        $this->storage->save($this->makeEntry('01A', timestamp: '2024-01-01T00:00:00.000Z'));
        $this->storage->save($this->makeEntry('01B', timestamp: '2024-06-01T00:00:00.000Z'));
        $this->storage->save($this->makeEntry('01C', timestamp: '2024-12-01T00:00:00.000Z'));

        $result = $this->storage->search(['date_from' => '2024-06-01T00:00:00.000Z']);

        self::assertSame(2, $result['total']);
    }

    #[Test]
    public function it_filters_by_date_to(): void
    {
        $this->storage->save($this->makeEntry('01A', timestamp: '2024-01-01T00:00:00.000Z'));
        $this->storage->save($this->makeEntry('01B', timestamp: '2024-06-01T00:00:00.000Z'));
        $this->storage->save($this->makeEntry('01C', timestamp: '2024-12-01T00:00:00.000Z'));

        $result = $this->storage->search(['date_to' => '2024-06-01T23:59:59.000Z']);

        self::assertSame(2, $result['total']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // search() — pagination
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_respects_limit_and_offset(): void
    {
        $ts = '2024-03-01T10:00:00.000Z';
        foreach (range(1, 10) as $i) {
            $this->storage->save($this->makeEntry(sprintf('%03d', $i), timestamp: $ts));
        }

        $page1 = $this->storage->search([], 3, 0);
        $page2 = $this->storage->search([], 3, 3);

        self::assertSame(10, $page1['total']);
        self::assertCount(3, $page1['entries']);
        self::assertCount(3, $page2['entries']);

        // Pages must not overlap
        $ids1 = array_column($page1['entries'], 'id');
        $ids2 = array_column($page2['entries'], 'id');
        self::assertEmpty(array_intersect($ids1, $ids2));
    }

    #[Test]
    public function it_returns_entries_newest_first(): void
    {
        $this->storage->save($this->makeEntry('01A', timestamp: '2024-01-01T00:00:00.000Z'));
        $this->storage->save($this->makeEntry('01B', timestamp: '2024-03-01T00:00:00.000Z'));
        $this->storage->save($this->makeEntry('01C', timestamp: '2024-06-01T00:00:00.000Z'));

        $result = $this->storage->search([]);

        $timestamps = array_column($result['entries'], 'timestamp');

        self::assertGreaterThan($timestamps[1], $timestamps[0]);
        self::assertGreaterThan($timestamps[2], $timestamps[1]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeEntry(
        string $id,
        string $appKey    = 'test-app',
        string $appId     = 'test',
        string $level     = 'info',
        string $category  = 'general',
        string $message   = 'Test message',
        ?string $batchId  = null,
        string $traceId   = 'trace-default',
        string $timestamp = '2024-03-01T10:00:00.000Z',
    ): LogEntry {
        return LogEntry::fromArray([
            'id'         => $id,
            'trace_id'   => $traceId,
            'batch_id'   => $batchId,
            'app_key'    => $appKey,
            'app_id'     => $appId,
            'user_agent' => 'TestClient/1.0',
            'level'      => $level,
            'category'   => $category,
            'message'    => $message,
            'context'    => null,
            'timestamp'  => $timestamp,
            'created_at' => $timestamp,
        ]);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
