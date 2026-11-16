<?php

declare(strict_types=1);

namespace LogService\Udp;

use LogService\Logging\SelfLogConfig;

/**
 * Validates an incoming UDP log packet:
 *
 *   {"v":1,"key":"billing-api","ts":1748000000000,"nonce":"9f2c4e1ab37d5c60",
 *    "sig":"<hex hmac-sha256>","log":{"app_id":"web-1","message":"…"}}
 *
 * Checks run cheapest first: size, JSON, version, fields, timestamp window,
 * replay (nonce per key), token lookup, HMAC. A nonce is only remembered once
 * the signature is valid, so forged packets cannot fill the cache.
 *
 * The token lookup is injected, so this class depends on neither storage nor
 * the HTTP auth classes.
 */
final class UdpPacketValidator
{
    public const REASON_TOO_LARGE        = 'too_large';
    public const REASON_INVALID_JSON     = 'invalid_json';
    public const REASON_BAD_VERSION      = 'bad_version';
    public const REASON_MISSING_FIELD    = 'missing_field';
    public const REASON_BAD_NONCE        = 'bad_nonce';
    public const REASON_STALE            = 'stale';
    public const REASON_REPLAY           = 'replay';
    public const REASON_RESERVED_KEY     = 'reserved_key';
    public const REASON_UNKNOWN_KEY      = 'unknown_key';
    public const REASON_BAD_SIGNATURE    = 'bad_signature';
    public const REASON_NONCE_CACHE_FULL = 'nonce_cache_full';

    private const NONCE_PATTERN = '/^[A-Za-z0-9_-]{%d,64}$/';

    /**
     * "{key}\0{nonce}" => expiry (ms). Kept in insertion order; expiries only
     * grow, so expired entries are always at the front.
     *
     * @var array<string, int>
     */
    private array $nonces = [];

    /** @var \Closure(string): ?string */
    private readonly \Closure $tokenLookup;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param callable(string $appKey): ?string $tokenLookup Secret for the app_key; null = unknown / inactive.
     * @param (callable(): int)|null            $clock       Current time in ms (tests).
     */
    public function __construct(
        callable $tokenLookup,
        private readonly int $maxPacketBytes    = 8192,
        private readonly int $timestampWindowMs = 30_000,
        private readonly int $nonceTtlSeconds   = 60,
        private readonly int $nonceCacheMax     = 200_000,
        ?callable $clock = null,
    ) {
        $this->tokenLookup = \Closure::fromCallable($tokenLookup);
        $this->clock       = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): int => (int) (microtime(true) * 1000);
    }

    public static function fromConfig(UdpConfig $config, callable $tokenLookup): self
    {
        return new self(
            $tokenLookup,
            $config->maxPacketBytes,
            $config->timestampWindowMs,
            $config->nonceTtlSeconds,
            $config->nonceCacheMax,
        );
    }

    public function validate(string $raw): ValidationResult
    {
        // 1. Size
        if (strlen($raw) > $this->maxPacketBytes) {
            return ValidationResult::fail(self::REASON_TOO_LARGE, strlen($raw) . " bytes > {$this->maxPacketBytes}");
        }

        // 2. JSON object
        $data = json_decode($raw, true);
        if (!is_array($data) || array_is_list($data)) {
            return ValidationResult::fail(self::REASON_INVALID_JSON, 'not a JSON object');
        }

        // 3. Protocol version
        if (($data['v'] ?? null) !== 1) {
            return ValidationResult::fail(self::REASON_BAD_VERSION, 'v must be 1');
        }

        // 4. Fields
        foreach (['key', 'nonce', 'sig'] as $field) {
            if (!is_string($data[$field] ?? null) || $data[$field] === '') {
                return ValidationResult::fail(self::REASON_MISSING_FIELD, "{$field} must be a non-empty string");
            }
        }
        if (!is_int($data['ts'] ?? null)) {
            return ValidationResult::fail(self::REASON_MISSING_FIELD, 'ts must be an integer (Unix time in ms)');
        }
        if (!is_array($data['log'] ?? null) || $data['log'] === [] || array_is_list($data['log'])) {
            return ValidationResult::fail(self::REASON_MISSING_FIELD, 'log must be a non-empty object');
        }
        if (!preg_match(sprintf(self::NONCE_PATTERN, UdpConfig::MIN_NONCE_LENGTH), $data['nonce'])) {
            return ValidationResult::fail(
                self::REASON_BAD_NONCE,
                'nonce must be ' . UdpConfig::MIN_NONCE_LENGTH . '–64 characters of A-Z a-z 0-9 _ -',
            );
        }
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $data['sig'])) {
            return ValidationResult::fail(self::REASON_BAD_SIGNATURE, 'sig must be 64 hex characters');
        }

        $appKey = $data['key'];
        $ts     = $data['ts'];
        $nonce  = $data['nonce'];

        // 5. Timestamp window
        $now   = ($this->clock)();
        $delta = abs($now - $ts);
        if ($delta > $this->timestampWindowMs) {
            return ValidationResult::fail(self::REASON_STALE, "ts is {$delta}ms from server time (max {$this->timestampWindowMs}ms)");
        }

        // 6. Replay (per key: two clients may pick the same nonce)
        $this->pruneExpired($now);
        $nonceKey = $appKey . "\0" . $nonce;
        if (isset($this->nonces[$nonceKey])) {
            return ValidationResult::fail(self::REASON_REPLAY, 'nonce already used');
        }

        // 7. Key
        if (SelfLogConfig::isReservedAppKey($appKey)) {
            return ValidationResult::fail(self::REASON_RESERVED_KEY, "'{$appKey}' is reserved for the server's own logs");
        }
        $token = ($this->tokenLookup)($appKey);
        if ($token === null || $token === '') {
            return ValidationResult::fail(self::REASON_UNKNOWN_KEY, "unknown or inactive client '{$appKey}'");
        }

        // 8. HMAC — over the log object as sent ({} stays {}), see canonical().
        $logObject = json_decode($raw)->log;
        $expected  = hash_hmac('sha256', self::canonical($appKey, $ts, $nonce, $logObject), $token);
        if (!hash_equals($expected, strtolower($data['sig']))) {
            return ValidationResult::fail(self::REASON_BAD_SIGNATURE, 'HMAC mismatch');
        }

        // 9. Remember the nonce (bounded)
        if (count($this->nonces) >= $this->nonceCacheMax) {
            return ValidationResult::fail(self::REASON_NONCE_CACHE_FULL, "{$this->nonceCacheMax} nonces held");
        }
        $this->nonces[$nonceKey] = $now + $this->nonceTtlSeconds * 1000;

        return ValidationResult::ok($data);
    }

    /**
     * The string both sides sign: "{key}:{ts}:{nonce}:{log as JSON}", where the
     * JSON uses JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, no whitespace,
     * keys in the order sent.
     *
     * @param array<string, mixed>|object $log
     */
    public static function canonical(string $appKey, int $ts, string $nonce, array|object $log): string
    {
        return $appKey . ':' . $ts . ':' . $nonce . ':'
            . json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Drop expired nonces. Runs on each packet; costs only what has expired. */
    public function pruneExpired(?int $nowMs = null): void
    {
        $nowMs ??= ($this->clock)();
        foreach ($this->nonces as $key => $expiry) {
            if ($expiry > $nowMs) {
                break;
            }
            unset($this->nonces[$key]);
        }
    }

    public function nonceCount(): int
    {
        return count($this->nonces);
    }
}
