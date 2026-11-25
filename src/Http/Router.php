<?php

declare(strict_types=1);

namespace LogService\Http;

use LogService\Auth\CacheFlushableInterface;
use LogService\Auth\WriteAuthInterface;
use LogService\Demo\DemoTraffic;
use LogService\Health\ClientActivityTracker;
use LogService\Health\ClientHealth;
use LogService\Ingest\EntryFactory;
use LogService\Ingest\EntrySink;
use LogService\Ingest\IngestBuffer;
use LogService\Ingest\IngestConfig;
use LogService\Logging\SelfLogConfig;
use LogService\Models\LogEntry;
use LogService\Repository\Client;
use LogService\Repository\ClientRepository;
use LogService\Retention\RetentionLogEntry;
use LogService\Retention\RetentionLogRepositoryInterface;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionPolicyRepositoryInterface;
use LogService\Retention\CachedRetentionVolumeAnalyzer;
use LogService\Retention\RetentionRunner;
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Storage\ContextFilter;
use LogService\Storage\ReportsHealth;
use LogService\Storage\SearchFilters;
use LogService\Storage\StorageInterface;
use LogService\Udp\UdpConfig;
use LogService\Udp\UdpLogServer;
use LogService\WebSocket\LogHub;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

/**
 * HTTP router for the log ingestion and query API.
 *
 * Authentication:
 *
 *   Write (POST /api/logs):
 *     File storage   → Authorization: Bearer <API_SECRET>
 *     MariaDB storage → X-Api-Key: <app_key>  +  X-Api-Token: <api_token>
 *     Delegated to the injected WriteAuthInterface implementation.
 *
 *   Read (GET /api/logs, GET /api/logs/{id}, GET /api/retention/*):
 *     Always: Authorization: Bearer <UI_SECRET>  (same for both storage modes)
 *
 *   Public (no auth):
 *     GET /api/health, GET /docs, GET /, GET /openapi.yaml, OPTIONS *
 *
 * Ingest (POST /api/logs):
 *   Without an IngestBuffer every entry is written before responding (201).
 *   With one (INGEST_MODE=buffered) entries are queued and answered with
 *   202 Accepted; `Prefer: return=representation` waits for the write and
 *   answers 201 with the stored entries, like the synchronous mode.
 */
final class Router
{
    private const DEMO_MAX_COUNT          = 50;
    private const DEMO_MAX_BACKFILL_HOURS = 6;
    private const DEMO_BACKFILL_PER_HOUR  = 40;

    private const VOLUME_DEFAULT_DAYS = 90;
    private const VOLUME_MAX_DAYS     = 3650;

    private const RUN_LOG_DEFAULT_DAYS  = 90;
    private const RUN_LOG_MAX_DAYS      = 365;
    private const RUN_LOG_DEFAULT_LIMIT = 500;
    private const RUN_LOG_MAX_LIMIT     = 5000;

    public function __construct(
        private readonly StorageInterface                     $storage,
        private readonly LogHub                              $hub,
        private readonly WriteAuthInterface                  $writeAuth,
        private readonly string                              $uiSecret,
        private readonly string                              $storageType,
        private readonly string                              $version           = 'dev',
        private readonly ?RetentionRunner                    $retentionRunner   = null,
        private readonly ?RetentionPolicyRepositoryInterface $policyRepository  = null,
        private readonly ?ClientRepository                   $clientRepository  = null,
        private readonly ?CacheFlushableInterface            $writeAuthDb       = null,
        private readonly ClientIpResolver                    $ipResolver        = new ClientIpResolver(),
        private readonly ?RetentionVolumeAnalyzerInterface   $volumeAnalyzer    = null,
        private readonly ?IngestBuffer                       $ingestBuffer      = null,
        private readonly float                               $ingestWaitSeconds = 10.0,
        private readonly string                              $ingestDbDriver    = IngestConfig::DRIVER_PDO,
        /** Whether the server stores its own messages under the reserved app_key "logstream-server". */
        private readonly bool                                $selfLogging       = false,
        /** DEMO_MODE: serves /demo and /api/demo/* (fake traffic). 404 otherwise. */
        private readonly bool                                $demoMode          = false,
        /** Records last-seen times on ingestion (MariaDB only). Buffered writes are recorded by the buffer's onPersisted callback. */
        private readonly ?ClientActivityTracker              $clientActivity    = null,
        /** UDP ingest (null when UDP_ENABLED=false); only read for /api/health and /api/info. */
        private readonly ?UdpLogServer                       $udpServer         = null,
        private readonly ?UdpConfig                          $udpConfig         = null,
        /** History of retention runs (retention_log table, or a JSONL file in file mode). */
        private readonly ?RetentionLogRepositoryInterface    $retentionLog      = null,
        /** Where log entries live (LOG_STORE); defaults to $storageType, the control-plane database. */
        ?string                                              $logStore          = null,
    ) {
        $this->logStore = $logStore ?? $storageType;
        $this->sink = new EntrySink($storage, $hub, $ingestBuffer, $clientActivity);
    }

    /** Shared with UDP: sync writes, or the ingest buffer. */
    private readonly EntrySink $sink;

    private readonly string $logStore;

    // ──────────────────────────────────────────────────────────────────────────

