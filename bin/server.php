#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LogService\Auth\DatabaseWriteAuth;
use LogService\Auth\SecretGenerator;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Database\Connection;
use LogService\Http\ClientIpResolver;
use LogService\Http\Router;
use LogService\Repository\ClientRepository;
use LogService\Retention\DatabaseRetentionEngine;
use LogService\Retention\DatabaseRetentionPolicyRepository;
use LogService\Retention\FileRetentionEngine;
use LogService\Retention\FileRetentionPolicyRepository;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionRunner;
use LogService\Storage\FileStorage;
use LogService\Storage\MariaDBStorage;
use LogService\WebSocket\LogHub;
use Ratchet\Http\HttpServer as RatchetHttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Socket\SocketServer;

// ─── Bootstrap ────────────────────────────────────────────────────────────────

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$storageType = $_ENV['STORAGE_TYPE'] ?? 'file';
$httpPort    = (int)($_ENV['HTTP_PORT'] ?? 8081);
$wsPort      = (int)($_ENV['WS_PORT']   ?? 8080);
$apiSecret   = $_ENV['API_SECRET']   ?? '';
$uiSecret    = $_ENV['UI_SECRET']    ?? '';
$appVersion  = $_ENV['APP_VERSION']  ?? 'dev';
$ipResolver  = ClientIpResolver::fromString($_ENV['TRUSTED_PROXIES'] ?? '');

// ─── Storage + Write Auth ─────────────────────────────────────────────────────

$writeAuthDb      = null;
$clientRepository = null;

if ($storageType === 'mariadb') {
    $dsn  = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'logservice',
    );
    $dbUser = $_ENV['DB_USER'] ?? 'root';
    $dbPass = $_ENV['DB_PASS'] ?? '';

    // Both connections re-open themselves if MariaDB drops them while idle
    // (wait_timeout, VPN/NAT idle timeouts), instead of failing with
    // "MySQL server has gone away" until the server restarts.
    $appDb      = Connection::mysql($dsn, $dbUser, $dbPass);
    $storageDb  = Connection::mysql($dsn, $dbUser, $dbPass, ["SET time_zone = '+00:00'"]);
    $storageDb->pdo(); // fail fast on bad credentials at boot

    $storage          = new MariaDBStorage($storageDb);
    $writeAuthDb      = new DatabaseWriteAuth($appDb);
    $writeAuth        = $writeAuthDb;
    try {
        $appTokens = SecretGenerator::forAppTokens($_ENV);
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, '[Config]   APP_TOKEN_PREFIX: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $clientRepository = new ClientRepository($appDb, $appTokens);

    $authMode = 'per-client (X-Api-Key / X-Api-Token  →  clients table)';
    echo "[Storage]  MariaDB ({$_ENV['DB_HOST']}:{$_ENV['DB_PORT']}/{$_ENV['DB_NAME']})\n";
    echo "[Clients]  Client management enabled\n";
} else {
    $logPath   = $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs';
    $storage   = new FileStorage($logPath);
    $writeAuth = new SingleKeyWriteAuth($apiSecret);

    $authMode = 'single key (Authorization: Bearer API_SECRET)';
    echo "[Storage]  File ({$logPath})\n";
}

if (empty($uiSecret)) {
    echo "[WARN]     UI_SECRET is not set — read endpoints are unprotected!\n";
}

// ─── Retention ────────────────────────────────────────────────────────────────

$retentionRunner     = null;
$policyRepository    = null;
$retentionConfig     = RetentionConfig::disabled();

$retentionConfigPath = $_ENV['RETENTION_CONFIG'] ?? null;
if ($retentionConfigPath !== null) {
    if (!str_starts_with($retentionConfigPath, '/') && !preg_match('#^[A-Za-z]:[/\\\\]#', $retentionConfigPath)) {
        $retentionConfigPath = __DIR__ . '/../' . ltrim($retentionConfigPath, './\\');
    }
    try {
        $retentionConfig = RetentionConfig::fromFile($retentionConfigPath);
    } catch (\Throwable $e) {
        echo "[WARN]     Retention config error: " . $e->getMessage() . "\n";
    }
}

