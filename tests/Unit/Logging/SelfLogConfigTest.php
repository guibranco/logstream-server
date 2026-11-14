<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Logging;

use LogService\Logging\SelfLogConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelfLogConfigTest extends TestCase
{
    #[Test]
    public function is_enabled_at_info_level_for_this_host_by_default(): void
    {
        $config = SelfLogConfig::fromEnv([]);

        self::assertTrue($config->enabled);
        self::assertSame('info', $config->minLevel);
        self::assertSame(substr(gethostname() ?: 'default', 0, 100), $config->appId);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function booleans(): iterable
    {
        yield 'false' => ['false', false];
        yield '0'     => ['0', false];
        yield 'no'    => ['no', false];
        yield 'OFF'   => ['OFF', false];
        yield 'true'  => ['true', true];
        yield '1'     => ['1', true];
        yield 'Yes'   => ['Yes', true];
        yield 'empty' => ['', true];
    }

    #[Test]
    #[DataProvider('booleans')]
    public function reads_self_log_enabled(string $raw, bool $expected): void
    {
        self::assertSame($expected, SelfLogConfig::fromEnv(['SELF_LOG_ENABLED' => $raw])->enabled);
    }

    #[Test]
    public function rejects_an_unclear_enabled_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SELF_LOG_ENABLED');

        SelfLogConfig::fromEnv(['SELF_LOG_ENABLED' => 'maybe']);
    }

    #[Test]
    public function reads_the_level_and_app_id(): void
    {
        $config = SelfLogConfig::fromEnv(['SELF_LOG_LEVEL' => ' Warning ', 'SELF_LOG_APP_ID' => 'vinhedo2']);

        self::assertSame('warning', $config->minLevel);
        self::assertSame('vinhedo2', $config->appId);
    }

    #[Test]
    public function rejects_an_unknown_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SELF_LOG_LEVEL');

        SelfLogConfig::fromEnv(['SELF_LOG_LEVEL' => 'verbose']);
    }

    #[Test]
    public function rejects_an_app_id_longer_than_the_column(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SelfLogConfig::fromEnv(['SELF_LOG_APP_ID' => str_repeat('x', 101)]);
    }

    #[Test]
    public function the_reserved_app_key_matches_regardless_of_case_and_spaces(): void
    {
        self::assertTrue(SelfLogConfig::isReservedAppKey('logstream-server'));
        self::assertTrue(SelfLogConfig::isReservedAppKey(' LogStream-Server '));
        self::assertFalse(SelfLogConfig::isReservedAppKey('logstream-server-2'));
        self::assertFalse(SelfLogConfig::isReservedAppKey(null));
    }
}
