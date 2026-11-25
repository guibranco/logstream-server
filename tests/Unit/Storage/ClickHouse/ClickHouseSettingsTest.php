<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage\ClickHouse;

use LogService\Storage\ClickHouse\ClickHouseSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClickHouseSettingsTest extends TestCase
{
    #[Test]
    public function defaults_apply_when_unset_or_empty(): void
    {
        foreach ([[], ['CLICKHOUSE_URL' => ' ', 'CLICKHOUSE_DATABASE' => '', 'CLICKHOUSE_TIMEOUT' => '']] as $env) {
            $settings = ClickHouseSettings::fromEnv($env);

            self::assertSame('http://127.0.0.1:8123', $settings->url);
            self::assertSame('logstream', $settings->database);
            self::assertSame('default', $settings->user);
            self::assertSame('', $settings->password);
            self::assertSame(10.0, $settings->timeout);
        }
    }

    #[Test]
    public function reads_every_setting(): void
    {
        $settings = ClickHouseSettings::fromEnv([
            'CLICKHOUSE_URL' => 'https://ch.internal:8443/', 'CLICKHOUSE_DATABASE' => 'logs',
            'CLICKHOUSE_USER' => 'logstream', 'CLICKHOUSE_PASSWORD' => ' test only password ', 'CLICKHOUSE_TIMEOUT' => '2.5',
        ]);

        self::assertSame('https://ch.internal:8443/', $settings->url);
        self::assertSame('https://ch.internal:8443/logs', $settings->label());
        self::assertSame('logstream', $settings->user);
        self::assertSame(' test only password ', $settings->password, 'passwords are used as given');
        self::assertSame(2.5, $settings->timeout);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalid(): iterable
    {
        yield 'no scheme'        => [['CLICKHOUSE_URL' => '127.0.0.1:8123'], 'CLICKHOUSE_URL'];
        yield 'with a path'      => [['CLICKHOUSE_URL' => 'http://ch:8123/logstream'], 'CLICKHOUSE_URL'];
        yield 'native protocol'  => [['CLICKHOUSE_URL' => 'tcp://ch:9000'], 'CLICKHOUSE_URL'];
        yield 'timeout not a number' => [['CLICKHOUSE_TIMEOUT' => 'ten'], 'CLICKHOUSE_TIMEOUT'];
        yield 'timeout zero'     => [['CLICKHOUSE_TIMEOUT' => '0'], 'CLICKHOUSE_TIMEOUT'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function rejects_invalid_settings(array $env, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ClickHouseSettings::fromEnv($env);
    }
}