    /** @return Response|PromiseInterface<Response> */
    public function handle(ServerRequestInterface $request): Response|PromiseInterface
    {
        $method = $request->getMethod();
        $path   = rtrim($request->getUri()->getPath(), '/') ?: '/';
        $cors   = $this->corsHeaders();

        if ($method === 'OPTIONS') {
            return new Response(204, $cors);
        }

        if ($method === 'GET' && $path === '/api/health') {
            return $this->handleHealth($cors);
        }

        if ($method === 'GET' && $path === '/api/info') {
            return $this->handleInfo($cors);
        }

        if ($method === 'GET' && ($path === '/docs' || $path === '/')) {
            return $this->serveTemplate(__DIR__ . '/../../public/swagger-ui.html', 'text/html');
        }

        if ($method === 'GET' && $path === '/favicon.ico') {
            return $this->serveFile(__DIR__ . '/../../public/favicon.ico', 'image/x-icon');
        }

        if ($method === 'GET' && ($path === '/logo.svg' || $path === '/favicon.svg')) {
            return $this->serveFile(__DIR__ . '/../../public/logo.svg', 'image/svg+xml');
        }

        if ($method === 'GET' && $path === '/openapi.yaml') {
            return $this->serveTemplate(__DIR__ . '/../../public/openapi.yaml', 'application/yaml');
        }

        // ── Demo (DEMO_MODE only; otherwise these paths fall through to 404) ──

        if ($this->demoMode) {
            if ($method === 'GET' && $path === '/demo') {
                return $this->serveTemplate(__DIR__ . '/../../public/demo.html', 'text/html');
            }
            if ($method === 'GET' && $path === '/api/demo/scenarios') {
                return $this->json(['scenarios' => DemoTraffic::SCENARIOS, 'limits' => [
                    'max_count'          => self::DEMO_MAX_COUNT,
                    'max_backfill_hours' => self::DEMO_MAX_BACKFILL_HOURS,
                ]], 200, $cors);
            }
            if ($method === 'POST' && $path === '/api/demo/run') {
                return $this->handleDemoRun($request, $cors);
            }
        }

        // ── Retention policy CRUD ─────────────────────────────────────────────

        if (str_starts_with($path, '/api/retention')) {
            // Retention can delete logs, so it never runs unauthenticated:
            // without UI_SECRET the endpoints stay off instead of open.
            if (empty($this->uiSecret)) {
                return $this->json(['error' => 'Retention API requires UI_SECRET to be set'], 503, $cors);
            }
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            return $this->routeRetention($method, $path, $request, $cors);
        }

        // ── Client management ─────────────────────────────────────────────────

        if ($path === '/api/clients') {
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            return match ($method) {
                'GET'   => $this->handleClientList($cors),
                'POST'  => $this->handleClientCreate($request, $cors),
                default => $this->json(['error' => 'Method not allowed'], 405, $cors),
            };
        }

        if (preg_match('#^/api/clients/([^/]+)/health$#', $path, $m)) {
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            if ($method !== 'GET') {
                return $this->json(['error' => 'Method not allowed'], 405, $cors);
            }
            return $this->handleClientHealth(urldecode($m[1]), $cors);
        }

        // /rotate must be matched before the generic /{key} pattern
        if (preg_match('#^/api/clients/([^/]+)/rotate$#', $path, $m)) {
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            if ($method !== 'POST') {
                return $this->json(['error' => 'Method not allowed'], 405, $cors);
            }
            return $this->handleClientRotate(urldecode($m[1]), $cors);
        }

        if (preg_match('#^/api/clients/([^/]+)$#', $path, $m)) {
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            $key = urldecode($m[1]);
            return match ($method) {
                'GET'    => $this->handleClientGet($key, $cors),
                'PUT'    => $this->handleClientUpdate($key, $request, $cors),
                'DELETE' => $this->handleClientDelete($key, $cors),
                default  => $this->json(['error' => 'Method not allowed'], 405, $cors),
            };
        }

        // ── Write endpoint ────────────────────────────────────────────────────
        if ($method === 'POST' && $path === '/api/logs') {
            $authResult = $this->writeAuth->authenticate($request);
            if (!$authResult->ok) {
                return $this->json(['error' => 'Unauthorized', 'reason' => $authResult->reason], 401, $cors);
            }
            return $this->handleIngest($request, $cors, $authResult->appKey);
        }

        // ── Read endpoints ────────────────────────────────────────────────────
        if ($method === 'GET' && $path === '/api/logs') {
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            return $this->handleSearch($request, $cors);
        }

        if ($method === 'GET' && preg_match('#^/api/logs/([^/]+)$#', $path, $m)) {
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            return $this->handleGetById($m[1], $cors);
        }

        return $this->json(['error' => 'Not found'], 404, $cors);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Retention routing
    // ──────────────────────────────────────────────────────────────────────────

    private function routeRetention(
        string                 $method,
        string                 $path,
        ServerRequestInterface $request,
        array                  $cors,
    ): Response {
        // Fixed paths. match() only evaluates the arm that matches.
        $exact = match ("{$method} {$path}") {
            // Stored volume by age, for the retention chart
            'GET /api/retention/volume'    => $this->handleRetentionVolume($request, $cors),
            // History of retention runs (one row per policy per run)
            'GET /api/retention/log'       => $this->handleRetentionLog($request, $cors),
            // Run all policies
            'POST /api/retention/run'      => $this->handleRetentionRun(null, $request, $cors),
            // List policies
            'GET /api/retention/policies'  => $this->handleRetentionList($cors),
            // Create policy
            'POST /api/retention/policies' => $this->handleRetentionCreate($request, $cors),
            default                        => null,
        };
        if ($exact !== null) {
            return $exact;
        }

        // GET /api/retention/policies/{name} — get policy
        if ($method === 'GET' && preg_match('#^/api/retention/policies/([^/]+)$#', $path, $m)) {
            return $this->handleRetentionGet($m[1], $cors);
        }

        // PUT /api/retention/policies/{name} — update policy
        if ($method === 'PUT' && preg_match('#^/api/retention/policies/([^/]+)$#', $path, $m)) {
            return $this->handleRetentionUpdate($m[1], $request, $cors);
        }

        // DELETE /api/retention/policies/{name} — delete policy
        if ($method === 'DELETE' && preg_match('#^/api/retention/policies/([^/]+)$#', $path, $m)) {
            return $this->handleRetentionDelete($m[1], $cors);
        }

        // POST /api/retention/policies/{name}/run — run specific policy
        if ($method === 'POST' && preg_match('#^/api/retention/policies/([^/]+)/run$#', $path, $m)) {
            return $this->handleRetentionRun($m[1], $request, $cors);
        }

        return $this->json(['error' => 'Not found'], 404, $cors);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Retention handlers
    // ──────────────────────────────────────────────────────────────────────────

    private function handleRetentionRun(?string $policyName, ServerRequestInterface $request, array $cors): Response
    {
        if ($this->retentionRunner === null) {
            return $this->json(['error' => 'Retention is not configured'], 503, $cors);
        }

        try {
            [$dryRun, $policyName] = $this->parseRetentionRunOptions($request, $policyName);
        } catch (\UnexpectedValueException $e) {
            return $this->json(['error' => $e->getMessage()], 400, $cors);
        }

        try {
            if ($policyName !== null) {
                $results = [$this->retentionRunner->runPolicy($policyName, $dryRun)];
            } else {
                $results = $this->retentionRunner->runAll($dryRun);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 404, $cors);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500, $cors);
        }

        $totalPruned = array_sum(array_map(fn($r) => $r->pruned, $results));
        $failed      = count(array_filter($results, fn($r) => $r->error !== null));

        if (!$dryRun && $totalPruned > 0 && $this->volumeAnalyzer instanceof CachedRetentionVolumeAnalyzer) {
            $this->volumeAnalyzer->invalidate();
        }

        return $this->json([
            'ran_at'       => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            'policies'     => array_map(fn($r) => $r->toArray(), $results),
            'total_pruned' => $totalPruned,
            // A failed policy reports pruned: 0; this makes failures visible
            // instead of looking like "nothing matched".
            'failed'       => $failed,
        ], 200, $cors);
    }

    private function handleRetentionVolume(ServerRequestInterface $request, array $cors): Response
    {
        if ($this->volumeAnalyzer === null || $this->policyRepository === null) {
            return $this->json(['error' => 'Retention is not configured'], 503, $cors);
        }

        $days = $this->parseVolumeDays($request);
        if ($days === null) {
            return $this->json(
                ['error' => 'days must be an integer between 1 and ' . self::VOLUME_MAX_DAYS],
                400,
                $cors,
            );
        }

        return $this->volumeReportResponse($days, $cors);
    }

    private function volumeReportResponse(int $days, array $cors): Response
    {
        try {
            $report = $this->volumeAnalyzer->analyze($this->policyRepository->all(), $days);
            return $this->json($report->toArray(), 200, $cors);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500, $cors);
        }
    }

