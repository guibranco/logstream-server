<?php

declare(strict_types=1);

/**
 * demo-seed.php — fills a LogStream server with realistic demo traffic.
 *
 * Sends the fake "ShopFast" traffic from LogService\Demo\DemoTraffic: six
 * made-up backend services, each scenario one request flowing through several
 * of them under a shared trace_id. With DEMO_MODE=true the Render entrypoint
 * runs this on every start; the /demo page triggers single scenarios.
 *
 * Two phases:
 *   1. Backfill — scenarios spread over the last N hours, sent in batches.
 *   2. Live     — a scenario every few seconds with current timestamps.
 *
 * Usage:
 *   php bin/demo-seed.php [--url=http://127.0.0.1:8081] [--secret=lsa_...]
 *                         [--backfill-hours=6] [--per-hour=40]
 *                         [--interval=4] [--duration=0] [--no-live] [--wait=60]
 *
 * Environment fallbacks:
 *   LOGSTREAM_URL                 → --url
 *   LOGSTREAM_API_SECRET / API_SECRET → --secret            (file mode, Bearer)
 *   LOGSTREAM_API_KEY + LOGSTREAM_API_TOKEN                  (MariaDB mode)
 *
 * In MariaDB mode the server takes app_key from the credentials, so every entry
 * lands under that one registered app; the service name is still kept in the
 * category and in context.service.
 *
 * Demo data only: every name, e-mail and order is made up.
 */

require __DIR__ . '/../vendor/autoload.php';

use LogService\Demo\DemoTraffic;

// ── Options ──────────────────────────────────────────────────────────────────

$opts = getopt('', [
    'url:', 'secret:', 'backfill-hours:', 'per-hour:', 'interval:',
    'duration:', 'no-live', 'wait:', 'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, (string) preg_replace('/^ \* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__), 2)[1], 2)[0]));
    exit(0);
}

$baseUrl  = rtrim((string) ($opts['url'] ?? getenv('LOGSTREAM_URL') ?: 'http://127.0.0.1:8081'), '/');
$secret   = (string) ($opts['secret'] ?? getenv('LOGSTREAM_API_SECRET') ?: getenv('API_SECRET') ?: '');
$apiKey   = (string) (getenv('LOGSTREAM_API_KEY') ?: '');
$apiToken = (string) (getenv('LOGSTREAM_API_TOKEN') ?: '');

$backfillHours = max(0.0, (float) ($opts['backfill-hours'] ?? 6));
$perHour       = max(1, (int) ($opts['per-hour'] ?? 40));
$interval      = max(0.5, (float) ($opts['interval'] ?? 4));
$duration      = max(0, (int) ($opts['duration'] ?? 0));   // seconds, 0 = until stopped
$live          = !isset($opts['no-live']);
$waitSeconds   = max(0, (int) ($opts['wait'] ?? 60));

if ($secret === '' && ($apiKey === '' || $apiToken === '')) {
    fwrite(STDERR, "❌ No credentials: pass --secret (or set API_SECRET), or set LOGSTREAM_API_KEY and LOGSTREAM_API_TOKEN.\n");
    exit(1);
}

$headers = ['Content-Type: application/json', 'User-Agent: logstream-demo-seed/1.0'];
$headers = array_merge($headers, $secret !== ''
    ? ["Authorization: Bearer {$secret}"]
    : ["X-Api-Key: {$apiKey}", "X-Api-Token: {$apiToken}"]);

// ── HTTP ─────────────────────────────────────────────────────────────────────

/** @return array{0:int,1:string} status code and body */
function http(string $method, string $url, array $headers, ?string $body = null): array
{
    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'content'       => $body ?? '',
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $ctx);
    $status   = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, $response === false ? '' : $response];
}

function waitForHealth(string $baseUrl, int $seconds): bool
{
    $deadline = time() + $seconds;
    do {
        [$status] = http('GET', "{$baseUrl}/api/health", []);
        if ($status === 200) {
            return true;
        }
        sleep(2);
    } while (time() < $deadline);
    return false;
}

/** @param list<array<string,mixed>> $entries */
function send(string $baseUrl, array $headers, array $entries): bool
{
    // Top-level app_key/app_id are required; each entry overrides them with its own.
    $payload = json_encode(['app_key' => 'demo', 'app_id' => 'demo', 'logs' => $entries], JSON_UNESCAPED_SLASHES);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        [$status, $body] = http('POST', "{$baseUrl}/api/logs", $headers, $payload);
        if ($status === 201 || $status === 202) {
            return true;
        }
        if ($status === 401 || $status === 403 || $status === 400) {
            fwrite(STDERR, "❌ Ingest rejected ({$status}): {$body}\n");
            return false;
        }
        usleep($attempt * 500_000);
    }
    fwrite(STDERR, "⚠️  Ingest failed after retries (last status {$status}).\n");
    return false;
}

// ── Run ──────────────────────────────────────────────────────────────────────

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  static function () use (&$running) { $running = false; });
}

echo "🌱 LogStream demo seeder → {$baseUrl}\n";

if ($waitSeconds > 0 && !waitForHealth($baseUrl, $waitSeconds)) {
    fwrite(STDERR, "❌ {$baseUrl}/api/health did not answer 200 within {$waitSeconds} s.\n");
    exit(1);
}

// Phase 1: backfill
if ($backfillHours > 0) {
    $entries = DemoTraffic::backfill($backfillHours, $perHour);
    // Stay well under 64 KiB per request: servers before the
    // StreamingRequestMiddleware fix drop larger bodies.
    foreach (array_chunk($entries, 100) as $batch) {
        if (!send($baseUrl, $headers, $batch)) {
            exit(1);
        }
    }
    echo '✅ Backfilled ' . count($entries) . " entries over {$backfillHours} h\n";
}

// Phase 2: live
if (!$live) {
    exit(0);
}

echo "📡 Live mode: a scenario every ~{$interval} s" . ($duration ? " for {$duration} s" : '') . "\n";
$stopAt = $duration ? time() + $duration : PHP_INT_MAX;
while ($running && time() < $stopAt) {
    send($baseUrl, $headers, DemoTraffic::run('random', microtime(true)));
    // Jitter so the stream doesn't tick like a metronome.
    usleep((int) ($interval * mt_rand(50, 150) * 10_000));
}
echo "👋 Seeder stopped\n";
