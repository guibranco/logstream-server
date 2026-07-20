<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Repository;

use LogService\Repository\Client;
use LogService\Repository\ClientRepository;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class ClientRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ClientRepository $repo;

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
            CREATE TABLE log_entries (
                id        TEXT PRIMARY KEY,
                app_key   TEXT NOT NULL,
                message   TEXT NOT NULL DEFAULT ''
            )
        ");

        $this->repo = new ClientRepository($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    #[Test]
    public function create_returns_client_with_raw_token(): void
    {
        $client = $this->repo->create('Billing API', 'billing-api');

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('billing-api', $client->appKey);
        self::assertSame('Billing API', $client->name);
        self::assertTrue($client->active);
        self::assertNotNull($client->rawToken);
        self::assertGreaterThan(30, strlen($client->rawToken));
    }

    #[Test]
    public function create_throws_on_duplicate_app_key(): void
    {
        $this->repo->create('First', 'billing-api');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->repo->create('Second', 'billing-api');
    }

    #[Test]
    public function create_stores_token_in_database(): void
    {
        $client = $this->repo->create('Billing API', 'billing-api');

        $row = $this->pdo->query(
            "SELECT api_token FROM clients WHERE app_key = 'billing-api'"
        )->fetch();

        self::assertSame($client->rawToken, $row['api_token']);
    }

    // ── all + findByKey ───────────────────────────────────────────────────────

    #[Test]
    public function all_returns_empty_array_when_no_clients(): void
    {
        self::assertSame([], $this->repo->all());
    }

    #[Test]
    public function all_returns_clients_ordered_by_name(): void
    {
        $this->repo->create('Zebra Service', 'zebra');
        $this->repo->create('Alpha Service', 'alpha');

        $clients = $this->repo->all();

        self::assertCount(2, $clients);
        self::assertSame('alpha', $clients[0]->appKey);
        self::assertSame('zebra', $clients[1]->appKey);
    }

    #[Test]
    public function all_never_exposes_raw_token(): void
    {
        $this->repo->create('Billing API', 'billing-api');

        $clients = $this->repo->all();

        self::assertNull($clients[0]->rawToken);
    }

    #[Test]
    public function find_by_key_returns_null_for_unknown_key(): void
    {
        self::assertNull($this->repo->findByKey('nonexistent'));
    }

    #[Test]
    public function find_by_key_returns_client_without_raw_token(): void
    {
        $this->repo->create('Billing API', 'billing-api');

        $found = $this->repo->findByKey('billing-api');

        self::assertNotNull($found);
        self::assertNull($found->rawToken);
        self::assertSame('billing-api', $found->appKey);
    }

    // ── update ────────────────────────────────────────────────────────────────

    #[Test]
    public function update_changes_name_and_active(): void
    {
        $this->repo->create('Old Name', 'my-app');

        $updated = $this->repo->update('my-app', 'New Name', false);

        self::assertSame('New Name', $updated->name);
        self::assertFalse($updated->active);
    }

    #[Test]
    public function update_throws_for_unknown_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->repo->update('nonexistent', 'Name', true);
    }

    #[Test]
    public function update_does_not_expose_token(): void
    {
        $this->repo->create('My App', 'my-app');
        $updated = $this->repo->update('my-app', 'New Name', true);

        self::assertNull($updated->rawToken);
    }

    // ── rotateToken ───────────────────────────────────────────────────────────

    #[Test]
    public function rotate_token_returns_new_token(): void
    {
        $created = $this->repo->create('My App', 'my-app');
        $rotated = $this->repo->rotateToken('my-app');

        self::assertNotNull($rotated->rawToken);
        self::assertNotSame($created->rawToken, $rotated->rawToken);
    }

    #[Test]
    public function rotate_token_updates_stored_token(): void
    {
        $this->repo->create('My App', 'my-app');
        $rotated = $this->repo->rotateToken('my-app');

        $row = $this->pdo->query(
            "SELECT api_token FROM clients WHERE app_key = 'my-app'"
        )->fetch();

        self::assertSame($rotated->rawToken, $row['api_token']);
    }

    #[Test]
    public function rotate_token_throws_for_unknown_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->repo->rotateToken('nonexistent');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    #[Test]
    public function delete_removes_client_record(): void
    {
        $this->repo->create('My App', 'my-app');
        $this->repo->delete('my-app');

        self::assertNull($this->repo->findByKey('my-app'));
    }

    #[Test]
    public function delete_cascades_to_log_entries(): void
    {
        $this->repo->create('My App', 'my-app');

        $this->pdo->exec(
            "INSERT INTO log_entries (id, app_key, message) VALUES
             ('id1', 'my-app', 'log 1'),
             ('id2', 'my-app', 'log 2'),
             ('id3', 'other-app', 'other log')"
        );

        $deleted = $this->repo->delete('my-app');

        self::assertSame(2, $deleted);

        $remaining = (int) $this->pdo->query('SELECT COUNT(*) FROM log_entries')->fetchColumn();
        self::assertSame(1, $remaining);
    }

    #[Test]
    public function delete_returns_zero_when_no_log_entries(): void
    {
        $this->repo->create('My App', 'my-app');
        $deleted = $this->repo->delete('my-app');

        self::assertSame(0, $deleted);
    }

    #[Test]
    public function delete_throws_for_unknown_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->repo->delete('nonexistent');
    }

    #[Test]
    public function delete_rolls_back_and_wraps_the_exception_on_failure(): void
    {
        $this->repo->create('My App', 'my-app');

        // Force the DELETE FROM log_entries statement inside the transaction to fail.
        $this->pdo->exec('DROP TABLE log_entries');

        try {
            $this->repo->delete('my-app');
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Failed to delete client 'my-app'", $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertNotNull($this->repo->findByKey('my-app'), 'client should survive a rolled-back delete');
    }

    // ── toPublicArray / toCreatedArray ────────────────────────────────────────

    #[Test]
    public function to_public_array_never_contains_token(): void
    {
        $client = $this->repo->create('My App', 'my-app');
        $array  = $client->toPublicArray();

        self::assertArrayNotHasKey('api_token', $array);
        self::assertArrayHasKey('name',       $array);
        self::assertArrayHasKey('app_key',    $array);
        self::assertArrayHasKey('active',     $array);
        self::assertArrayHasKey('created_at', $array);
        self::assertArrayHasKey('updated_at', $array);
    }

    #[Test]
    public function to_created_array_contains_token_and_note(): void
    {
        $client = $this->repo->create('My App', 'my-app');
        $array  = $client->toCreatedArray();

        self::assertArrayHasKey('api_token', $array);
        self::assertArrayHasKey('_note',     $array);
        self::assertSame($client->rawToken, $array['api_token']);
    }
}
