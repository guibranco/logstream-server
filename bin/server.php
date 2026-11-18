#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The server's own logger goes first so deprecations raised while loading
// classes are caught. It prints to the console and, unless SELF_LOG_ENABLED=false,
// stores everything as entries of the reserved application "logstream-server".
$logger = new LogService\Logging\ServerLogger(projectRoot: dirname(__DIR__));
$logger->registerPhpHandlers();

use Dotenv\Dotenv;
use LogService\Auth\DatabaseWriteAuth;
use LogService\Auth\SecretGenerator;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Database\Connection;
use LogService\Health\ClientActivityTracker;
use LogService\Health\ClientSilenceMonitor;
use LogService\Health\WebhookSilenceNotifier;
use LogService\Http\ClientIpResolver;
use LogService\Http\FailedRequestLogger;
use LogService\Http\Router;
use LogService\Ingest\AsyncMysqlBatchWriter;
use LogService\Ingest\EntrySink;
use LogService\Ingest\IngestBuffer;
use LogService\Ingest\IngestConfig;
use LogService\Ingest\StorageBatchWriter;
use LogService\Logging\SelfLogConfig;
use LogService\Logging\SelfLogSink;
use LogService\Repository\ClientRepository;
use LogService\Retention\CachedRetentionVolumeAnalyzer;
use LogService\Retention\DatabaseRetentionEngine;
use LogService\Retention\DatabaseRetentionLogRepository;
use LogService\Retention\FileRetentionLogRepository;
use LogService\Retention\DatabaseRetentionPolicyRepository;
use LogService\Retention\DatabaseRetentionVolumeAnalyzer;
use LogService\Retention\FileRetentionEngine;
use LogService\Retention\FileRetentionPolicyRepository;
use LogService\Retention\FileRetentionVolumeAnalyzer;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionRunner;
use LogService\Storage\FileStorage;
use LogService\Storage\MariaDBStorage;
use LogService\Udp\UdpConfig;
use LogService\Udp\UdpLogServer;
use LogService\Udp\UdpPacketValidator;
use LogService\WebSocket\LogHub;
use Ratchet\Http\HttpServer as RatchetHttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Http\Middleware\StreamingRequestMiddleware;
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
// DEMO_MODE=true: serve the /demo page and /api/demo/* fake-traffic endpoints.
$demoMode    = in_array(strtolower(trim((string) ($_ENV['DEMO_MODE'] ?? ''))), ['1', 'true', 'yes', 'on'], true);

try {
    $ingestConfig  = IngestConfig::fromEnv($_ENV);
    $selfLogConfig = SelfLogConfig::fromEnv($_ENV);
    $udpConfig     = UdpConfig::fromEnv($_ENV);
    $udpConfig->assertNoPortClash($httpPort, $wsPort);
} catch (\InvalidArgumentException $e) {
    $logger->critical('config', $e->getMessage());
    exit(1);
}

$logger->configure($selfLogConfig, $appVersion);

// ─── Storage + Write Auth ─────────────────────────────────────────────────────

$writeAuthDb      = null;
$clientRepository = null;
$clientActivity   = null;

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
        $logger->critical('config', 'APP_TOKEN_PREFIX: ' . $e->getMessage());
        exit(1);
    }
    $clientRepository = new ClientRepository($appDb, $appTokens);
    // last_seen_at: kept in memory on ingestion, written every few seconds.
    $clientActivity   = new ClientActivityTracker($clientRepository, log: $logger->channel('clients'));

    $authMode = 'per-client (X-Api-Key / X-Api-Token  →  clients table)';
    $logger->info('storage', "MariaDB ({$_ENV['DB_HOST']}:{$_ENV['DB_PORT']}/{$_ENV['DB_NAME']})");
    $logger->info('clients', 'Client management enabled');
} else {
    $logPath   = $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs';
    $storage   = new FileStorage($logPath);
    $writeAuth = new SingleKeyWriteAuth($apiSecret);

    $authMode = 'single key (Authorization: Bearer API_SECRET)';
    $logger->info('storage', "File ({$logPath})");
}

