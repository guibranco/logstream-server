<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\RetentionConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetentionConfigTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/retention_config_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_builds_from_array_with_all_fields(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'        => true,
            'interval_hours' => 12,
            'policies'       => [
                ['name' => 'a', 'older_than_days' => 30],
                ['name' => 'b', 'level' => 'debug'],
            ],
        ]);

        self::assertTrue($config->isEnabled());
        self::assertSame(12, $config->intervalHours);
        self::assertCount(2, $config->getPolicies());
    }

    #[Test]
    public function it_defaults_enabled_false_and_interval_24_when_absent(): void
    {
        $config = RetentionConfig::fromArray([]);

        self::assertFalse($config->isEnabled());
        self::assertSame(24, $config->intervalHours);
        self::assertSame([], $config->getPolicies());
    }

    #[Test]
    public function it_ignores_non_array_policy_entries(): void
    {
        $config = RetentionConfig::fromArray([
            'policies' => [
                ['name' => 'valid'],
                'not-an-array',
                123,
            ],
        ]);

        self::assertCount(1, $config->getPolicies());
        self::assertSame('valid', $config->getPolicies()[0]->name);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // fromFile
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_loads_from_a_valid_json_file(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'enabled'  => true,
            'policies' => [['name' => 'x', 'older_than_days' => 7]],
        ]));

        $config = RetentionConfig::fromFile($this->tmpFile);

        self::assertTrue($config->isEnabled());
        self::assertCount(1, $config->getPolicies());
    }

    #[Test]
    public function it_throws_when_the_file_does_not_exist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found or not readable');

        RetentionConfig::fromFile($this->tmpFile);
    }

    #[Test]
    public function it_throws_on_invalid_json(): void
    {
        file_put_contents($this->tmpFile, 'not valid json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');

        RetentionConfig::fromFile($this->tmpFile);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // disabled() / getPolicy()
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function disabled_returns_an_empty_disabled_config(): void
    {
        $config = RetentionConfig::disabled();

        self::assertFalse($config->isEnabled());
        self::assertSame([], $config->getPolicies());
    }

    #[Test]
    public function get_policy_returns_matching_policy_by_name(): void
    {
        $config = RetentionConfig::fromArray([
            'policies' => [
                ['name' => 'a'],
                ['name' => 'b', 'level' => 'debug'],
            ],
        ]);

        $policy = $config->getPolicy('b');

        self::assertNotNull($policy);
        self::assertSame('b', $policy->name);
        self::assertSame('debug', $policy->level);
    }

    #[Test]
    public function get_policy_returns_null_for_unknown_name(): void
    {
        $config = RetentionConfig::fromArray(['policies' => [['name' => 'a']]]);

        self::assertNull($config->getPolicy('unknown'));
    }
}
