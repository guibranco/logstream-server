<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Auth;

use LogService\Auth\DatabaseWriteAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Uses an in-memory SQLite database so no MariaDB instance is required.
 * The schema mirrors the real clients table.
 */
final class DatabaseWriteAuthTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseWriteAuth $auth;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec("
            CREATE TABLE clients (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL,
                app_key    TEXT    NOT NULL UNIQUE,
                api_token  TEXT    NOT NULL,
                active     INTEGER NOT NULL DEFAULT 1,
                created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            INSERT INTO clients (name, app_key, api_token, active) VALUES
                ('Billing API',  'billing-api',  'token-billing',  1),
                ('Auth Service', 'auth-service', 'token-auth',     1),
                ('Inactive App', 'inactive-app', 'token-inactive', 0)
        ");

        $this->auth = new DatabaseWriteAuth($this->pdo);
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function makeRequest(string $apiKey = '', string $apiToken = ''): ServerRequestInterface
    {
        $request = new ServerRequest('POST', 'http://localhost/api/logs');

        if ($apiKey !== '') {
            $request = $request->withHeader('X-Api-Key', $apiKey);
        }
        if ($apiToken !== '') {
            $request = $request->withHeader('X-Api-Token', $apiToken);
        }

        return $request;
    }

    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_accepts_valid_credentials(): void
    {
        $result = $this->auth->authenticate(
            $this->makeRequest('billing-api', 'token-billing')
        );

        self::assertTrue($result->ok);
        self::assertSame('billing-api', $result->appKey);
        self::assertNull($result->reason);
    }

    #[Test]
    public function it_resolves_the_correct_app_key_for_each_client(): void
    {
        $result = $this->auth->authenticate(
            $this->makeRequest('auth-service', 'token-auth')
        );

        self::assertTrue($result->ok);
        self::assertSame('auth-service', $result->appKey);
    }

    #[Test]
    public function it_rejects_a_wrong_token(): void
    {
        $result = $this->auth->authenticate(
            $this->makeRequest('billing-api', 'wrong-token')
        );

        self::assertFalse($result->ok);
        self::assertNull($result->appKey);
        self::assertStringContainsString('Invalid token', $result->reason);
    }

    #[Test]
    public function it_rejects_an_unknown_app_key(): void
    {
        $result = $this->auth->authenticate(
            $this->makeRequest('nonexistent-app', 'any-token')
        );

        self::assertFalse($result->ok);
        self::assertStringContainsString('Unknown client', $result->reason);
    }

    #[Test]
    public function it_rejects_an_inactive_client(): void
    {
        $result = $this->auth->authenticate(
            $this->makeRequest('inactive-app', 'token-inactive')
        );

        self::assertFalse($result->ok);
        self::assertStringContainsString('Unknown client', $result->reason);
    }

    #[Test]
    public function it_rejects_a_request_with_no_headers(): void
    {
        $result = $this->auth->authenticate(
            new ServerRequest('POST', 'http://localhost/api/logs')
        );

        self::assertFalse($result->ok);
        self::assertStringContainsString('Missing', $result->reason);
    }

    #[Test]
    public function it_rejects_when_only_api_key_is_present(): void
    {
        $result = $this->auth->authenticate(
            $this->makeRequest('billing-api', '')
        );

        self::assertFalse($result->ok);
        self::assertStringContainsString('Missing', $result->reason);
    }

    #[Test]
    public function it_rejects_when_only_api_token_is_present(): void
    {
        $result = $this->auth->authenticate(
            $this->makeRequest('', 'token-billing')
        );

        self::assertFalse($result->ok);
    }

    #[Test]
    public function it_caches_successful_lookups(): void
    {
        // First call — hits the DB
        $result1 = $this->auth->authenticate(
            $this->makeRequest('billing-api', 'token-billing')
        );

        // Drop the clients table to prove subsequent calls use the cache
        $this->pdo->exec('DROP TABLE clients');

        $result2 = $this->auth->authenticate(
            $this->makeRequest('billing-api', 'token-billing')
        );

        self::assertTrue($result1->ok);
        self::assertTrue($result2->ok);
        self::assertSame('billing-api', $result2->appKey);
    }

    #[Test]
    public function flush_cache_forces_a_fresh_db_lookup(): void
    {
        // Warm the cache
        $this->auth->authenticate(
            $this->makeRequest('billing-api', 'token-billing')
        );

        // Update the token in the DB
        $this->pdo->exec("UPDATE clients SET api_token = 'new-token' WHERE app_key = 'billing-api'");

        // Old token still works (cache hit)
        $cached = $this->auth->authenticate(
            $this->makeRequest('billing-api', 'token-billing')
        );
        self::assertTrue($cached->ok);

        // Flush and retry with old token — should now fail
        $this->auth->flushCache();

        $fresh = $this->auth->authenticate(
            $this->makeRequest('billing-api', 'token-billing')
        );
        self::assertFalse($fresh->ok);

        // New token should work
        $updated = $this->auth->authenticate(
            $this->makeRequest('billing-api', 'new-token')
        );
        self::assertTrue($updated->ok);
    }
}
