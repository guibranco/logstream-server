<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\DatabaseRetentionPolicyRepository;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseRetentionPolicyRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseRetentionPolicyRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(
            'CREATE TABLE retention_policies (
                name             VARCHAR(255)  NOT NULL PRIMARY KEY,
                app_key          VARCHAR(255)  NULL,
                app_id           VARCHAR(255)  NULL,
                level            VARCHAR(50)   NULL,
                category         VARCHAR(255)  NULL,
                message_regex    TEXT          NULL,
                message_glob     TEXT          NULL,
                older_than_days  INTEGER       NULL,
                created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );

        $this->repo = new DatabaseRetentionPolicyRepository($this->pdo);
    }

    // ── create / all / find ───────────────────────────────────────────────────

    #[Test]
    public function it_creates_and_finds_a_policy(): void
    {
        $this->repo->create(RetentionPolicy::fromArray([
            'name'            => 'my-policy',
            'app_key'         => 'my-app',
            'level'           => 'debug',
            'older_than_days' => 30,
        ]));

        $found = $this->repo->find('my-policy');

        self::assertNotNull($found);
        self::assertSame('my-app', $found->appKey);
        self::assertSame('debug', $found->level);
        self::assertSame(30, $found->olderThanDays);
    }

    #[Test]
    public function find_returns_null_for_unknown_name(): void
    {
        self::assertNull($this->repo->find('unknown'));
    }

    #[Test]
    public function all_returns_empty_array_when_no_policies(): void
    {
        self::assertSame([], $this->repo->all());
    }

    #[Test]
    public function all_returns_policies_ordered_by_name(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'zebra']));
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'alpha']));

        $policies = $this->repo->all();

        self::assertCount(2, $policies);
        self::assertSame('alpha', $policies[0]->name);
        self::assertSame('zebra', $policies[1]->name);
    }

    #[Test]
    public function it_round_trips_all_optional_fields_as_null(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'bare']));

        $found = $this->repo->find('bare');

        self::assertNull($found->appKey);
        self::assertNull($found->appId);
        self::assertNull($found->level);
        self::assertNull($found->category);
        self::assertNull($found->messageRegex);
        self::assertNull($found->messageGlob);
        self::assertNull($found->olderThanDays);
    }

    // ── update ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_updates_an_existing_policy(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']));

        $this->repo->update(RetentionPolicy::fromArray([
            'name'  => 'x',
            'level' => 'error',
        ]));

        $found = $this->repo->find('x');
        self::assertSame('error', $found->level);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_deletes_a_policy(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x']));

        $this->repo->delete('x');

        self::assertNull($this->repo->find('x'));
    }

    #[Test]
    public function delete_is_a_no_op_for_an_unknown_name(): void
    {
        $this->repo->delete('unknown');
        self::assertSame([], $this->repo->all());
    }
}
