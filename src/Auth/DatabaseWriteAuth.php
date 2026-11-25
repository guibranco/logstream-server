<?php

declare(strict_types=1);

namespace LogService\Auth;

use LogService\Database\Connection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-client write authentication backed by the `clients` MariaDB table.
 *
 * Each registered application has its own row with:
 *   app_key   — sent by the client as X-Api-Key
 *   api_token — sent by the client as X-Api-Token
 *
 * Both headers must be present and match an active row.
 * Credentials are verified with hash_equals() to prevent timing attacks.
 *
 * The resolved app_key from the matching row is returned on success and
 * used as the default app_key for ingested log entries.
 */
final class DatabaseWriteAuth implements WriteAuthInterface, CacheFlushableInterface
{
    /**
     * Most app_keys (known or not) kept in the cache. Unknown keys are cached
     * too, so a flood of made-up keys (HTTP or UDP) could otherwise grow it
     * without limit; above this the cache starts over.
     */
    private const MAX_CACHE_ENTRIES = 10_000;

    /** In-memory cache: app_key → api_token, or null for unknown / inactive keys. */
    private array $cache = [];

    public function __construct(private readonly Connection $db) {}

    // ──────────────────────────────────────────────────────────────────────────

    public function authenticate(ServerRequestInterface $request): AuthResult
    {
        $apiKey   = trim($request->getHeaderLine('X-Api-Key'));
        $apiToken = trim($request->getHeaderLine('X-Api-Token'));

        if ($apiKey === '' || $apiToken === '') {
            return AuthResult::failure(
                'Missing X-Api-Key or X-Api-Token header'
            );
        }

        $storedToken = $this->lookupToken($apiKey);

        if ($storedToken === null) {
            return AuthResult::failure("Unknown client: {$apiKey}");
        }

        if (!hash_equals($storedToken, $apiToken)) {
            return AuthResult::failure("Invalid token for client: {$apiKey}");
        }

        return AuthResult::success($apiKey);
    }

    /**
     * The active client's api_token, or null when unknown or inactive. Used
     * as the HMAC key for UDP packets; shares this class's cache, so
     * flushCache() (called when a client is updated, rotated or deleted)
     * applies to UDP straight away.
     */
    public function tokenFor(string $appKey): ?string
    {
        return $this->lookupToken($appKey);
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Look up the stored api_token for the given app_key.
     * Returns null when the client is not found or is inactive.
     * Results are cached in memory for the lifetime of the process
     * to avoid a DB round-trip on every log ingestion request.
     */
    private function lookupToken(string $appKey): ?string
    {
        if (array_key_exists($appKey, $this->cache)) {
            return $this->cache[$appKey];
        }

        try {
            $row = $this->db->run(static function (\PDO $pdo) use ($appKey): array|false {
                $stmt = $pdo->prepare(
                    'SELECT api_token FROM clients WHERE app_key = :app_key AND active = 1 LIMIT 1'
                );
                $stmt->execute([':app_key' => $appKey]);
                return $stmt->fetch(\PDO::FETCH_ASSOC);
            });
        } catch (\Throwable $e) {
            // DB error — fail closed, do not cache
            return null;
        }

        $token = $row['api_token'] ?? null;
        if (count($this->cache) >= self::MAX_CACHE_ENTRIES) {
            $this->cache = [];
        }
        $this->cache[$appKey] = $token;

        return $token;
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** Flush the in-memory token cache (useful after client records are modified). */
    public function flushCache(): void
    {
        $this->cache = [];
    }
}
