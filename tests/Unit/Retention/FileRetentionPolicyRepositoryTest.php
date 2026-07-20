<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\FileRetentionPolicyRepository;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FileRetentionPolicyRepositoryTest extends TestCase
{
    private string $path;
    private FileRetentionPolicyRepository $repo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/retention_policies_test_' . uniqid() . '.json';
        $this->repo = new FileRetentionPolicyRepository($this->path);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    // ── all / find on a missing or empty file ────────────────────────────────

    #[Test]
    public function all_returns_empty_array_when_file_does_not_exist(): void
    {
        self::assertSame([], $this->repo->all());
    }

    #[Test]
    public function find_returns_null_when_file_does_not_exist(): void
    {
        self::assertNull($this->repo->find('anything'));
    }

    #[Test]
    public function all_returns_empty_array_for_invalid_json(): void
    {
        file_put_contents($this->path, 'not valid json');
        self::assertSame([], $this->repo->all());
    }

    #[Test]
    public function all_returns_empty_array_when_policies_key_is_not_an_array(): void
    {
        file_put_contents($this->path, json_encode(['policies' => 'nope']));
        self::assertSame([], $this->repo->all());
    }

    #[Test]
    public function it_reads_a_bare_array_without_a_policies_wrapper(): void
    {
        file_put_contents($this->path, json_encode([
            ['name' => 'a'],
            ['name' => 'b'],
        ]));

        self::assertCount(2, $this->repo->all());
    }

    #[Test]
    public function it_ignores_non_array_entries_in_the_policy_list(): void
    {
        file_put_contents($this->path, json_encode([
            'policies' => [['name' => 'valid'], 'garbage', 42],
        ]));

        $policies = $this->repo->all();

        self::assertCount(1, $policies);
        self::assertSame('valid', $policies[0]->name);
    }

    // ── create ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_and_persists_a_policy(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']));

        $found = $this->repo->find('x');
        self::assertNotNull($found);
        self::assertSame('debug', $found->level);
    }

    #[Test]
    public function create_throws_on_duplicate_name(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Policy 'x' already exists.");

        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x']));
    }

    #[Test]
    public function created_policy_survives_a_fresh_repository_instance(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x', 'app_key' => 'my-app']));

        $reloaded = new FileRetentionPolicyRepository($this->path);
        $found    = $reloaded->find('x');

        self::assertNotNull($found);
        self::assertSame('my-app', $found->appKey);
    }

    #[Test]
    public function it_omits_null_fields_when_persisting(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'bare']));

        $raw = json_decode((string) file_get_contents($this->path), true);

        self::assertArrayNotHasKey('app_key', $raw['policies'][0]);
        self::assertArrayNotHasKey('older_than_days', $raw['policies'][0]);
    }

    // ── update ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_updates_an_existing_policy(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']));

        $this->repo->update(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'error']));

        self::assertSame('error', $this->repo->find('x')->level);
    }

    #[Test]
    public function update_throws_for_an_unknown_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Policy 'unknown' not found.");

        $this->repo->update(RetentionPolicy::fromArray(['name' => 'unknown']));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_deletes_a_policy(): void
    {
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'x']));
        $this->repo->create(RetentionPolicy::fromArray(['name' => 'y']));

        $this->repo->delete('x');

        self::assertNull($this->repo->find('x'));
        self::assertNotNull($this->repo->find('y'));
    }

    #[Test]
    public function delete_throws_for_an_unknown_name(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Policy 'unknown' not found.");

        $this->repo->delete('unknown');
    }
}
