<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\RetentionResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetentionResultTest extends TestCase
{
    #[Test]
    public function it_builds_the_minimal_array_with_defaults(): void
    {
        $result = new RetentionResult(policy: 'my-policy', pruned: 5);
        $array  = $result->toArray();

        self::assertSame('my-policy', $array['policy']);
        self::assertSame(5, $array['pruned']);
        self::assertSame(0, $array['files_removed']);
        self::assertSame(0, $array['files_rewritten']);
        self::assertSame(0, $array['files_scanned']);
        self::assertFalse($array['dry_run']);
        self::assertArrayNotHasKey('files_skipped_too_recent', $array);
        self::assertArrayNotHasKey('cutoff_date', $array);
        self::assertArrayNotHasKey('warnings', $array);
        self::assertArrayNotHasKey('error', $array);
    }

    #[Test]
    public function it_falls_back_to_a_generated_summary_when_none_given(): void
    {
        $result = new RetentionResult(policy: 'x', pruned: 3);

        self::assertSame('3 entries pruned', $result->toArray()['summary']);
    }

    #[Test]
    public function it_uses_the_given_summary_when_present(): void
    {
        $result = new RetentionResult(policy: 'x', pruned: 3, summary: 'custom summary');

        self::assertSame('custom summary', $result->toArray()['summary']);
    }

    #[Test]
    public function it_includes_files_skipped_when_greater_than_zero(): void
    {
        $result = new RetentionResult(policy: 'x', pruned: 0, filesSkipped: 4);
        $array  = $result->toArray();

        self::assertSame(4, $array['files_skipped_too_recent']);
        self::assertArrayNotHasKey('cutoff_date', $array);
    }

    #[Test]
    public function it_includes_both_keys_when_cutoff_date_is_set(): void
    {
        $result = new RetentionResult(policy: 'x', pruned: 0, cutoffDate: '2024-01-01T00:00:00+00:00');
        $array  = $result->toArray();

        self::assertSame(0, $array['files_skipped_too_recent']);
        self::assertSame('2024-01-01T00:00:00+00:00', $array['cutoff_date']);
    }

    #[Test]
    public function it_includes_warnings_when_present(): void
    {
        $result = new RetentionResult(policy: 'x', pruned: 0, warnings: ['something odd happened']);

        self::assertSame(['something odd happened'], $result->toArray()['warnings']);
    }

    #[Test]
    public function it_includes_error_when_present(): void
    {
        $result = new RetentionResult(policy: 'x', pruned: 0, error: 'boom');

        self::assertSame('boom', $result->toArray()['error']);
    }

    #[Test]
    public function it_reflects_dry_run_and_duration(): void
    {
        $result = new RetentionResult(policy: 'x', pruned: 0, dryRun: true, durationMs: 42);
        $array  = $result->toArray();

        self::assertTrue($array['dry_run']);
        self::assertSame(42, $array['duration_ms']);
    }
}
