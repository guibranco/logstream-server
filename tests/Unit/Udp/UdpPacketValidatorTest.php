<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Udp;

use LogService\Udp\UdpPacketValidator;
use LogService\Udp\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UdpPacketValidatorTest extends TestCase
{
    private const APP_KEY = 'billing-api';
    private const TOKEN   = 'lss_test-token-secret';
    private const NOW     = 1_748_000_000_000;

    private int $now = self::NOW;

    /** @var list<string> */
    private array $lookups = [];

    private function validator(int $windowMs = 30_000, int $nonceTtl = 60, int $maxBytes = 8192, int $cacheMax = 1000): UdpPacketValidator
    {
        return new UdpPacketValidator(
            tokenLookup: function (string $key): ?string {
                $this->lookups[] = $key;
                return match ($key) {
                    self::APP_KEY => self::TOKEN,
                    'other-app'   => 'other-token',
                    default       => null,
                };
            },
            maxPacketBytes:    $maxBytes,
            timestampWindowMs: $windowMs,
            nonceTtlSeconds:   $nonceTtl,
            nonceCacheMax:     $cacheMax,
            clock:             fn(): int => $this->now,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function packet(array $overrides = [], string $token = self::TOKEN): array
    {
        $packet = array_merge([
            'v'     => 1,
            'key'   => self::APP_KEY,
            'ts'    => $this->now,
            'nonce' => bin2hex(random_bytes(8)),
            'log'   => ['app_id' => 'prod', 'level' => 'info', 'message' => 'Test'],
        ], $overrides);

        if (!array_key_exists('sig', $overrides)) {
            $packet['sig'] = hash_hmac(
                'sha256',
                UdpPacketValidator::canonical($packet['key'], $packet['ts'], $packet['nonce'], $packet['log']),
                $token,
            );
        }

        return $packet;
    }

    private static function json(array $packet): string
    {
        return json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function assertRejected(ValidationResult $result, string $reason): void
    {
        self::assertFalse($result->ok, 'expected a rejection');
        self::assertNull($result->data);
        self::assertSame($reason, $result->reason, (string) $result->detail);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    #[Test]
    public function valid_packet_is_accepted(): void
    {
        $packet = $this->packet();

        $result = $this->validator()->validate(self::json($packet));

        self::assertTrue($result->ok, (string) $result->detail);
        self::assertSame(self::APP_KEY, $result->data['key']);
        self::assertSame($packet['log'], $result->data['log']);
    }

    #[Test]
    public function uppercase_signature_is_accepted(): void
    {
        $packet        = $this->packet();
        $packet['sig'] = strtoupper($packet['sig']);

        self::assertTrue($this->validator()->validate(self::json($packet))->ok);
    }

    #[Test]
    public function empty_objects_unicode_and_slashes_in_log_keep_the_signature_valid(): void
    {
        $log    = ['app_id' => 'prod', 'message' => 'Olá 🎉', 'context' => ['url' => 'https://example.com/a/b', 'empty' => new \stdClass(), 'list' => [], 'amount' => 99.5]];
        $packet = $this->packet(['log' => $log]);

        self::assertTrue($this->validator()->validate(self::json($packet))->ok);
    }

    #[Test]
    public function float_with_zero_fraction_keeps_the_signature_valid(): void
    {
        // A client that encodes 99.0 as "99.0" (JS/Python style) signs that text.
        $nonce = bin2hex(random_bytes(8));
        $log   = '{"app_id":"prod","message":"m","context":{"amount":99.0}}';
        $sig   = hash_hmac('sha256', self::APP_KEY . ':' . $this->now . ':' . $nonce . ':' . $log, self::TOKEN);
        $raw   = '{"v":1,"key":"' . self::APP_KEY . '","ts":' . $this->now . ',"nonce":"' . $nonce . '","sig":"' . $sig . '","log":' . $log . '}';

        self::assertTrue($this->validator()->validate($raw)->ok);
    }

    // ── Size / JSON / version ─────────────────────────────────────────────────

    #[Test]
    public function oversized_packet_is_rejected(): void
    {
        $packet = $this->packet(['log' => ['app_id' => 'p', 'message' => str_repeat('x', 400)]]);

        self::assertRejected($this->validator(maxBytes: 300)->validate(self::json($packet)), UdpPacketValidator::REASON_TOO_LARGE);
    }

    #[Test]
    public function invalid_json_is_rejected(): void
    {
        self::assertRejected($this->validator()->validate('not json {{'), UdpPacketValidator::REASON_INVALID_JSON);
        self::assertRejected($this->validator()->validate('[1,2,3]'), UdpPacketValidator::REASON_INVALID_JSON);
        self::assertRejected($this->validator()->validate('"string"'), UdpPacketValidator::REASON_INVALID_JSON);
    }

    #[Test]
    public function wrong_version_is_rejected(): void
    {
        foreach ([2, '1', null] as $version) {
            $packet = $this->packet(['v' => $version]);
            self::assertRejected($this->validator()->validate(self::json($packet)), UdpPacketValidator::REASON_BAD_VERSION);
        }
    }

    // ── Fields ────────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function requiredFields(): array
    {
        return ['key' => ['key'], 'ts' => ['ts'], 'nonce' => ['nonce'], 'sig' => ['sig'], 'log' => ['log']];
    }

    #[Test]
    #[DataProvider('requiredFields')]
    public function missing_field_is_rejected(string $field): void
    {
        $packet = $this->packet();
        unset($packet[$field]);

        self::assertRejected($this->validator()->validate(self::json($packet)), UdpPacketValidator::REASON_MISSING_FIELD);
    }

    #[Test]
    public function log_must_be_a_non_empty_object(): void
    {
        foreach (['just a string', [], ['a', 'b']] as $log) {
            $packet        = $this->packet();
            $packet['log'] = $log;
            self::assertRejected($this->validator()->validate(self::json($packet)), UdpPacketValidator::REASON_MISSING_FIELD);
        }
    }

    #[Test]
    public function ts_must_be_an_integer(): void
    {
        foreach ([(string) $this->now, $this->now + 0.5] as $ts) {
            $packet = $this->packet(['ts' => $ts, 'sig' => str_repeat('0', 64)]);
            self::assertRejected($this->validator()->validate(self::json($packet)), UdpPacketValidator::REASON_MISSING_FIELD);
        }
    }

    #[Test]
    public function short_or_malformed_nonce_is_rejected(): void
    {
        foreach (['a3f9b2c1', str_repeat('a', 65), 'has spaces in it!!'] as $nonce) {
            $packet = $this->packet(['nonce' => $nonce]);
            self::assertRejected($this->validator()->validate(self::json($packet)), UdpPacketValidator::REASON_BAD_NONCE);
        }
    }

    #[Test]
    public function malformed_signature_is_rejected_before_any_lookup(): void
    {
        $packet = $this->packet(['sig' => 'not-hex']);

        self::assertRejected($this->validator()->validate(self::json($packet)), UdpPacketValidator::REASON_BAD_SIGNATURE);
        self::assertSame([], $this->lookups);
    }

    // ── Timestamp ─────────────────────────────────────────────────────────────

    #[Test]
    public function packet_outside_the_window_is_rejected(): void
    {
        $v = $this->validator(windowMs: 30_000);

        self::assertRejected($v->validate(self::json($this->packet(['ts' => self::NOW - 30_001]))), UdpPacketValidator::REASON_STALE);
        self::assertRejected($v->validate(self::json($this->packet(['ts' => self::NOW + 30_001]))), UdpPacketValidator::REASON_STALE);
        self::assertSame([], $this->lookups, 'stale packets never reach the token lookup');
    }

    #[Test]
    public function packet_at_the_edge_of_the_window_is_accepted(): void
    {
        $v = $this->validator(windowMs: 30_000);

        self::assertTrue($v->validate(self::json($this->packet(['ts' => self::NOW - 30_000])))->ok);
        self::assertTrue($v->validate(self::json($this->packet(['ts' => self::NOW + 30_000])))->ok);
    }

    // ── Replay ────────────────────────────────────────────────────────────────

    #[Test]
    public function replayed_packet_is_rejected(): void
    {
        $v    = $this->validator();
        $json = self::json($this->packet());

        self::assertTrue($v->validate($json)->ok);
        self::assertRejected($v->validate($json), UdpPacketValidator::REASON_REPLAY);
    }

    #[Test]
    public function same_nonce_for_two_keys_is_accepted(): void
    {
        $v     = $this->validator();
        $nonce = bin2hex(random_bytes(8));

        self::assertTrue($v->validate(self::json($this->packet(['nonce' => $nonce])))->ok);
        self::assertTrue($v->validate(self::json($this->packet(['nonce' => $nonce, 'key' => 'other-app'], 'other-token')))->ok);
    }

    #[Test]
    public function nonce_can_be_reused_after_its_ttl(): void
    {
        $v     = $this->validator(windowMs: 30_000, nonceTtl: 60);
        $nonce = bin2hex(random_bytes(8));

        self::assertTrue($v->validate(self::json($this->packet(['nonce' => $nonce])))->ok);

        $this->now += 60_001;
        self::assertTrue($v->validate(self::json($this->packet(['nonce' => $nonce])))->ok);
        self::assertSame(1, $v->nonceCount(), 'the expired nonce was pruned');
    }

    #[Test]
    public function forged_packets_do_not_use_up_nonces(): void
    {
        $v      = $this->validator();
        $packet = $this->packet();
        $forged = ['sig' => str_repeat('a', 64)] + $packet;

        self::assertRejected($v->validate(self::json($forged)), UdpPacketValidator::REASON_BAD_SIGNATURE);
        self::assertSame(0, $v->nonceCount());
        self::assertTrue($v->validate(self::json($packet))->ok, 'the genuine packet with that nonce still gets in');
    }

    #[Test]
    public function full_nonce_cache_rejects_new_packets_until_nonces_expire(): void
    {
        $v = $this->validator(cacheMax: 2);

        self::assertTrue($v->validate(self::json($this->packet()))->ok);
        self::assertTrue($v->validate(self::json($this->packet()))->ok);
        self::assertRejected($v->validate(self::json($this->packet())), UdpPacketValidator::REASON_NONCE_CACHE_FULL);

        $this->now += 60_001;
        self::assertTrue($v->validate(self::json($this->packet()))->ok);
    }

    #[Test]
    public function prune_only_drops_expired_nonces(): void
    {
        $v = $this->validator(nonceTtl: 60);
        $v->validate(self::json($this->packet()));
        $this->now += 30_000;
        $v->validate(self::json($this->packet()));

        $v->pruneExpired(self::NOW + 60_001);

        self::assertSame(1, $v->nonceCount());
    }

    // ── Keys and HMAC ─────────────────────────────────────────────────────────

    #[Test]
    public function unknown_key_is_rejected(): void
    {
        self::assertRejected(
            $this->validator()->validate(self::json($this->packet(['key' => 'nonexistent-app']))),
            UdpPacketValidator::REASON_UNKNOWN_KEY,
        );
    }

    #[Test]
    public function reserved_self_log_key_is_rejected_without_lookup(): void
    {
        self::assertRejected(
            $this->validator()->validate(self::json($this->packet(['key' => 'logstream-server']))),
            UdpPacketValidator::REASON_RESERVED_KEY,
        );
        self::assertSame([], $this->lookups);
    }

    #[Test]
    public function wrong_secret_is_rejected(): void
    {
        self::assertRejected(
            $this->validator()->validate(self::json($this->packet([], 'wrong-token'))),
            UdpPacketValidator::REASON_BAD_SIGNATURE,
        );
    }

    #[Test]
    public function tampered_log_invalidates_the_signature(): void
    {
        $packet                   = $this->packet();
        $packet['log']['message'] = 'tampered message';

        self::assertRejected($this->validator()->validate(self::json($packet)), UdpPacketValidator::REASON_BAD_SIGNATURE);
    }

    #[Test]
    public function tampered_key_ts_or_nonce_invalidates_the_signature(): void
    {
        $base = $this->packet();

        foreach ([['key' => 'other-app'], ['ts' => self::NOW + 1], ['nonce' => bin2hex(random_bytes(8))]] as $change) {
            self::assertRejected(
                $this->validator()->validate(self::json(array_merge($base, $change))),
                UdpPacketValidator::REASON_BAD_SIGNATURE,
            );
        }
    }

    // ── canonical() ───────────────────────────────────────────────────────────

    #[Test]
    public function canonical_string_format_is_stable(): void
    {
        self::assertSame(
            'my-app:1748000000000:abc123:{"message":"hello"}',
            UdpPacketValidator::canonical('my-app', 1_748_000_000_000, 'abc123', ['message' => 'hello']),
        );
    }

    #[Test]
    public function canonical_uses_unescaped_unicode_and_slashes(): void
    {
        $canon = UdpPacketValidator::canonical('app', 1000, 'n', ['url' => 'https://example.com/path', 'emoji' => '🎉']);

        self::assertStringContainsString('https://example.com/path', $canon);
        self::assertStringContainsString('🎉', $canon);
    }

    #[Test]
    public function validation_result_shapes(): void
    {
        $ok = ValidationResult::ok(['key' => 'k']);
        self::assertTrue($ok->ok);
        self::assertSame(['key' => 'k'], $ok->data);
        self::assertNull($ok->reason);

        $fail = ValidationResult::fail('bad_signature', 'HMAC mismatch');
        self::assertFalse($fail->ok);
        self::assertNull($fail->data);
        self::assertSame('bad_signature', $fail->reason);
        self::assertSame('HMAC mismatch', $fail->detail);
    }
}
