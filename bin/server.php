#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LogService\Http\Router;
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
$apiSecret   = $_ENV['API_SECRET'] ?? '';

// ─── Storage ──────────────────────────────────────────────────────────────────

if ($storageType === 'mariadb') {
    $dsn     = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'logservice',
    );
    $storage = new MariaDBStorage($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '');
    echo "[Storage] MariaDB ({$_ENV['DB_HOST']}:{$_ENV['DB_PORT']}/{$_ENV['DB_NAME']})\n";
} else {
    $logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs';
    $storage = new FileStorage($logPath);
    echo "[Storage] File ({$logPath})\n";
}

// ─── WebSocket hub ────────────────────────────────────────────────────────────

$loop = Loop::get();
$hub  = new LogHub();

$wsSocket = new SocketServer("0.0.0.0:{$wsPort}", [], $loop);
$wsServer = new IoServer(
    new RatchetHttpServer(new WsServer($hub)),
    $wsSocket,
    $loop,
);

// ─── HTTP API ─────────────────────────────────────────────────────────────────

$router = new Router($storage, $hub, $apiSecret);

$httpServer = new HttpServer(
    new RequestBodyBufferMiddleware(4 * 1024 * 1024), // 4 MB max body
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
echo "╚══════════════════════════════════════════╝\n\n";

$loop->run();
