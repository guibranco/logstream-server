#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LogService\Database\Connection;
use Dotenv\Dotenv;
use LogService\Retention\DatabasePruner;
use LogService\Retention\DatabaseRetentionLogRepository;
use LogService\Retention\DatabaseRetentionPolicyRepository;
use LogService\Retention\FileStoragePruner;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionEngine;

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// ─── Load retention config ────────────────────────────────────────────────────

$configPath = $_ENV['RETENTION_CONFIG'] ?? null;

if ($configPath === null) {
    fwrite(STDERR, "[Retention] RETENTION_CONFIG is not set in .env — nothing to do.\n");
    exit(0);
}

// Resolve relative paths against the project root
if (!str_starts_with($configPath, '/') && !preg_match('#^[A-Za-z]:[/\\\\]#', $configPath)) {
    $configPath = dirname(__DIR__) . '/' . ltrim($configPath, './\\');
}

try {
    $config = RetentionConfig::fromFile($configPath);
} catch (\Throwable $e) {
    fwrite(STDERR, "[Retention] Config error: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$config->enabled) {
    echo "[Retention] Disabled — nothing to do.\n";
    exit(0);
}

// ─── Build pruner ─────────────────────────────────────────────────────────────

$storageType = $_ENV['STORAGE_TYPE'] ?? 'file';
$log         = null;

if ($storageType === 'mariadb') {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'logservice',
    );
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    $db     = Connection::wrap($pdo);
    $pruner = new DatabasePruner($db);
    $log    = new DatabaseRetentionLogRepository($db);

    // The policies live in the retention_policies table (managed from the UI
    // and the API), not in the JSON file, which only provides enabled/interval.
    try {
        $config = new RetentionConfig(
            enabled:       $config->enabled,
            intervalHours: $config->intervalHours,
            policies:      (new DatabaseRetentionPolicyRepository($db))->all(),
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, "[Retention] Could not load policies: " . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    $logPath = $_ENV['LOG_PATH'] ?? dirname(__DIR__) . '/storage/logs';
    $pruner  = new FileStoragePruner($logPath);
}

// ─── Run ──────────────────────────────────────────────────────────────────────

$engine  = new RetentionEngine($pruner, $config, $log);
$results = $engine->run();

if (empty($results)) {
    echo "[Retention] No policies ran.\n";
    exit(0);
}

$totalPruned = 0;
$failed      = 0;

foreach ($results as $result) {
    $totalPruned += $result->pruned;
    $line = "[Retention] Policy '{$result->policy}': pruned {$result->pruned} entries";
    if ($result->error !== null) {
        $failed++;
        $line .= " (ERROR: {$result->error})";
    }
    echo $line . "\n";
}

echo "[Retention] Done — {$totalPruned} entries pruned in total.\n";

exit($failed > 0 ? 1 : 0);
