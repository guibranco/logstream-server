#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LogService\Database\Connection;
use Dotenv\Dotenv;
use LogService\Retention\DatabaseRetentionEngine;
use LogService\Retention\DatabaseRetentionLogRepository;
use LogService\Retention\DatabaseRetentionPolicyRepository;
use LogService\Retention\FileRetentionEngine;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionRunner;

// ─── Parse CLI arguments ───────────────────────────────────────────────────────

$opts = getopt('', ['dry-run', 'policy:', 'list', 'config:', 'help']);

if (isset($opts['help'])) {
    echo <<<'HELP'
Usage: php bin/retention.php [OPTIONS]

Options:
  --dry-run           Simulate run without deleting anything
  --policy=<name>     Run a single named policy
  --list              List policies (MariaDB mode: from the retention_policies table)
  --config=<path>     Path to retention config JSON (overrides RETENTION_CONFIG env)
  --help              Show this help message

HELP;
    exit(0);
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$storageType = $_ENV['STORAGE_TYPE'] ?? 'file';
$dryRun      = isset($opts['dry-run']);

// ─── Resolve config path ──────────────────────────────────────────────────────

$configPath = $opts['config'] ?? $_ENV['RETENTION_CONFIG'] ?? null;

if ($configPath === null) {
    fwrite(STDERR, "Error: No retention config specified. Use --config=<path> or set RETENTION_CONFIG in .env\n");
    exit(1);
}

if (!str_starts_with($configPath, '/') && !preg_match('#^[A-Za-z]:[/\\\\]#', $configPath)) {
    $configPath = __DIR__ . '/../' . ltrim($configPath, './\\');
}

try {
    $config = RetentionConfig::fromFile($configPath);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error loading config: " . $e->getMessage() . "\n");
    exit(1);
}

// ─── Build engine and runner ──────────────────────────────────────────────────
//
// In MariaDB mode the policies live in the retention_policies table (the same
// source the server and the UI use) and every run is recorded in retention_log.
// In file mode the policies come from the JSON config file.

$repository = null;
$log        = null;

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
    $db         = Connection::wrap($pdo);
    $engine     = new DatabaseRetentionEngine($db);
    $repository = new DatabaseRetentionPolicyRepository($db);
    $log        = new DatabaseRetentionLogRepository($db);
} else {
    $logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs';
    $engine  = new FileRetentionEngine($logPath);
}

$runner = new RetentionRunner($engine, $config, $repository, $log);

// ─── List mode ────────────────────────────────────────────────────────────────

if (isset($opts['list'])) {
    try {
        $policies = $runner->listPolicies();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Error loading policies: " . $e->getMessage() . "\n");
        exit(1);
    }

    if (empty($policies)) {
        echo "No policies configured.\n";
        exit(0);
    }

    $source = $repository !== null ? 'retention_policies table' : $configPath;
    echo "Configured policies ({$source}):\n";
    foreach ($policies as $policy) {
        $parts = ["  * {$policy->name}"];
        if ($policy->olderThanDays !== null) { $parts[] = "older_than={$policy->olderThanDays}d"; }
        if ($policy->appKey !== null)         { $parts[] = "app_key={$policy->appKey}"; }
        if ($policy->appId !== null)          { $parts[] = "app_id={$policy->appId}"; }
        if ($policy->level !== null)          { $parts[] = "level={$policy->level}"; }
        if ($policy->category !== null)       { $parts[] = "category={$policy->category}"; }
        if ($policy->messageRegex !== null)   { $parts[] = "message_regex={$policy->messageRegex}"; }
        if ($policy->messageGlob !== null)    { $parts[] = "message_glob={$policy->messageGlob}"; }
        echo implode('  ', $parts) . "\n";
    }

    exit(0);
}

// ─── Run ──────────────────────────────────────────────────────────────────────

if ($dryRun) {
    echo "[Retention] DRY RUN — no entries will be deleted\n";
}

$policyName = $opts['policy'] ?? null;

try {
    if ($policyName !== null) {
        $results = [$runner->runPolicy($policyName, $dryRun)];
    } else {
        $results = $runner->runAll($dryRun);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

$totalPruned = 0;
$failed      = 0;

foreach ($results as $result) {
    $totalPruned += $result->pruned;
    echo "[Retention] {$result->policy}: {$result->summary}\n";

    if ($result->error !== null) {
        $failed++;
        fwrite(STDERR, "  [ERROR] {$result->policy}: {$result->error}\n");
    }

    foreach ($result->warnings as $warning) {
        echo "  [WARN] {$warning}\n";
    }
}

$verb = $dryRun ? 'would be pruned' : 'pruned';
echo "[Retention] Done — {$totalPruned} entries {$verb}"
    . ($failed > 0 ? ", {$failed} polic" . ($failed === 1 ? 'y' : 'ies') . ' failed' : '')
    . ".\n";

exit($failed > 0 ? 1 : 0);