if (empty($uiSecret)) {
    $logger->warning('config', 'UI_SECRET is not set — read endpoints are unprotected!');
}

// ─── Retention ────────────────────────────────────────────────────────────────

$retentionRunner     = null;
$policyRepository    = null;
$volumeAnalyzer      = null;
$retentionLog        = null;
$retentionConfig     = RetentionConfig::disabled();

$retentionConfigPath = $_ENV['RETENTION_CONFIG'] ?? null;
if ($retentionConfigPath !== null) {
    if (!str_starts_with($retentionConfigPath, '/') && !preg_match('#^[A-Za-z]:[/\\\\]#', $retentionConfigPath)) {
        $retentionConfigPath = __DIR__ . '/../' . ltrim($retentionConfigPath, './\\');
    }
    try {
        $retentionConfig = RetentionConfig::fromFile($retentionConfigPath);
    } catch (\Throwable $e) {
        $logger->warning('retention', 'Retention config error: ' . $e->getMessage());
    }
}

if ($retentionConfig->isEnabled()) {
    if ($storageType === 'mariadb') {
        $retentionEngine  = new DatabaseRetentionEngine($appDb);
        $policyRepository = new DatabaseRetentionPolicyRepository($appDb);
        $retentionLog     = new DatabaseRetentionLogRepository($appDb);
        $volumeAnalyzer   = new CachedRetentionVolumeAnalyzer(new DatabaseRetentionVolumeAnalyzer($appDb));
    } else {
        $retentionEngine  = new FileRetentionEngine($logPath ?? (__DIR__ . '/../storage/logs'));
        $policyRepository = new FileRetentionPolicyRepository(
            $retentionConfigPath ?? (__DIR__ . '/../retention.json'),
        );
        $volumeAnalyzer   = new CachedRetentionVolumeAnalyzer(
            new FileRetentionVolumeAnalyzer($logPath ?? (__DIR__ . '/../storage/logs')),
        );
        $retentionLog     = new FileRetentionLogRepository(
            $_ENV['RETENTION_LOG_PATH'] ?? dirname($logPath ?? (__DIR__ . '/../storage/logs')) . '/retention_log.jsonl',
        );
    }

    $retentionRunner = new RetentionRunner($retentionEngine, $retentionConfig, $policyRepository, $retentionLog);

    // Count what the runner will actually use: in MariaDB mode the policies
    // live in the retention_policies table, not in the JSON file.
    try {
        $policyCount = count($retentionRunner->listPolicies());
        $policyLabel = "{$policyCount} polic" . ($policyCount === 1 ? 'y' : 'ies');
    } catch (\Throwable $e) {
        $policyLabel = 'policies unavailable (' . $e->getMessage() . ')';
    }
    $logger->info('retention', "Enabled — {$policyLabel}, interval: {$retentionConfig->intervalHours}h");
} else {
    $logger->info('retention', 'Disabled');
}

// ─── WebSocket hub ────────────────────────────────────────────────────────────

$loop = Loop::get();
$hub  = new LogHub($uiSecret);

// ─── Ingest buffer ────────────────────────────────────────────────────────────
// INGEST_MODE=buffered (default): POST /api/logs queues entries, answers 202,
// and a timer writes them in batches. INGEST_MODE=sync: write, then answer 201.

$ingestBuffer = null;
$asyncWriter  = null;

