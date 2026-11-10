<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Models;

use LogService\Models\LogEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogEntryTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // fromArray / toArray round-trip
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_an_entry_from_a_complete_array(): void
    {
        $entry = LogEntry::fromArray($this->fixture());

        self::assertSame('01HZ000TEST00000000000000A', $entry->id);
        self::assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $entry->traceId);
        self::assertSame('batch-001', $entry->batchId);
        self::assertSame('billing-api', $entry->appKey);
        self::assertSame('production', $entry->appId);
        self::assertSame('BillingService/2.1.0', $entry->userAgent);
        self::assertSame('error', $entry->level);
        self::assertSame('payments', $entry->category);
        self::assertSame('Charge failed', $entry->message);
        self::assertSame(['invoice_id' => 1234], $entry->context);
    }

    #[Test]
    public function it_round_trips_through_to_array(): void
    {
        $original = $this->fixture();
        $entry    = LogEntry::fromArray($original);
        $exported = $entry->toArray();

        self::assertSame($entry->id,       $exported['id']);
        self::assertSame($entry->traceId,  $exported['trace_id']);
        self::assertSame($entry->batchId,  $exported['batch_id']);
        self::assertSame($entry->appKey,   $exported['app_key']);
        self::assertSame($entry->appId,    $exported['app_id']);
        self::assertSame($entry->level,    $exported['level']);
        self::assertSame($entry->category, $exported['category']);
        self::assertSame($entry->message,  $exported['message']);
        self::assertSame($entry->context,  $exported['context']);
    }

    #[Test]
    public function it_round_trips_the_message_template(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), [
            'message'          => 'Charge 1234 failed',
            'message_template' => 'Charge {invoice_id} failed',
        ]));

        self::assertSame('Charge {invoice_id} failed', $entry->messageTemplate);
        self::assertSame('Charge {invoice_id} failed', $entry->toArray()['message_template']);
    }

    #[Test]
    public function it_has_no_message_template_by_default(): void
    {
        $entry = LogEntry::fromArray($this->fixture());

        self::assertNull($entry->messageTemplate);
        self::assertArrayHasKey('message_template', $entry->toArray());
        self::assertNull($entry->toArray()['message_template']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Level validation
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    #[DataProvider('validLevels')]
    public function it_accepts_all_valid_levels(string $level): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['level' => $level]));
        self::assertSame($level, $entry->level);
    }

    public static function validLevels(): array
    {
        return array_map(fn($l) => [$l], LogEntry::LEVELS);
    }

    #[Test]
    public function it_falls_back_to_info_for_unknown_level(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['level' => 'nonsense']));
        self::assertSame('info', $entry->level);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Category truncation
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_truncates_category_to_max_length(): void
    {
        $long  = str_repeat('x', LogEntry::MAX_CATEGORY_LENGTH + 50);
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['category' => $long]));

        self::assertSame(LogEntry::MAX_CATEGORY_LENGTH, strlen($entry->category));
    }

    #[Test]
    public function it_defaults_category_to_general_when_missing(): void
    {
        $data = $this->fixture();
        unset($data['category']);

        $entry = LogEntry::fromArray($data);
        self::assertSame('general', $entry->category);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Optional fields
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_allows_null_batch_id(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['batch_id' => null]));
        self::assertNull($entry->batchId);
    }

    #[Test]
    public function it_allows_null_user_agent(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['user_agent' => null]));
        self::assertNull($entry->userAgent);
    }

    #[Test]
    public function it_allows_null_context(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['context' => null]));
        self::assertNull($entry->context);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Context decoding
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_decodes_json_string_context(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), [
            'context' => '{"key":"value","num":42}',
        ]));

        self::assertSame(['key' => 'value', 'num' => 42], $entry->context);
    }

    #[Test]
    public function it_returns_null_for_invalid_json_context(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), [
            'context' => 'not-valid-json',
        ]));

        self::assertNull($entry->context);
    }

    #[Test]
    public function it_passes_through_array_context_unchanged(): void
    {
        $ctx   = ['foo' => 'bar', 'nested' => ['x' => 1]];
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['context' => $ctx]));

        self::assertSame($ctx, $entry->context);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Timestamp handling
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_parses_iso8601_timestamp(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), [
            'timestamp' => '2024-06-15T12:00:00.000Z',
        ]));

        self::assertSame('2024', $entry->timestamp->format('Y'));
        self::assertSame('06',   $entry->timestamp->format('m'));
        self::assertSame('15',   $entry->timestamp->format('d'));
    }

    #[Test]
    public function it_falls_back_gracefully_for_invalid_timestamp(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), [
            'timestamp' => 'not-a-date',
        ]));

        // Should not throw — result is "now"
        self::assertInstanceOf(\DateTimeImmutable::class, $entry->timestamp);
    }

    #[Test]
    public function it_round_trips_the_sender_ip(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['sender_ip' => '203.0.113.7']));

        self::assertSame('203.0.113.7', $entry->senderIp);
        self::assertSame('203.0.113.7', $entry->toArray()['sender_ip']);
    }

    #[Test]
    public function it_defaults_sender_ip_to_null_for_legacy_entries(): void
    {
        $entry = LogEntry::fromArray($this->fixture());

        self::assertNull($entry->senderIp);
        self::assertArrayHasKey('sender_ip', $entry->toArray());
        self::assertNull($entry->toArray()['sender_ip']);
    }

    #[Test]
    public function it_discards_an_invalid_sender_ip(): void
    {
        $entry = LogEntry::fromArray(array_merge($this->fixture(), ['sender_ip' => 'not-an-ip']));

        self::assertNull($entry->senderIp);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function fixture(): array
    {
        return [
            'id'         => '01HZ000TEST00000000000000A',
            'trace_id'   => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'batch_id'   => 'batch-001',
            'app_key'    => 'billing-api',
            'app_id'     => 'production',
            'user_agent' => 'BillingService/2.1.0',
            'level'      => 'error',
            'category'   => 'payments',
            'message'    => 'Charge failed',
            'context'    => ['invoice_id' => 1234],
            'timestamp'  => '2024-06-15T12:00:00.000Z',
            'created_at' => '2024-06-15T12:00:00.100Z',
        ];
    }
}
