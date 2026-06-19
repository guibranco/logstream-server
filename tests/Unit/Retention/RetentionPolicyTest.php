<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // fromArray / construction
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_constructs_from_array_with_all_fields(): void
    {
        $policy = RetentionPolicy::fromArray([
            'name'           => 'test-policy',
            'app_key'        => 'my-app',
            'app_id'         => 'prod',
            'level'          => 'debug',
            'category'       => 'auth',
            'message_regex'  => '.*error.*',
            'message_glob'   => '*fail*',
            'older_than_days' => 30,
        ]);

        self::assertSame('test-policy', $policy->name);
        self::assertSame('my-app', $policy->appKey);
        self::assertSame('prod', $policy->appId);
        self::assertSame('debug', $policy->level);
        self::assertSame('auth', $policy->category);
        self::assertSame('.*error.*', $policy->messageRegex);
        self::assertSame('*fail*', $policy->messageGlob);
        self::assertSame(30, $policy->olderThanDays);
    }

    #[Test]
    public function it_uses_unnamed_fallback_when_name_is_missing(): void
    {
        $policy = RetentionPolicy::fromArray([]);
        self::assertSame('unnamed', $policy->name);
    }

    #[Test]
    public function it_leaves_all_optional_fields_null_when_absent(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'empty']);

        self::assertNull($policy->appKey);
        self::assertNull($policy->appId);
        self::assertNull($policy->level);
        self::assertNull($policy->category);
        self::assertNull($policy->messageRegex);
        self::assertNull($policy->messageGlob);
        self::assertNull($policy->olderThanDays);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // isEmpty / hasFieldFilters
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_is_empty_when_no_filters_set(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'empty']);
        self::assertTrue($policy->isEmpty());
        self::assertFalse($policy->hasFieldFilters());
    }

    #[Test]
    public function it_is_not_empty_when_only_date_is_set(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'date-only', 'older_than_days' => 7]);
        self::assertFalse($policy->isEmpty());
        self::assertFalse($policy->hasFieldFilters());
    }

    #[Test]
    public function it_has_field_filters_when_any_field_is_set(): void
    {
        foreach (['app_key', 'app_id', 'level', 'category', 'message_regex', 'message_glob'] as $field) {
            $policy = RetentionPolicy::fromArray(['name' => $field, $field => 'value']);
            self::assertTrue($policy->hasFieldFilters(), "Expected hasFieldFilters() true for {$field}");
            self::assertFalse($policy->isEmpty(), "Expected isEmpty() false for {$field}");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // getCutoffDate
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_null_cutoff_when_no_date_filter(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'no-date']);
        self::assertNull($policy->getCutoffDate());
    }

    #[Test]
    public function it_returns_cutoff_date_approximately_n_days_ago(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $cutoff = $policy->getCutoffDate();

        self::assertNotNull($cutoff);

        $expected = new \DateTimeImmutable('-7 days');
        // Allow a small window for test execution time (within 5 seconds)
        self::assertEqualsWithDelta(
            $expected->getTimestamp(),
            $cutoff->getTimestamp(),
            5,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // matchesEntry — date filter
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_matches_entry_older_than_cutoff(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $entry  = $this->makeEntry(timestamp: '2020-01-01T00:00:00.000Z');

        self::assertTrue($policy->matchesEntry($entry));
    }

    #[Test]
    public function it_does_not_match_entry_newer_than_cutoff(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $entry  = $this->makeEntry(timestamp: '2099-12-31T00:00:00.000Z');

        self::assertFalse($policy->matchesEntry($entry));
    }

    #[Test]
    public function it_does_not_match_entry_with_invalid_timestamp(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $entry  = $this->makeEntry(timestamp: 'not-a-date');

        self::assertFalse($policy->matchesEntry($entry));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // matchesEntry — field filters
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_matches_entry_with_matching_app_key(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'app_key' => 'my-app']);
        self::assertTrue($policy->matchesEntry($this->makeEntry(appKey: 'my-app')));
        self::assertFalse($policy->matchesEntry($this->makeEntry(appKey: 'other-app')));
    }

    #[Test]
    public function it_matches_entry_with_matching_app_id(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'app_id' => 'production']);
        self::assertTrue($policy->matchesEntry($this->makeEntry(appId: 'production')));
        self::assertFalse($policy->matchesEntry($this->makeEntry(appId: 'staging')));
    }

    #[Test]
    public function it_matches_entry_with_matching_level(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']);
        self::assertTrue($policy->matchesEntry($this->makeEntry(level: 'debug')));
        self::assertFalse($policy->matchesEntry($this->makeEntry(level: 'error')));
    }

    #[Test]
    public function it_matches_entry_with_matching_category(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'category' => 'payments']);
        self::assertTrue($policy->matchesEntry($this->makeEntry(category: 'payments')));
        self::assertFalse($policy->matchesEntry($this->makeEntry(category: 'auth')));
    }

    #[Test]
    public function it_matches_entry_with_matching_message_regex(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'message_regex' => '.*@hotmail\\.com']);
        self::assertTrue($policy->matchesEntry($this->makeEntry(message: 'user@hotmail.com logged in')));
        self::assertFalse($policy->matchesEntry($this->makeEntry(message: 'user@gmail.com logged in')));
    }

    #[Test]
    public function it_matches_entry_with_matching_message_glob(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'message_glob' => '*@hotmail.com*']);
        self::assertTrue($policy->matchesEntry($this->makeEntry(message: 'sent to user@hotmail.com today')));
        self::assertFalse($policy->matchesEntry($this->makeEntry(message: 'sent to user@gmail.com today')));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // matchesEntry — combined filters (AND logic)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_requires_all_filters_to_match(): void
    {
        $policy = RetentionPolicy::fromArray([
            'name'            => 'x',
            'level'           => 'debug',
            'older_than_days' => 7,
        ]);

        // Old + correct level → match
        self::assertTrue($policy->matchesEntry(
            $this->makeEntry(level: 'debug', timestamp: '2020-01-01T00:00:00.000Z')
        ));

        // Old but wrong level → no match
        self::assertFalse($policy->matchesEntry(
            $this->makeEntry(level: 'error', timestamp: '2020-01-01T00:00:00.000Z')
        ));

        // Correct level but too recent → no match
        self::assertFalse($policy->matchesEntry(
            $this->makeEntry(level: 'debug', timestamp: '2099-12-31T00:00:00.000Z')
        ));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function makeEntry(
        string $appKey    = 'test-app',
        string $appId     = 'test',
        string $level     = 'info',
        string $category  = 'general',
        string $message   = 'Test message',
        string $timestamp = '2024-03-01T10:00:00.000Z',
    ): array {
        return [
            'id'         => 'TEST001',
            'trace_id'   => 'trace-default',
            'batch_id'   => null,
            'app_key'    => $appKey,
            'app_id'     => $appId,
            'user_agent' => 'TestClient/1.0',
            'level'      => $level,
            'category'   => $category,
            'message'    => $message,
            'context'    => null,
            'timestamp'  => $timestamp,
            'created_at' => $timestamp,
        ];
    }
}