if ($ingestConfig->isBuffered()) {
    if ($storageType === 'mariadb' && $ingestConfig->dbDriver === IngestConfig::DRIVER_ASYNC) {
        $asyncWriter = AsyncMysqlBatchWriter::connect(
            host:     $_ENV['DB_HOST'] ?? '127.0.0.1',
            port:     (int) ($_ENV['DB_PORT'] ?? 3306),
            database: $_ENV['DB_NAME'] ?? 'logservice',
            user:     $_ENV['DB_USER'] ?? 'root',
            password: $_ENV['DB_PASS'] ?? '',
        );
        $batchWriter = $asyncWriter;
    } else {
        $batchWriter = new StorageBatchWriter($storage, $storageType === 'mariadb' ? 'pdo-mysql' : 'file');
    }

    $ingestBuffer = IngestBuffer::fromConfig(
        $batchWriter,
        $ingestConfig,
        // Broadcast only what is stored, so the live view never shows an entry search can't find.
        static function (array $entries) use ($hub, $clientActivity): void {
            foreach ($entries as $entry) {
                $hub->broadcast($entry);
            }
            // Only stored entries count as "seen" (self-log entries are skipped by the tracker).
            $clientActivity?->touch(...array_map(static fn($entry) => $entry->appKey, $entries));
        },
        $logger->channel('ingest'),
    );
    $ingestBuffer->start();

    $logger->info('ingest', sprintf(
        'Buffered — writer: %s, batch: %d, every %dms, capacity: %d',
        $batchWriter->name(),
        $ingestConfig->batchSize,
        $ingestConfig->flushIntervalMs,
        $ingestConfig->capacity,
    ));
} else {
    $logger->info('ingest', 'Synchronous — every entry is written before the response');
}

// ─── Self-logging ─────────────────────────────────────────────────────────────
// From here on (and for everything logged above) the server's own messages are
// stored like any client's: through the ingest buffer, or directly in sync mode.

if ($selfLogConfig->enabled) {
    $logger->attach(new SelfLogSink($storage, $hub, $ingestBuffer));
    $logger->info('selflog', sprintf(
        "Enabled — app_key: %s, app_id: %s, level ≥ %s, trace_id: %s",
        SelfLogConfig::APP_KEY,
        $selfLogConfig->appId,
        $selfLogConfig->minLevel,
        $logger->traceId(),
    ));
} else {
    $logger->info('selflog', 'Disabled (SELF_LOG_ENABLED=false)');
}
$logger->info('http', $selfLogConfig->httpErrors
    ? 'Failed requests (4XX/5XX) are logged'
    : 'Failed requests are not logged (SELF_LOG_HTTP_ERRORS=false)');

if ($demoMode) {
    $logger->warning('demo', 'DEMO_MODE is on: /demo and /api/demo/run accept fake traffic without a key');
}

// ─── UDP ingest ───────────────────────────────────────────────────────────────
// HMAC-signed packets on UDP_PORT, stored through the same pipeline as
// POST /api/logs. The HMAC key is the client's api_token (MariaDB, shared with
// the HTTP token cache) or API_SECRET (file mode; empty = every packet rejected).

$udpServer = null;

if ($udpConfig->enabled) {
    $udpTokenLookup = $writeAuthDb !== null
        ? static fn(string $appKey): ?string => $writeAuthDb->tokenFor($appKey)
        : static fn(string $appKey): ?string => $apiSecret !== '' ? $apiSecret : null;

    if ($writeAuthDb === null && $apiSecret === '') {
        $logger->warning('udp', 'API_SECRET is empty — every UDP packet will be rejected (UDP always requires a secret)');
    }

    $udpServer = new UdpLogServer(
        UdpPacketValidator::fromConfig($udpConfig, $udpTokenLookup),
        new EntrySink($storage, $hub, $ingestBuffer, $clientActivity),
        $udpConfig->allowList(),
        $udpConfig->address(),
        $logger->channel('udp'),
    );
    $udpServer->start($loop);
} else {
    $logger->info('udp', 'Disabled (UDP_ENABLED=false)');
}

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
    volumeAnalyzer:   $volumeAnalyzer,
    ingestBuffer:     $ingestBuffer,
    ingestWaitSeconds: $ingestConfig->waitTimeoutMs / 1000,
    ingestDbDriver:   $ingestConfig->dbDriver,
    selfLogging:      $selfLogConfig->enabled,
    demoMode:         $demoMode,
    clientActivity:   $clientActivity,
    udpServer:        $udpServer,
    udpConfig:        $udpConfig,
    retentionLog:     $retentionLog,
);