    /** The `days` query parameter, the default when absent, or null when invalid. */
    private function parseVolumeDays(ServerRequestInterface $request): ?int
    {
        $raw = $request->getQueryParams()['days'] ?? null;
        if ($raw === null || $raw === '') {
            return self::VOLUME_DEFAULT_DAYS;
        }

        $valid = is_string($raw) && ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= self::VOLUME_MAX_DAYS;
        return $valid ? (int) $raw : null;
    }

    private function handleRetentionLog(ServerRequestInterface $request, array $cors): Response
    {
        if ($this->retentionLog === null) {
            return $this->json(['error' => 'Retention log is not configured'], 503, $cors);
        }

        $query = $request->getQueryParams();
        $days  = $this->parseBoundedInt($query['days'] ?? null, self::RUN_LOG_DEFAULT_DAYS, self::RUN_LOG_MAX_DAYS);
        if ($days === null) {
            return $this->json(['error' => 'days must be an integer between 1 and ' . self::RUN_LOG_MAX_DAYS], 400, $cors);
        }
        $limit = $this->parseBoundedInt($query['limit'] ?? null, self::RUN_LOG_DEFAULT_LIMIT, self::RUN_LOG_MAX_LIMIT);
        if ($limit === null) {
            return $this->json(['error' => 'limit must be an integer between 1 and ' . self::RUN_LOG_MAX_LIMIT], 400, $cors);
        }
        $policy = $query['policy'] ?? null;
        if ($policy !== null && (!is_string($policy) || trim($policy) === '')) {
            return $this->json(['error' => 'policy must be a non-empty string'], 400, $cors);
        }

        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$days} days");

