#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionRunner;
use LogService\Storage\StorageFactory;

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

$dryRun = isset($opts['dry-run']);

try {
    $storageFactory = StorageFactory::fromEnv($_ENV, dirname(__DIR__));
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

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
// The engine deletes from the log store (LOG_STORE). Policies and the run
// history belong to the control plane (STORAGE_TYPE): in MariaDB mode the
// policies live in the retention_policies table (the same source the server and
// the UI use) and every run is recorded in retention_log; in file mode the
// policies come from the JSON config file and runs are recorded in
// retention_log.jsonl (RETENTION_LOG_PATH), next to the log directory.

try {
    $storageFactory->connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Error connecting to the database: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$storageFactory->logStoreSupportsRetention()) {
    fwrite(STDERR, "Retention is not available yet for LOG_STORE={$storageFactory->config->logStore} "
        . "(guibranco/logstream-server#158). The table TTL removes entries after 35 days meanwhile.\n");
    exit(1);
}

$engine     = $storageFactory->retentionEngine();
$repository = $storageFactory->controlPlaneIsDatabase() ? $storageFactory->policyRepository($configPath) : null;
$log        = $storageFactory->retentionLog();
$runner     = new RetentionRunner($engine, $config, $repository, $log);

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
        if ($policy->hasAge())              { $parts[] = "older_than={$policy->ageLabel()}"; }
        if ($policy->appKey !== null)         { $parts[] = "app_key={$policy->appKey}"; }
        if ($policy->appId !== null)          { $parts[] = "app_id={$policy->appId}"; }
        if ($policy->level !== null)          { $parts[] = "level={$policy->level}"; }
        if ($policy->category !== null)       { $parts[] = "category={$policy->category}"; }
        if ($policy->transport !== null)      { $parts[] = "transport={$policy->transport}"; }
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
