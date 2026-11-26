#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionEngine;
use LogService\Storage\StorageFactory;

// ─── Arguments ────────────────────────────────────────────────────────────────
//
// prune.php always deletes and takes no options. Refuse any argument, so that
// "php bin/prune.php --dry-run" can never be mistaken for a simulation.

if ($argc > 1) {
    fwrite(STDERR, "[Retention] prune.php takes no options and always deletes.\n");
    fwrite(STDERR, "            For a dry run use: php bin/retention.php --dry-run\n");
    exit(2);
}

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
//
// The pruner deletes from the log store (LOG_STORE); the policies and the run
// history come from the control plane (STORAGE_TYPE).

try {
    $storageFactory = StorageFactory::fromEnv($_ENV, dirname(__DIR__));
    $storageFactory->connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "[Retention] " . $e->getMessage() . "\n");
    exit(1);
}

$pruner = $storageFactory->pruner();
$log    = $storageFactory->retentionLog();

if ($storageFactory->controlPlaneIsDatabase()) {
    // The policies live in the retention_policies table (managed from the UI
    // and the API), not in the JSON file, which only provides enabled/interval.
    try {
        $config = new RetentionConfig(
            enabled:       $config->enabled,
            intervalHours: $config->intervalHours,
            policies:      $storageFactory->policyRepository($configPath)->all(),
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, "[Retention] Could not load policies: " . $e->getMessage() . "\n");
        exit(1);
    }
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