        try {
            // One extra row tells whether the list was cut at $limit.
            $entries = $this->retentionLog->recent($since, $policy, $limit + 1);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500, $cors);
        }

        $truncated = count($entries) > $limit;
        $entries   = array_slice($entries, 0, $limit);

        return $this->json([
            'days'      => $days,
            'since'     => $since->format(\DateTimeInterface::RFC3339),
            'policy'    => $policy,
            'runs'      => array_map(static fn(RetentionLogEntry $e) => $e->toArray(), $entries),
            'truncated' => $truncated,
            'totals'    => [
                'runs'     => count($entries),
                'deleted'  => array_sum(array_map(static fn(RetentionLogEntry $e) => $e->dryRun ? 0 : $e->deleted, $entries)),
                'failed'   => count(array_filter($entries, static fn(RetentionLogEntry $e) => $e->error !== null)),
                'dry_runs' => count(array_filter($entries, static fn(RetentionLogEntry $e) => $e->dryRun)),
            ],
        ], 200, $cors);
    }

    /** A positive integer query value up to $max, $default when absent, or null when invalid. */
    private function parseBoundedInt(mixed $raw, int $default, int $max): ?int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }

        $valid = is_string($raw) && ctype_digit($raw) && (int) $raw >= 1 && (int) $raw <= $max;
        return $valid ? (int) $raw : null;
    }

    private function handleRetentionList(array $cors): Response
    {
        if ($this->policyRepository === null) {
            return $this->json(['error' => 'Policy repository is not configured'], 503, $cors);
        }

        $policies = $this->policyRepository->all();

        return $this->json([
            'policies' => array_map(fn(RetentionPolicy $p) => $this->policyToArray($p), $policies),
        ], 200, $cors);
    }

    private function handleRetentionGet(string $name, array $cors): Response
    {
        if ($this->policyRepository === null) {
            return $this->json(['error' => 'Policy repository is not configured'], 503, $cors);
        }

        $policy = $this->policyRepository->find($name);

        if ($policy === null) {
            return $this->json(['error' => "Policy '{$name}' not found"], 404, $cors);
        }

        return $this->json($this->policyToArray($policy), 200, $cors);
    }

    private function handleRetentionCreate(ServerRequestInterface $request, array $cors): Response
    {
        if ($this->policyRepository === null) {
            return $this->json(['error' => 'Policy repository is not configured'], 503, $cors);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body) || empty($body['name'])) {
            return $this->json(['error' => 'name is required'], 400, $cors);
        }

        try {
            $policy = RetentionPolicy::fromArray($body);
            $this->policyRepository->create($policy);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409, $cors);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400, $cors);
        }

        return $this->json($this->policyToArray($policy), 201, $cors);
    }

    private function handleRetentionUpdate(string $name, ServerRequestInterface $request, array $cors): Response
    {
        if ($this->policyRepository === null) {
            return $this->json(['error' => 'Policy repository is not configured'], 503, $cors);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body'], 400, $cors);
        }

        $body['name'] = $name;

        try {
            $policy = RetentionPolicy::fromArray($body);
            $this->policyRepository->update($policy);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 404, $cors);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400, $cors);
        }

        return $this->json($this->policyToArray($policy), 200, $cors);
    }

    private function handleRetentionDelete(string $name, array $cors): Response
    {
        if ($this->policyRepository === null) {
            return $this->json(['error' => 'Policy repository is not configured'], 503, $cors);
        }

        try {
            $this->policyRepository->delete($name);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 404, $cors);
        }

        return new Response(204, $cors);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Log handlers
    // ──────────────────────────────────────────────────────────────────────────

    private function handleHealth(array $cors): Response
    {
        // Stores that can check their backend (ClickHouse) report it; an
        // unreachable one makes the status "degraded" but keeps HTTP 200, so
        // container health checks don't restart the server over it.
        $logStore = $this->storage instanceof ReportsHealth ? $this->storage->health() : null;

        $body = [
            'status'         => $logStore !== null && !$logStore['reachable'] ? 'degraded' : 'ok',
            'time'           => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            'ws_connections' => $this->hub->getConnectionCount(),
        ];
        if ($logStore !== null) {
            $body['log_store'] = $logStore;
        }

        return $this->json($body + [
            'ingest' => $this->ingestInfo(withStats: true),
            'udp'    => $this->udpInfo(withStats: true),
        ], 200, $cors);
    }

    private function handleInfo(array $cors): Response
    {
        return $this->json([
            'version'      => $this->version,
            'storage_type' => $this->storageType,
            'log_store'    => $this->logStore,
            'features'     => [
                'client_management' => $this->storageType === 'mariadb',
                'retention'         => $this->retentionRunner !== null,
                // GET /api/retention/log lists past retention runs.
                'retention_log'     => $this->retentionLog !== null,
                'websocket'         => true,
                // app_key[], app_id[] and level[] on GET /api/logs match any of the values.
                'multi_value_filters' => true,
                // POST /api/logs answers 202 and honours Prefer: return=representation|minimal.
                'async_ingest'      => $this->ingestBuffer !== null,
                // The server's own messages are stored as entries of this app_key.
                'self_logging'      => $this->selfLogging,
                // GET /demo page and /api/demo/* fake-traffic endpoints.
                'demo_mode'         => $this->demoMode,
                // HMAC-signed log packets over UDP (see the "udp" block).
                'udp_ingest'        => $this->udpServer !== null,
                // transport (tcp|udp) is a search filter and a retention policy criterion.
                'transport_filters' => true,
                // Retention policies accept older_than_hours (rolling) besides older_than_days.
                'retention_hours'   => true,
            ],
            'self_logging' => [
                'enabled' => $this->selfLogging,
                'app_key' => SelfLogConfig::APP_KEY,
            ],
            'ingest'       => $this->ingestInfo(withStats: false),
            'udp'          => $this->udpInfo(withStats: false),
        ], 200, $cors);
    }

    /**
     * UDP ingest status. /api/info gives what a client needs to send packets;
     * /api/health adds whether the socket is bound and the counters.
     *
     * @return array<string, mixed>
     */
    private function udpInfo(bool $withStats): array
    {
        if ($this->udpServer === null || $this->udpConfig === null) {
            return ['enabled' => false];
        }

        $info = [
            'enabled'             => true,
            'port'                => $this->udpConfig->port,
            'protocol_version'    => 1,
            'max_packet_size'     => $this->udpConfig->maxPacketBytes,
            'timestamp_window_ms' => $this->udpConfig->timestampWindowMs,
        ];

        if ($withStats) {
            $info['listening'] = $this->udpServer->isListening();
            $info += $this->udpServer->stats();
            // Always a JSON object, also before the first rejection.
            $info['rejected'] = (object) $info['rejected'];
        }

        return $info;
    }

    /**
     * @param string|null $resolvedAppKey  The app_key surfaced by the auth strategy.
     *                                     For DB auth this is the registered app_key;
     *                                     for single-key auth it is '__single_key__'
     *                                     and the body field takes precedence.
     */
    /** @return Response|PromiseInterface<Response> */
    private function handleIngest(
        ServerRequestInterface $request,
        array $cors,
        ?string $resolvedAppKey,
    ): Response|PromiseInterface {
        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body'], 400, $cors);
        }

        $isSingleKey  = ($resolvedAppKey === '__single_key__' || $resolvedAppKey === null);
        $defaultAppKey = $isSingleKey
            ? ($body['app_key'] ?? $request->getHeaderLine('X-App-Key') ?: null)
            : $resolvedAppKey;

        $topAppId     = $body['app_id']   ?? $request->getHeaderLine('X-App-Id')   ?: null;
        $topUserAgent = $request->getHeaderLine('User-Agent') ?: ($body['user_agent'] ?? null);
        $topBatchId   = $body['batch_id'] ?? null;
        // Always taken from the connection, never from the body, so clients can't spoof it.
        $senderIp     = $this->ipResolver->resolve($request);

        if (!$defaultAppKey || !$topAppId) {
            return $this->json(['error' => 'app_key and app_id are required'], 400, $cors);
        }

        // A bare JSON array is a batch too (Fluent Bit, Vector and other log
        // shippers send one); its app_key/app_id then come from the headers.
        $rawLogs = match (true) {
            array_is_list($body)                            => $body,
            isset($body['logs']) && is_array($body['logs']) => $body['logs'],
            default                                         => [$body],
        };

        [$entries, $errors] = $this->buildEntries(
            $rawLogs, $isSingleKey, $defaultAppKey, $resolvedAppKey,
            $topAppId, $topUserAgent, $topBatchId, $senderIp,
        );

        return $this->persist($entries, $errors, $this->preferredReturn($request), $cors);
    }

    /**
     * Turns raw POST /api/logs items into entries (see EntryFactory::build()).
     *
     * @param array<int, mixed> $rawLogs
     * @return array{0: array<int, LogEntry>, 1: list<string>}
     */
    private function buildEntries(
        array   $rawLogs,
        bool    $isSingleKey,
        ?string $defaultAppKey,
        ?string $resolvedAppKey,
        string  $topAppId,
        ?string $topUserAgent,
        ?string $topBatchId,
        ?string $senderIp,
    ): array {
        // Everything reaching the router came over HTTP, i.e. TCP.
        return EntryFactory::build(
            $rawLogs, $isSingleKey, $defaultAppKey, $resolvedAppKey,
            $topAppId, $topUserAgent, $topBatchId, $senderIp, 'tcp',
        );
    }

    /**
     * Stores entries synchronously (201) or through the ingest buffer (202).
     *
     * @param array<int, LogEntry> $entries
     * @param list<string>         $errors
     * @return Response|PromiseInterface<Response>
     */
    private function persist(array $entries, array $errors, ?string $prefer, array $cors): Response|PromiseInterface
    {
        if ($this->ingestBuffer === null) {
            return $this->ingestSynchronously($entries, $errors, $cors);
        }

        if ($entries === []) {
            return $this->json(['saved' => 0, 'entries' => [], 'errors' => $errors ?: null], 400, $cors);
        }

        return $this->ingestBuffered($entries, $errors, $prefer, $cors);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Demo
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/demo/run — {"scenario": "checkout", "count": 5} runs a scenario
     * `count` times, ending now; {"backfill_hours": 2} writes random history
     * over the last hours instead. No key: the demo's keys are public anyway,
     * and the limits keep a single call small.
     *
     * @return Response|PromiseInterface<Response>
     */
    private function handleDemoRun(ServerRequestInterface $request, array $cors): Response|PromiseInterface
    {
        $raw  = (string) $request->getBody();
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body'], 400, $cors);
        }

        $backfill = $body['backfill_hours'] ?? null;
        if ($backfill !== null) {
            if (!is_numeric($backfill) || $backfill <= 0 || $backfill > self::DEMO_MAX_BACKFILL_HOURS) {
                return $this->json(['error' => 'backfill_hours must be more than 0 and at most ' . self::DEMO_MAX_BACKFILL_HOURS], 422, $cors);
            }
            $rawLogs  = DemoTraffic::backfill((float) $backfill, self::DEMO_BACKFILL_PER_HOUR);
            $scenario = 'backfill';
        } else {
            $scenario = (string) ($body['scenario'] ?? 'random');
            $count    = $body['count'] ?? 1;
            if (!DemoTraffic::isScenario($scenario)) {
                return $this->json(['error' => "Unknown scenario '{$scenario}'", 'scenarios' => array_keys(DemoTraffic::SCENARIOS)], 422, $cors);
            }
            if (!is_int($count) || $count < 1 || $count > self::DEMO_MAX_COUNT) {
                return $this->json(['error' => 'count must be an integer from 1 to ' . self::DEMO_MAX_COUNT], 422, $cors);
            }
            // Spread repeated runs over the last few seconds so they don't all share one instant.
            $rawLogs = [];
            $now     = microtime(true);
            for ($i = $count - 1; $i >= 0; $i--) {
                array_push($rawLogs, ...DemoTraffic::run($scenario, $now - $i * mt_rand(200, 1500) / 1000));
            }
        }

        [$entries, $errors] = $this->buildEntries(
            $rawLogs, true, 'demo', null, 'demo', 'logstream-demo-page/1.0', null,
            $this->ipResolver->resolve($request),
        );

        return $this->persist($entries, $errors, null, $cors);
    }

    /**
     * Original behaviour: write each entry, then answer 201 with what was stored.
     *
     * @param array<int, LogEntry> $entries Keyed by position in the request.
     * @param list<string>         $errors
     */
    private function ingestSynchronously(array $entries, array $errors, array $cors): Response
    {
        [$saved, $saveErrors] = $this->sink->saveNow($entries);
        $errors = [...$errors, ...$saveErrors];

        $status = empty($saved) ? 400 : 201;

        return $this->json([
            'saved'   => count($saved),
            'entries' => $saved,
            'errors'  => $errors ?: null,
        ], $status, $cors);
    }

    /**
     * Queue the entries. Answers 202 straight away, or — with
     * `Prefer: return=representation` — 201 once they are written.
     *
     * @param array<int, LogEntry> $entries Keyed by position in the request.
     * @param list<string>         $errors  Validation errors so far.
     * @param 'minimal'|'representation'|null $prefer
     * @return Response|PromiseInterface<Response>
     */
    private function ingestBuffered(array $entries, array $errors, ?string $prefer, array $cors): Response|PromiseInterface
    {
        $ticket = $this->sink->enqueue(array_values($entries));

        if ($ticket === null) {
            $reason = $this->ingestBuffer->isAccepting()
                ? 'Ingest buffer is full, retry later'
                : 'Server is shutting down, retry later';
            return $this->json(['error' => $reason], 503, $cors + ['Retry-After' => '1']);
        }

        $applied = $prefer !== null ? ['Preference-Applied' => "return={$prefer}"] : [];

        if ($prefer !== 'representation') {
            return $this->acceptedResponse($entries, $errors, $cors + $applied);
        }

        return $ticket->wait($this->ingestWaitSeconds)->then(
            function (?array $droppedIds) use ($entries, $errors, $cors, $applied): Response {
                if ($droppedIds === null) {
                    // Still queued (storage slow or down): it will be written, just not yet.
                    return $this->acceptedResponse($entries, $errors, $cors);
                }

                $saved = [];
                foreach ($entries as $i => $entry) {
                    if (in_array($entry->id, $droppedIds, true)) {
                        $errors[] = "Entry #{$i}: rejected by storage";
                        continue;
                    }
                    $saved[] = $entry->toArray();
                }

                return $this->json([
                    'saved'   => count($saved),
                    'entries' => $saved,
                    'errors'  => $errors ?: null,
                ], $saved === [] ? 400 : 201, $cors + $applied);
            },
        );
    }

    /**
     * 202 body: just enough to correlate (id + trace_id per accepted entry).
     *
     * @param array<int, LogEntry> $entries
     * @param list<string>         $errors
     */
    private function acceptedResponse(array $entries, array $errors, array $headers): Response
    {
        return $this->json([
            'accepted' => count($entries),
            'entries'  => array_values(array_map(
                static fn(LogEntry $e) => ['id' => $e->id, 'trace_id' => $e->traceId],
                $entries,
            )),
            'errors'   => $errors ?: null,
        ], 202, $headers);
    }

    /**
     * The `return` preference of an RFC 7240 Prefer header, if any.
     * e.g. "Prefer: return=representation" or "Prefer: respond-async, return=minimal".
     *
     * @return 'minimal'|'representation'|null
     */
    private function preferredReturn(ServerRequestInterface $request): ?string
    {
        foreach ($request->getHeader('Prefer') as $header) {
            foreach (explode(',', $header) as $preference) {
                $pair = array_map('trim', explode('=', explode(';', $preference, 2)[0], 2));
                if (strtolower($pair[0]) === 'return' && isset($pair[1])) {
                    $value = strtolower(trim($pair[1], '"'));
                    if ($value === 'minimal' || $value === 'representation') {
                        return $value;
                    }
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function ingestInfo(bool $withStats): array
    {
        if ($this->ingestBuffer === null) {
            return ['mode' => IngestConfig::MODE_SYNC];
        }

        $info = [
            'mode'      => IngestConfig::MODE_BUFFERED,
            // DB_DRIVER picks the writer for both database log stores.
            'db_driver' => in_array($this->logStore, ['mariadb', 'clickhouse'], true) ? $this->ingestDbDriver : null,
        ];

        return $withStats ? $info + $this->ingestBuffer->stats() : $info;
    }

    private static function oneOrMany(mixed $raw): string|array|null
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (!is_array($raw)) {
            return null;
        }
        $values = array_values(array_unique(array_filter(
            array_map(static fn($v) => is_string($v) ? trim($v) : '', $raw),
            static fn(string $v) => $v !== '',
        )));

        return count($values) === 1 ? $values[0] : $values;
    }

    private function handleSearch(ServerRequestInterface $request, array $cors): Response
    {
        $q = $request->getQueryParams();

        $filters = array_filter([
            'app_key'    => self::oneOrMany($q['app_key'] ?? null),
            'app_id'     => self::oneOrMany($q['app_id'] ?? null),
            'user_agent' => $q['user_agent'] ?? null,
            'level'      => self::oneOrMany($q['level'] ?? null),
            'category'   => $q['category']   ?? null,
            'trace_id'   => $q['trace_id']   ?? null,
            'batch_id'   => $q['batch_id']   ?? null,
            'sender_ip'  => $q['sender_ip']  ?? null,
            'transport'  => self::oneOrMany($q['transport'] ?? null),
            'date_from'  => $q['date_from']  ?? null,
            'date_to'    => $q['date_to']    ?? null,
            'search'     => $q['search']     ?? null,
            'message_template' => $q['message_template'] ?? null,
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        try {
            $context       = ContextFilter::fromQuery($q['context'] ?? null, $q['context_not'] ?? null);
            $contextSearch = ContextFilter::parseSearchTerm($q['context_search'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400, $cors);
        }
        if ($context !== []) {
            $filters['context'] = $context;
        }
        if ($contextSearch !== null) {
            $filters['context_search'] = $contextSearch;
        }

        // A relative range replaces date_from/date_to, the way the UI treats it.
        if (array_key_exists('range', $q) || array_key_exists('tz', $q)) {
            $range = $q['range'] ?? null;
            $tz    = $q['tz'] ?? null;
            if (!is_string($range) || trim($range) === '' || ($tz !== null && !is_string($tz))) {
                return $this->json(['error' => 'range must be a single non-empty value; tz, if given, a single value.'], 400, $cors);
            }
            try {
                $filters['date_from'] = DateRange::resolve(
                    $range,
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    $tz,
                );
            } catch (\InvalidArgumentException $e) {
                return $this->json(['error' => $e->getMessage()], 400, $cors);
            }
            unset($filters['date_to']);
        }

        $limit  = max(1, min((int)($q['limit']  ?? 100), 1000));
        $offset = max(0,          (int)($q['offset'] ?? 0));

        try {
            $searchFilters = SearchFilters::fromArray($filters);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400, $cors);
        }

        return $this->json($this->storage->search($searchFilters, $limit, $offset), 200, $cors);
    }

    private function handleGetById(string $id, array $cors): Response
    {
        $entry = $this->storage->findById($id);

        if (!$entry) {
            return $this->json(['error' => 'Not found'], 404, $cors);
        }

        return $this->json($entry->toArray(), 200, $cors);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Client management handlers
    // ──────────────────────────────────────────────────────────────────────────

    private function handleClientList(array $cors): Response
    {
        if ($this->clientRepository === null) {
            return $this->dbOnlyError($cors);
        }
        return $this->json([
            'clients' => array_map(fn(Client $c) => $this->publicClient($c), $this->clientRepository->all()),
        ], 200, $cors);
    }

    private function handleClientGet(string $appKey, array $cors): Response
    {
        if ($this->clientRepository === null) {
            return $this->dbOnlyError($cors);
        }
        $client = $this->clientRepository->findByKey($appKey);
        if ($client === null) {
            return $this->json(['error' => "Client '{$appKey}' not found."], 404, $cors);
        }
        return $this->json($this->publicClient($client), 200, $cors);
    }

    /** GET /api/clients/{app_key}/health */
    private function handleClientHealth(string $appKey, array $cors): Response
    {
        if ($this->clientRepository === null) {
            return $this->dbOnlyError($cors);
        }
        $client = $this->clientRepository->findByKey($appKey);
        if ($client === null) {
            return $this->json(['error' => "Client '{$appKey}' not found."], 404, $cors);
        }

        $client = $this->withPendingLastSeen($client);
        $health = $client->health(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return $this->json(['app_key' => $client->appKey] + $health->toArray(), 200, $cors);
    }

    /** Public client fields, with a last-seen time that may not be written yet. */
    private function publicClient(Client $client): array
    {
        return $this->withPendingLastSeen($client)->toPublicArray();
    }

    private function withPendingLastSeen(Client $client): Client
    {
        $pending = $this->clientActivity?->lastSeen($client->appKey);
        if ($pending === null || ($client->lastSeenAt !== null && $client->lastSeenAt >= $pending)) {
            return $client;
        }
        return $client->withLastSeenAt($pending);
    }

    /**
     * heartbeat_timeout_min from a PUT body: [present, value]. null or "" clears it.
     *
     * @return array{0: bool, 1: ?int}
     * @throws \InvalidArgumentException when the value is not a whole number in range.
     */
    private static function parseHeartbeatTimeout(array $body): array
    {
        if (!array_key_exists('heartbeat_timeout_min', $body)) {
            return [false, null];
        }
        $raw = $body['heartbeat_timeout_min'];
        if ($raw === null || $raw === '') {
            return [true, null];
        }
        $valid = (is_int($raw) || (is_string($raw) && ctype_digit($raw)))
            && (int) $raw >= 1
            && (int) $raw <= ClientHealth::MAX_TIMEOUT_MINUTES;
        if (!$valid) {
            throw new \InvalidArgumentException(
                "'heartbeat_timeout_min' must be a whole number of minutes from 1 to "
                . ClientHealth::MAX_TIMEOUT_MINUTES . ', or null to disable monitoring.'
            );
        }
        return [true, (int) $raw];
    }

    private function handleClientCreate(ServerRequestInterface $request, array $cors): Response
    {
        if ($this->clientRepository === null) {
            return $this->dbOnlyError($cors);
        }
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json(['error' => 'Invalid JSON body.'], 400, $cors);
        }
        $name   = trim($body['name']    ?? '');
        $appKey = trim($body['app_key'] ?? '');

        if ($name === '' || $appKey === '') {
            return $this->json(['error' => "'name' and 'app_key' are required."], 422, $cors);
        }
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $appKey)) {
            return $this->json([
                'error' => "'app_key' must be lowercase letters, numbers, and hyphens only.",
            ], 422, $cors);
        }
        if (SelfLogConfig::isReservedAppKey($appKey)) {
            return $this->json([
                'error' => "'app_key' '" . SelfLogConfig::APP_KEY . "' is reserved for the server's own logs.",
            ], 409, $cors);
        }
        try {
            $client = $this->clientRepository->create($name, $appKey);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409, $cors);
        }
        // The write-auth cache also remembers unknown keys: drop a cached miss
        // for this app_key (e.g. from a UDP packet sent before it was registered).
        $this->writeAuthDb?->flushCache();
        return $this->json($client->toCreatedArray(), 201, $cors);
    }

    private function handleClientUpdate(string $appKey, ServerRequestInterface $request, array $cors): Response
    {
        if ($this->clientRepository === null) {
            return $this->dbOnlyError($cors);
        }
        $existing = $this->clientRepository->findByKey($appKey);
        if ($existing === null) {
            return $this->json(['error' => "Client '{$appKey}' not found."], 404, $cors);
        }
        $body   = json_decode((string) $request->getBody(), true);
        $body   = is_array($body) ? $body : [];
        $name   = trim($body['name'] ?? $existing->name);
        $active = isset($body['active']) ? (bool) $body['active'] : $existing->active;

        if ($name === '') {
            return $this->json(['error' => "'name' cannot be empty."], 422, $cors);
        }
        try {
            [$hasTimeout, $timeout] = self::parseHeartbeatTimeout($body);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 422, $cors);
        }
        try {
            $updated = $this->clientRepository->update($appKey, $name, $active);
            if ($hasTimeout && $timeout !== $existing->heartbeatTimeoutMin) {
                $updated = $this->clientRepository->setHeartbeatTimeout($appKey, $timeout);
            }
        } catch (\RuntimeException $e) {
            // update() may already have deactivated the client: drop cached tokens anyway.
            $this->writeAuthDb?->flushCache();
            return $this->json(['error' => $e->getMessage()], 400, $cors);
        }
        $this->writeAuthDb?->flushCache();
        return $this->json($this->publicClient($updated), 200, $cors);
    }

    private function handleClientRotate(string $appKey, array $cors): Response
    {
        if ($this->clientRepository === null) {
            return $this->dbOnlyError($cors);
        }
        try {
            $client = $this->clientRepository->rotateToken($appKey);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 404, $cors);
        }
        $this->writeAuthDb?->flushCache();
        return $this->json($client->toCreatedArray(), 200, $cors);
    }

    private function handleClientDelete(string $appKey, array $cors): Response
    {
        if ($this->clientRepository === null) {
            return $this->dbOnlyError($cors);
        }
        try {
            $logsDeleted = $this->clientRepository->delete($appKey);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 404, $cors);
        }
        $this->writeAuthDb?->flushCache();
        $this->clientActivity?->forget($appKey);
        return $this->json(['deleted' => $appKey, 'logs_deleted' => $logsDeleted], 200, $cors);
    }

    private function dbOnlyError(array $cors): Response
    {
        return $this->json(
            ['error' => 'Client management requires STORAGE_TYPE=mariadb.'],
            503,
            $cors,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Reads dry_run and policy from the query string and the JSON body (the UI
     * sends {"dry_run": true, "policy": "..."}). Anything ambiguous is either a
     * dry run or rejected, never a real deletion:
     *  - a dry run if the query or the body asks for one;
     *  - "dry_run": null in the body counts as a dry run;
     *  - a non-empty body that is not a JSON object is rejected;
     *  - "policy" (POST /api/retention/run only) must be a non-empty string when
     *    present and not null; an invalid value is rejected instead of widening
     *    the run to all policies.
     *
     * @return array{0: bool, 1: ?string} [dryRun, policyName]
     * @throws \UnexpectedValueException on an invalid body
     */
    private function parseRetentionRunOptions(ServerRequestInterface $request, ?string $policyName): array
    {
        // Decode without assoc so a JSON object ({}) and a JSON array ([]) stay
        // distinguishable: only an object (or no body at all) is accepted.
        $raw     = trim((string) $request->getBody());
        $decoded = $raw === '' ? new \stdClass() : json_decode($raw);

        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('Request body must be a JSON object');
        }

        $body = get_object_vars($decoded);

        $bodyDryRun = array_key_exists('dry_run', $body)
            && ($body['dry_run'] === null || $this->isTruthy($body['dry_run']));
        $dryRun     = $this->isTruthy($request->getQueryParams()['dry_run'] ?? null) || $bodyDryRun;

        $selector = $body['policy'] ?? null;
        if ($policyName === null && $selector !== null) {
            if (!is_string($selector) || trim($selector) === '') {
                throw new \UnexpectedValueException('policy must be a non-empty string');
            }
            $policyName = $selector;
        }

        return [$dryRun, $policyName];
    }

    /**
     * A dry-run flag counts as set unless it is missing or explicitly off
     * (false, 0, "0", "false", "no", "off"). "?dry_run" with no value is set,
     * as before. Erring towards "set" means a typo never deletes data.
     */
    private function isTruthy(mixed $value): bool
    {
        return match (true) {
            $value === null                    => false,
            is_bool($value)                    => $value,
            is_int($value), is_float($value)   => $value != 0,
            is_string($value)                  => !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true),
            default                            => true,
        };
    }

    private function policyToArray(RetentionPolicy $policy): array
    {
        return array_filter([
            'name'            => $policy->name,
            'app_key'         => $policy->appKey,
            'app_id'          => $policy->appId,
            'level'           => $policy->level,
            'category'        => $policy->category,
            'transport'       => $policy->transport,
            'message_regex'   => $policy->messageRegex,
            'message_glob'    => $policy->messageGlob,
            'older_than_days' => $policy->olderThanDays,
            'older_than_hours' => $policy->olderThanHours,
        ], fn($v) => $v !== null);
    }

    private function isReadAuthorised(ServerRequestInterface $request): bool
    {
        if (empty($this->uiSecret)) {
            return true;
        }

        $header = $request->getHeaderLine('Authorization');
        if (str_starts_with($header, 'Bearer ')) {
            return hash_equals($this->uiSecret, substr($header, 7));
        }

        $token = $request->getQueryParams()['token'] ?? '';
        return $token !== '' && hash_equals($this->uiSecret, $token);
    }

    private function json(array $data, int $status, array $extra = []): Response
    {
        return new Response(
            $status,
            array_merge($extra, ['Content-Type' => 'application/json']),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'  => 'Content-Type, Authorization, X-Api-Key, X-Api-Token, X-App-Key, X-App-Id, Prefer',
            'Access-Control-Expose-Headers' => 'Preference-Applied, Retry-After',
        ];
    }

    private function serveFile(string $path, string $contentType): Response
    {
        if (!file_exists($path) || !is_readable($path)) {
            return $this->json(['error' => 'Not found'], 404);
        }

        return new Response(200, [
            'Content-Type'  => $contentType . '; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ], file_get_contents($path));
    }

    private function serveTemplate(string $path, string $contentType): Response
    {
        if (!file_exists($path) || !is_readable($path)) {
            return $this->json(['error' => 'Not found'], 404);
        }

        $content = str_replace('{{APP_VERSION}}', $this->version, file_get_contents($path));

        return new Response(200, [
            'Content-Type'  => $contentType . '; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ], $content);
    }
}