// StreamingRequestMiddleware must come first: without it HttpServer prepends its
// own body buffer capped at 64 KiB, which silently empties any larger body
// before the 4 MiB buffer below ever runs (big batches → "Invalid JSON body").
// With streaming on, HttpServer adds no concurrency limit either, so set one.
// FailedRequestLogger (SELF_LOG_HTTP_ERRORS, on by default) writes every 4XX/5XX
// response to the server's own log under the category "http".
$httpServer = new HttpServer(...array_values(array_filter([
    new StreamingRequestMiddleware(),
    new LimitConcurrentRequestsMiddleware(100),
    $selfLogConfig->httpErrors ? new FailedRequestLogger($logger->channel('http'), $ipResolver) : null,
    new RequestBodyBufferMiddleware(4 * 1024 * 1024),
    new RequestBodyParserMiddleware(),
    function ($request) use ($router) {
        return $router->handle($request);
    },
])));

$httpSocket = new SocketServer("0.0.0.0:{$httpPort}", [], $loop);
$httpServer->listen($httpSocket);

$httpServer->on('error', function (\Throwable $e) use ($logger) {
    $logger->error('http', 'Error: ' . $e->getMessage(), [
        'exception' => $e::class,
        'previous'  => $e->getPrevious()?->getMessage(),
    ]);
});

// ─── Start ────────────────────────────────────────────────────────────────────

echo "╔══════════════════════════════════════════╗\n";
echo "║           LogService started             ║\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  HTTP API  →  http://0.0.0.0:{$httpPort}       ║\n";
echo "║  WebSocket →  ws://0.0.0.0:{$wsPort}          ║\n";
echo $udpConfig->enabled
    ? "║  UDP       →  udp://{$udpConfig->address()}\n"
    : "║  UDP       →  off\n";
echo "╠══════════════════════════════════════════╣\n";
echo "║  Write auth : {$authMode}\n";
echo "║  Read key   : " . (empty($uiSecret) ? '⚠️  NOT SET' : '✅ set') . "\n";
echo "╚══════════════════════════════════════════╝\n\n";

$logger->info('startup', "LogStream server {$appVersion} started", [
    'version'      => $appVersion,
    'php_version'  => PHP_VERSION,
    'http_port'    => $httpPort,
    'ws_port'      => $wsPort,
    'udp_enabled'  => $udpConfig->enabled,
    'udp_port'     => $udpConfig->enabled ? $udpConfig->port : null,
    'storage_type' => $storageType,
    'write_auth'   => $authMode,
    'read_key_set' => !empty($uiSecret),
    'ingest_mode'  => $ingestConfig->mode,
    'retention'    => $retentionRunner !== null,
    'http_errors'  => $selfLogConfig->httpErrors,
]);

// ─── Retention periodic timer ─────────────────────────────────────────────────

if ($retentionRunner !== null) {
    $retentionIntervalHours = max(1, (int) $retentionConfig->intervalHours);

    if ($retentionIntervalHours !== (int) $retentionConfig->intervalHours) {
        $logger->warning('retention', "interval_hours={$retentionConfig->intervalHours} is too small; clamping to {$retentionIntervalHours} hour(s).");
    }

    $retentionIntervalSeconds = $retentionIntervalHours * 3600;

    $loop->addPeriodicTimer($retentionIntervalSeconds, function () use ($retentionRunner, $volumeAnalyzer, $logger) {
        $logger->info('retention', 'Running scheduled policies...');
        // Loading the policies can fail (e.g. MariaDB unreachable). An exception
        // escaping a timer stops the event loop, so log it and retry next tick.
        try {
            $results = $retentionRunner->runAll();
        } catch (\Throwable $e) {
            $logger->error('retention', 'Scheduled run failed: ' . $e->getMessage());
            return;
        }
        $totalPruned = array_sum(array_map(fn($r) => $r->pruned, $results));
        foreach ($results as $r) {
            if ($r->error !== null) {
                $logger->error('retention', "Policy '{$r->policy}': {$r->summary} ({$r->error})", ['policy' => $r->policy]);
            } else {
                $logger->info('retention', "Policy '{$r->policy}': {$r->summary}", ['policy' => $r->policy, 'pruned' => $r->pruned]);
            }
        }
        if ($totalPruned > 0) {
            $volumeAnalyzer?->invalidate();
        }
        $logger->info('retention', "Done — {$totalPruned} entries pruned.", ['pruned' => $totalPruned]);
    });
}

