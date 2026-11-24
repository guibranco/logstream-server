<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Models\LogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps migrations/clickhouse in step with the LogEntry model, without a
 * ClickHouse server (CI's ClickHouse job checks the schema on a live one).
 */
final class ClickHouseSchemaTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../migrations/clickhouse/001_log_entries.sql';

    /** @return array<string, string> column → type, in declaration order */
    private static function columns(): array
    {
        $sql = (string) file_get_contents(self::SCHEMA);
        preg_match('/CREATE TABLE IF NOT EXISTS log_entries\s*\((.*)\)\s*(?:--[^\n]*\n\s*)*ENGINE/s', $sql, $body);
        self::assertNotEmpty($body, 'log_entries definition not found');

        $columns = [];
        foreach (explode("\n", $body[1]) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '--') || str_starts_with($line, 'INDEX ')) {
                continue;
            }
            if (preg_match('/^([a-z_]+)\s+(.+?),?$/', $line, $m)) {
                $columns[$m[1]] = $m[2];
            }
        }

        return $columns;
    }

    #[Test]
    public function has_a_column_for_every_log_entry_field(): void
    {
        $entry = LogEntry::fromArray([
            'id' => '01JDXS5G8QW3Z6V9N2M4K7H1PA', 'trace_id' => 't', 'batch_id' => null,
            'app_key' => 'a', 'app_id' => 'b', 'user_agent' => null, 'level' => 'info',
            'message' => 'm',
        ]);

        $fields  = array_keys($entry->toArray());
        $columns = array_keys(self::columns());
        sort($fields);
        sort($columns);

        self::assertSame($fields, $columns);
    }

    #[Test]
    public function level_enum_lists_every_level_in_severity_order(): void
    {
        preg_match_all("/'([a-z]+)' = (\\d)/", self::columns()['level'], $m);

        self::assertSame(LogEntry::LEVELS, $m[1]);
        self::assertSame(range(1, count(LogEntry::LEVELS)), array_map('intval', $m[2]));
    }

    #[Test]
    public function optional_fields_are_nullable_and_required_ones_are_not(): void
    {
        $columns = self::columns();

        foreach (['batch_id', 'user_agent', 'sender_ip', 'transport', 'message_template', 'context'] as $optional) {
            self::assertStringContainsString('Nullable(', $columns[$optional], $optional);
        }
        foreach (['id', 'trace_id', 'app_key', 'app_id', 'level', 'category', 'message', 'timestamp', 'created_at'] as $required) {
            self::assertStringNotContainsString('Nullable(', $columns[$required], $required);
        }
    }

    #[Test]
    public function timestamps_keep_microseconds_in_utc(): void
    {
        $columns = self::columns();

        self::assertStringStartsWith("DateTime64(6, 'UTC')", $columns['timestamp']);
        self::assertStringStartsWith("DateTime64(6, 'UTC')", $columns['created_at']);
    }
}
