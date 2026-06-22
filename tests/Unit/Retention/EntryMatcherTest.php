<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\EntryMatcher;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntryMatcherTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // shouldDelete — empty policy
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_never_deletes_for_an_empty_policy(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'empty']);
        $entry  = $this->makeEntry();

        self::assertFalse(EntryMatcher::shouldDelete($policy, $entry));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // isExpired
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_considers_old_entries_expired(): void
    {
        $cutoff = new \DateTimeImmutable('2024-01-01T00:00:00Z');
        $entry  = $this->makeEntry(timestamp: '2020-06-15T12:00:00Z');

        self::assertTrue(EntryMatcher::isExpired($entry, $cutoff));
    }

    #[Test]
    public function it_considers_recent_entries_not_expired(): void
    {
        $cutoff = new \DateTimeImmutable('2020-01-01T00:00:00Z');
        $entry  = $this->makeEntry(timestamp: '2099-12-31T00:00:00Z');

        self::assertFalse(EntryMatcher::isExpired($entry, $cutoff));
    }

    #[Test]
    public function it_treats_missing_timestamp_as_not_expired(): void
    {
        $cutoff = new \DateTimeImmutable('2099-01-01T00:00:00Z');
        $entry  = ['message' => 'no timestamp'];

        self::assertFalse(EntryMatcher::isExpired($entry, $cutoff));
    }

    #[Test]
    public function it_treats_invalid_timestamp_as_not_expired(): void
    {
        $cutoff = new \DateTimeImmutable('2099-01-01T00:00:00Z');
        $entry  = $this->makeEntry(timestamp: 'not-a-date');

        self::assertFalse(EntryMatcher::isExpired($entry, $cutoff));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // matches — date filter
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_matches_old_entry_with_date_only_policy(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 30]);
        $entry  = $this->makeEntry(timestamp: '2020-01-01T00:00:00Z');

        self::assertTrue(EntryMatcher::matches($policy, $entry));
    }

    #[Test]
    public function it_does_not_match_recent_entry_with_date_policy(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $entry  = $this->makeEntry(timestamp: '2099-12-31T00:00:00Z');

        self::assertFalse(EntryMatcher::matches($policy, $entry));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // matches — field filters
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_matches_by_app_key(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'app_key' => 'my-app']);

        self::assertTrue(EntryMatcher::matches($policy, $this->makeEntry(appKey: 'my-app')));
        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(appKey: 'other-app')));
    }

    #[Test]
    public function it_matches_by_app_id(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'app_id' => 'prod']);

        self::assertTrue(EntryMatcher::matches($policy, $this->makeEntry(appId: 'prod')));
        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(appId: 'staging')));
    }

    #[Test]
    public function it_matches_by_level(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']);

        self::assertTrue(EntryMatcher::matches($policy, $this->makeEntry(level: 'debug')));
        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(level: 'error')));
    }

    #[Test]
    public function it_matches_by_category(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'category' => 'payments']);

        self::assertTrue(EntryMatcher::matches($policy, $this->makeEntry(category: 'payments')));
        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(category: 'auth')));
    }

    #[Test]
    public function it_matches_by_message_regex(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'message_regex' => '.*@hotmail\\.com']);

        self::assertTrue(EntryMatcher::matches($policy, $this->makeEntry(message: 'user@hotmail.com logged in')));
        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(message: 'user@gmail.com logged in')));
    }

    #[Test]
    public function it_matches_by_message_glob(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'message_glob' => '*@hotmail.com*']);

        self::assertTrue(EntryMatcher::matches($policy, $this->makeEntry(message: 'sent to user@hotmail.com today')));
        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(message: 'sent to user@gmail.com today')));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // matches — combined AND logic
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_requires_all_filters_to_match(): void
    {
        $policy = RetentionPolicy::fromArray([
            'name'            => 'x',
            'level'           => 'debug',
            'older_than_days' => 7,
        ]);

        self::assertTrue(EntryMatcher::matches($policy, $this->makeEntry(
            level:     'debug',
            timestamp: '2020-01-01T00:00:00Z',
        )));

        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(
            level:     'error',
            timestamp: '2020-01-01T00:00:00Z',
        )));

        self::assertFalse(EntryMatcher::matches($policy, $this->makeEntry(
            level:     'debug',
            timestamp: '2099-12-31T00:00:00Z',
        )));
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
