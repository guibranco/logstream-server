<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Storage\StorageConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StorageConfigTest extends TestCase
{
    #[Test]
    public function defaults_to_file_for_both_roles(): void
    {
        $config = StorageConfig::fromEnv([]);

        self::assertSame('file', $config->controlPlane);
        self::assertSame('file', $config->logStore);
        self::assertTrue($config->isUnified());
        self::assertFalse($config->usesMariaDb());
    }

    #[Test]
    public function log_store_follows_storage_type_when_not_set(): void
    {
        $config = StorageConfig::fromEnv(['STORAGE_TYPE' => 'mariadb', 'LOG_STORE' => '']);

        self::assertSame('mariadb', $config->controlPlane);
        self::assertSame('mariadb', $config->logStore);
        self::assertTrue($config->usesMariaDb());
    }

    /** @return iterable<string, array{string, string}> */
    public static function mixed(): iterable
    {
        yield 'clients in MariaDB, entries in files' => ['mariadb', 'file'];
        yield 'single key, entries in MariaDB'       => ['file', 'mariadb'];
        yield 'clients in MariaDB, entries in ClickHouse' => ['mariadb', 'clickhouse'];
        yield 'single key, entries in ClickHouse'    => ['file', 'clickhouse'];
    }

    #[Test]
    #[DataProvider('mixed')]
    public function accepts_a_log_store_other_than_the_control_plane(string $control, string $logs): void
    {
        $config = StorageConfig::fromEnv(['STORAGE_TYPE' => $control, 'LOG_STORE' => " {$logs} "]);

        self::assertSame($control, $config->controlPlane);
        self::assertSame($logs, $config->logStore);
        self::assertFalse($config->isUnified());
        self::assertSame($control === 'mariadb' || $logs === 'mariadb', $config->usesMariaDb());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown storage type'    => [['STORAGE_TYPE' => 'postgres'], "STORAGE_TYPE must be one of: file, mariadb (got 'postgres')"];
        // Used to fall back to file mode silently; now refused instead of switching backends.
        yield 'storage type wrong case' => [['STORAGE_TYPE' => 'MariaDB'], 'STORAGE_TYPE must be one of'];
        yield 'unknown log store'       => [['LOG_STORE' => 'loki'], "LOG_STORE must be one of: file, mariadb, clickhouse (got 'loki')"];
        yield 'clickhouse is not a control plane' => [['STORAGE_TYPE' => 'clickhouse'], 'STORAGE_TYPE must be one of: file, mariadb'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function rejects_unsupported_values_at_boot(array $env, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        StorageConfig::fromEnv($env);
    }
}