if ($retentionConfig->isEnabled()) {
    if ($storageType === 'mariadb') {
        $retentionEngine  = new DatabaseRetentionEngine($appDb);
        $policyRepository = new DatabaseRetentionPolicyRepository($appDb);
    } else {
        $retentionEngine  = new FileRetentionEngine($logPath ?? (__DIR__ . '/../storage/logs'));
        $policyRepository = new FileRetentionPolicyRepository(
            $retentionConfigPath ?? (__DIR__ . '/../retention.json'),
        );
    }

    $retentionRunner = new RetentionRunner($retentionEngine, $retentionConfig, $policyRepository);

    // Count what the runner will actually use: in MariaDB mode the policies
    // live in the retention_policies table, not in the JSON file.
    try {
        $policyCount = count($retentionRunner->listPolicies());
        $policyLabel = "{$policyCount} polic" . ($policyCount === 1 ? 'y' : 'ies');
    } catch (\Throwable $e) {
        $policyLabel = 'policies unavailable (' . $e->getMessage() . ')';
    }
    echo "[Retention] Enabled — {$policyLabel}, interval: {$retentionConfig->intervalHours}h\n";
} else {
    echo "[Retention] Disabled\n";
}

// ─── WebSocket hub ────────────────────────────────────────────────────────────

$loop = Loop::get();
$hub  = new LogHub($uiSecret);

$wsSocket = new SocketServer("0.0.0.0:{$wsPort}", [], $loop);
$wsServer = new IoServer(
    new RatchetHttpServer(new WsServer($hub)),
    $wsSocket,
    $loop,
);

// ─── HTTP API ─────────────────────────────────────────────────────────────────

$router = new Router(
    storage:          $storage,
    hub:              $hub,
    writeAuth:        $writeAuth,
    uiSecret:         $uiSecret,
    storageType:      $storageType,
    version:          $appVersion,
    retentionRunner:  $retentionRunner,
    policyRepository: $policyRepository,
    clientRepository: $clientRepository,
    writeAuthDb:      $writeAuthDb,
    ipResolver:       $ipResolver,
);

$httpServer = new HttpServer(
    new RequestBodyBufferMiddleware(4 * 1024 * 1024),
    new RequestBodyParserMiddleware(),
    function ($request) use ($router) {
        return $router->handle($request);
    },
);

$httpSocket = new SocketServer("0.0.0.0:{$httpPort}", [], $loop);
$httpServer->listen($httpSocket);

$httpServer->on('error', function (\Throwable $e) {
    echo '[HTTP] Error: ' . $e->getMessage() . "\n";
});

// ─── Start ────────────────────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════╗\n";
echo "║           LogService started             ║\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  HTTP API  →  http://0.0.0.0:{$httpPort}       ║\n";
echo "║  WebSocket →  ws://0.0.0.0:{$wsPort}          ║\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  Write auth : {$authMode}\n";
echo "║  Read key   : " . (empty($uiSecret) ? '⚠️  NOT SET' : '✅ set') . "\n";
echo "╚══════════════════════════════════════════╝\n\n";

// ─── Retention periodic timer ─────────────────────────────────────────────────

if ($retentionRunner !== null) {
    $retentionIntervalHours = max(1, (int) $retentionConfig->intervalHours);

    if ($retentionIntervalHours !== (int) $retentionConfig->intervalHours) {
        echo "[Retention] interval_hours={$retentionConfig->intervalHours} is too small; clamping to {$retentionIntervalHours} hour(s).\n";
    }

    $retentionIntervalSeconds = $retentionIntervalHours * 3600;

    $loop->addPeriodicTimer($retentionIntervalSeconds, function () use ($retentionRunner) {
        echo '[Retention] Running scheduled policies...' . "\n";
        // Loading the policies can fail (e.g. MariaDB unreachable). An exception
        // escaping a timer stops the event loop, so log it and retry next tick.
        try {
            $results = $retentionRunner->runAll();
        } catch (\Throwable $e) {
            echo '[Retention] Scheduled run failed: ' . $e->getMessage() . "\n";
            return;
        }
        $totalPruned = array_sum(array_map(fn($r) => $r->pruned, $results));
        foreach ($results as $r) {
            $line = "[Retention] Policy '{$r->policy}': {$r->summary}";
            if ($r->error !== null) {
                $line .= " (ERROR: {$r->error})";
            }
            echo $line . "\n";
        }
        echo "[Retention] Done — {$totalPruned} entries pruned.\n";
    });
}

$loop->run();
