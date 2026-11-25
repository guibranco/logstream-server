<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Storage\MariaDbSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MariaDbSettingsTest extends TestCase
{
    #[Test]
    public function defaults_apply_when_unset_or_empty(): void
    {
        foreach ([[], ['DB_HOST' => '', 'DB_PORT' => ' ', 'DB_NAME' => '', 'DB_USER' => '']] as $env) {
            $settings = MariaDbSettings::fromEnv($env);

            self::assertSame('mysql:host=127.0.0.1;port=3306;dbname=logservice;charset=utf8mb4', $settings->dsn());
            self::assertSame('root', $settings->user);
            self::assertSame('', $settings->password);
        }
    }

    #[Test]
    public function reads_every_db_setting(): void
    {
        $settings = MariaDbSettings::fromEnv([
            'DB_HOST' => 'db.internal', 'DB_PORT' => '3307', 'DB_NAME' => 'logs',
            'DB_USER' => 'logstream', 'DB_PASS' => ' test only password ',
        ]);

        self::assertSame('mysql:host=db.internal;port=3307;dbname=logs;charset=utf8mb4', $settings->dsn());
        self::assertSame('db.internal:3307/logs', $settings->label());
        self::assertSame('logstream', $settings->user);
        self::assertSame(' test only password ', $settings->password, 'passwords are used as given');
    }

    #[Test]
    public function a_non_numeric_port_falls_back_to_the_default(): void
    {
        self::assertSame(3306, MariaDbSettings::fromEnv(['DB_PORT' => 'mysql'])->port);
    }
}