// ─── Client health ────────────────────────────────────────────────────────────
// last_seen_at is flushed every few seconds. With CLIENT_SILENCE_WEBHOOK_URL
// set, clients with a heartbeat timeout are checked every
// CLIENT_HEALTH_CHECK_SECONDS (default 30) and a webhook fires when one goes silent.

$silenceMonitor = null;

if ($clientActivity !== null) {
    $clientActivity->start($loop);

    $silenceWebhook = trim((string) ($_ENV['CLIENT_SILENCE_WEBHOOK_URL'] ?? ''));
    if ($silenceWebhook !== '') {
        if (!WebhookSilenceNotifier::isValidUrl($silenceWebhook)) {
            $logger->warning('clients', 'CLIENT_SILENCE_WEBHOOK_URL is not an http(s) URL — silence alerts are off.');
        } else {
            $checkSeconds   = max(5.0, (float) ($_ENV['CLIENT_HEALTH_CHECK_SECONDS'] ?? ClientSilenceMonitor::DEFAULT_INTERVAL_SECONDS));
            $silenceMonitor = new ClientSilenceMonitor(
                $clientRepository,
                new WebhookSilenceNotifier($silenceWebhook),
                $clientActivity,
                log: $logger->channel('clients'),
            );
            $silenceMonitor->start($loop, $checkSeconds);
            $logger->info('clients', "Silence alerts enabled — checked every {$checkSeconds}s");
        }
    } else {
        $logger->info('clients', 'Silence alerts off (CLIENT_SILENCE_WEBHOOK_URL not set)');
    }
}

// ─── Graceful shutdown ────────────────────────────────────────────────────────
// On SIGTERM (systemctl stop/restart, docker stop) or SIGINT: stop accepting
// connections, write what is still buffered, then exit. A second signal exits
// immediately.

if (function_exists('pcntl_signal')) {
    $shuttingDown = false;
    $shutdown = function (int $signal) use (
        &$shuttingDown, $loop, $httpSocket, $wsSocket, $udpServer, $ingestBuffer, $asyncWriter, $ingestConfig, $logger, $clientActivity
    ): void {
        if ($shuttingDown) {
            $logger->log('warning', 'shutdown', 'Second signal — exiting now.', now: true);
            exit(1);
        }
        $shuttingDown = true;
        $logger->info('shutdown', "Signal {$signal} received — closing listeners.", ['signal' => $signal]);

        $httpSocket->close();
        $wsSocket->close();
        $udpServer?->stop($loop);
        $clientActivity?->stop($loop);

        if ($ingestBuffer === null) {
            $clientActivity?->flush();
            $loop->stop();
            return;
        }

        $pending = $ingestBuffer->pending();
        $logger->info('shutdown', "Writing {$pending} buffered entries...");
        $ingestBuffer->drain($ingestConfig->shutdownTimeoutMs / 1000)->then(
            function (bool $drained) use ($loop, $ingestBuffer, $asyncWriter, $logger, $clientActivity): void {
                // The buffer no longer accepts entries: these are written directly.
                $drained
                    ? $logger->info('shutdown', 'Buffer drained.')
                    : $logger->error('shutdown', 'Timed out — ' . $ingestBuffer->pending() . ' entries were not written.');
                $asyncWriter?->quit();
                $clientActivity?->flush();
                $loop->stop();
            },
        );
    };

    $loop->addSignal(SIGTERM, $shutdown);
    $loop->addSignal(SIGINT, $shutdown);
}

$loop->run();
