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

        // 8. HMAC — over the log text exactly as sent, or else the compact
        //    re-encoding of it (see canonical()); either must match.
        if (!$this->signatureMatches($raw, $appKey, $ts, $nonce, strtolower($data['sig']), $token)) {
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
     * The string both sides sign: "{key}:{ts}:{nonce}:{log}".
     *
     * {log} is the `log` value exactly as it appears in the packet (what
     * clients naturally sign: the JSON text they embed). As a fallback the
     * server also accepts the compact re-encoding built here — no whitespace,
     * keys in the order sent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
     * zero fractions kept — for clients that pretty-print the packet.
     *
     * @param array<string, mixed>|object $log
     */
    public static function canonical(string $appKey, int $ts, string $nonce, array|object $log): string
    {
        return $appKey . ':' . $ts . ':' . $nonce . ':'
            . json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Drop expired nonces. Runs on each packet; costs only what has expired.
     * A nonce is kept up to and including its expiry, so a packet stamped at
     * the far edge of the window can't be replayed at the exact moment its
     * nonce would otherwise be forgotten.
     */
    public function pruneExpired(?int $nowMs = null): void
    {
        $nowMs ??= ($this->clock)();
        foreach ($this->nonces as $key => $expiry) {
            if ($expiry >= $nowMs) {
                break;
            }
            unset($this->nonces[$key]);
        }
    }

    private function signatureMatches(string $raw, string $appKey, int $ts, string $nonce, string $sig, string $token): bool
    {
        $prefix = $appKey . ':' . $ts . ':' . $nonce . ':';

        $rawLog = self::rawLogValue($raw);
        if ($rawLog !== null && hash_equals(hash_hmac('sha256', $prefix . $rawLog, $token), $sig)) {
            return true;
        }

        // Objects keep {} as {} (an associative decode would turn it into []).
        // Some valid JSON can't be decoded to objects (e.g. a property name
        // starting with a NUL byte): then only the raw text can match.
        $decoded = json_decode($raw);
        if (!is_object($decoded) || !isset($decoded->log) || !is_object($decoded->log)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', self::canonical($appKey, $ts, $nonce, $decoded->log), $token), $sig);
    }

    /**
     * The raw JSON text of the top-level "log" value (the last one, as
     * json_decode() keeps the last of duplicate keys), or null. Only called on
     * a packet json_decode() has already accepted, so it can be lenient.
     */
    public static function rawLogValue(string $raw): ?string
    {
        $len = strlen($raw);
        $i   = self::skipWhitespace($raw, 0, $len);
        if ($i >= $len || $raw[$i] !== '{') {
            return null;
        }
        $i++;
        $found = null;

        while (true) {
            $i = self::skipWhitespace($raw, $i, $len);
            if ($i >= $len || $raw[$i] === '}') {
                return $found;
            }
            if ($raw[$i] !== '"') {
                return null;
            }
            $keyEnd = self::skipString($raw, $i, $len);
            $key    = json_decode(substr($raw, $i, $keyEnd - $i));
            $i      = self::skipWhitespace($raw, $keyEnd, $len);
            if ($i >= $len || $raw[$i] !== ':') {
                return null;
            }
            $start = self::skipWhitespace($raw, $i + 1, $len);
            $end   = self::skipValue($raw, $start, $len);
            if ($key === 'log') {
                $found = substr($raw, $start, $end - $start);
            }
            $i = self::skipWhitespace($raw, $end, $len);
            if ($i < $len && $raw[$i] === ',') {
                $i++;
            }
        }
    }

    private static function skipWhitespace(string $raw, int $i, int $len): int
    {
        while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t" || $raw[$i] === "\n" || $raw[$i] === "\r")) {
            $i++;
        }
        return $i;
    }

    /** $i is on the opening quote; returns the index after the closing one. */
    private static function skipString(string $raw, int $i, int $len): int
    {
        for ($i++; $i < $len; $i++) {
            if ($raw[$i] === '\\') {
                $i++;
            } elseif ($raw[$i] === '"') {
                return $i + 1;
            }
        }
        return $len;
    }

    /** Returns the index just after the value starting at $i. */
    private static function skipValue(string $raw, int $i, int $len): int
    {
        if ($i >= $len) {
            return $len;
        }
        if ($raw[$i] === '"') {
            return self::skipString($raw, $i, $len);
        }
        if ($raw[$i] === '{' || $raw[$i] === '[') {
            $depth = 0;
            while ($i < $len) {
                $c = $raw[$i];
                if ($c === '"') {
                    $i = self::skipString($raw, $i, $len);
                    continue;
                }
                if ($c === '{' || $c === '[') {
                    $depth++;
                } elseif ($c === '}' || $c === ']') {
                    $depth--;
                    if ($depth === 0) {
                        return $i + 1;
                    }
                }
                $i++;
            }
            return $len;
        }
        // Number, true, false, null
        while ($i < $len && !in_array($raw[$i], [',', '}', ']', ' ', "\t", "\n", "\r"], true)) {
            $i++;
        }
        return $i;
    }

    public function nonceCount(): int
    {
        return count($this->nonces);
    }
}
