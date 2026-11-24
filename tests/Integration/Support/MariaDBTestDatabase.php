<?php

declare(strict_types=1);

namespace LogService\Tests\Integration\Support;

/**
 * A throwaway MariaDB for integration tests, from TEST_DB_DSN / TEST_DB_USER /
 * TEST_DB_PASS. Rebuilt once per run from migrations/*.sql.
 *
 * WARNING: every table in that database is dropped. Never point it at real data.
 */
final class MariaDBTestDatabase
{
    private static ?\PDO $pdo = null;

    /** Null when TEST_DB_DSN is not set (tests should skip). */
    public static function pdo(): ?\PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = getenv('TEST_DB_DSN');
        if (!$dsn) {
            return null;
        }

        $pdo = new \PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("DROP TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $migrations = glob(dirname(__DIR__, 3) . '/migrations/*.sql');
        sort($migrations);
        foreach ($migrations as $file) {
            $pdo->exec(file_get_contents($file));
        }

        return self::$pdo = $pdo;
    }
}
