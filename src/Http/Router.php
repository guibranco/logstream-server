<?php

declare(strict_types=1);

namespace LogService\Http;

use LogService\Auth\WriteAuthInterface;
use LogService\Models\LogEntry;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionPolicyRepositoryInterface;
use LogService\Retention\RetentionRunner;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

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
 */
final class Router
{
    public function __construct(
        private readonly StorageInterface                    $storage,
        private readonly LogHub                             $hub,
        private readonly WriteAuthInterface                 $writeAuth,
        private readonly string                             $uiSecret,
        private readonly string                             $storageType,
        private readonly string                             $version          = 'dev',
        private readonly ?RetentionRunner                   $retentionRunner  = null,
        private readonly ?RetentionPolicyRepositoryInterface $policyRepository = null,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────

    public function handle(ServerRequestInterface $request): Response
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

        if ($method === 'GET' && $path === '/openapi.yaml') {
            return $this->serveTemplate(__DIR__ . '/../../public/openapi.yaml', 'application/yaml');
        }

        // ── Retention policy CRUD ─────────────────────────────────────────────

        if (str_starts_with($path, '/api/retention')) {
            if (!$this->isReadAuthorised($request)) {
                return $this->json(['error' => 'Unauthorized'], 401, $cors);
            }
            return $this->routeRetention($method, $path, $request, $cors);
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
        // POST /api/retention/run — run all policies
        if ($method === 'POST' && $path === '/api/retention/run') {
            return $this->handleRetentionRun(null, $cors);
        }

        // GET /api/retention/policies — list policies
        if ($method === 'GET' && $path === '/api/retention/policies') {
            return $this->handleRetentionList($cors);
        }

        // POST /api/retention/policies — create policy
        if ($method === 'POST' && $path === '/api/retention/policies') {
            return $this->handleRetentionCreate($request, $cors);
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
            return $this->handleRetentionRun($m[1], $cors);
        }

        return $this->json(['error' => 'Not found'], 404, $cors);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Retention handlers
    // ──────────────────────────────────────────────────────────────────────────

    private function handleRetentionRun(?string $policyName, array $cors): Response
    {
        if ($this->retentionRunner === null) {
            return $this->json(['error' => 'Retention is not configured'], 503, $cors);
        }

        try {
            if ($policyName !== null) {
                $results = [$this->retentionRunner->runPolicy($policyName)];
            } else {
                $results = $this->retentionRunner->runAll();
            }
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 404, $cors);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500, $cors);
        }

        $totalPruned = array_sum(array_map(fn($r) => $r->pruned, $results));

        return $this->json([
            'ran_at'       => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            'policies'     => array_map(fn($r) => $r->toArray(), $results),
            'total_pruned' => $totalPruned,
        ], 200, $cors);
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
        return $this->json([
            'status'         => 'ok',
            'time'           => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            'ws_connections' => $this->hub->getConnectionCount(),
        ], 200, $cors);
    }

    private function handleInfo(array $cors): Response
    {
        return $this->json([
            'version'      => $this->version,
            'storage_type' => $this->storageType,
            'features'     => [
                'client_management' => $this->storageType === 'mariadb',
                'retention'         => $this->retentionRunner !== null,
                'websocket'         => true,
            ],
        ], 200, $cors);
    }

    /**
     * @param string|null $resolvedAppKey  The app_key surfaced by the auth strategy.
     *                                     For DB auth this is the registered app_key;
     *                                     for single-key auth it is '__single_key__'
     *                                     and the body field takes precedence.
     */
    private function handleIngest(
        ServerRequestInterface $request,
        array $cors,
        ?string $resolvedAppKey,
    ): Response {
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

        if (!$defaultAppKey || !$topAppId) {
            return $this->json(['error' => 'app_key and app_id are required'], 400, $cors);
        }

        $rawLogs = isset($body['logs']) && is_array($body['logs'])
            ? $body['logs']
            : [$body];

        $saved  = [];
        $errors = [];

        foreach ($rawLogs as $i => $raw) {
            $entryAppKey = $isSingleKey
                ? ($raw['app_key'] ?? $defaultAppKey)
                : $resolvedAppKey;

            try {
                $entry = LogEntry::fromArray([
                    'id'         => $this->ulid(),
                    'trace_id'   => $raw['trace_id']   ?? $this->uuid4(),
                    'batch_id'   => $raw['batch_id']   ?? $topBatchId,
                    'app_key'    => $entryAppKey,
                    'app_id'     => $raw['app_id']     ?? $topAppId,
                    'user_agent' => $raw['user_agent'] ?? $topUserAgent,
                    'level'      => $raw['level']      ?? 'info',
                    'category'   => $raw['category']   ?? 'general',
                    'message'    => $raw['message']    ?? '',
                    'context'    => $raw['context']    ?? null,
                    'timestamp'  => $raw['timestamp']  ?? (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
                ]);

                $this->storage->save($entry);
                $this->hub->broadcast($entry);
                $saved[] = $entry->toArray();
            } catch (\Throwable $e) {
                $errors[] = "Entry #{$i}: " . $e->getMessage();
            }
        }

        $status = empty($saved) ? 400 : 201;

        return $this->json([
            'saved'   => count($saved),
            'entries' => $saved,
            'errors'  => $errors ?: null,
        ], $status, $cors);
    }

    private function handleSearch(ServerRequestInterface $request, array $cors): Response
    {
        $q = $request->getQueryParams();

        $filters = array_filter([
            'app_key'    => $q['app_key']    ?? null,
            'app_id'     => $q['app_id']     ?? null,
            'user_agent' => $q['user_agent'] ?? null,
            'level'      => $q['level']      ?? null,
            'category'   => $q['category']   ?? null,
            'trace_id'   => $q['trace_id']   ?? null,
            'batch_id'   => $q['batch_id']   ?? null,
            'date_from'  => $q['date_from']  ?? null,
            'date_to'    => $q['date_to']    ?? null,
            'search'     => $q['search']     ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $limit  = max(1, min((int)($q['limit']  ?? 100), 1000));
        $offset = max(0,          (int)($q['offset'] ?? 0));

        return $this->json($this->storage->search($filters, $limit, $offset), 200, $cors);
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
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function policyToArray(RetentionPolicy $policy): array
    {
        return array_filter([
            'name'            => $policy->name,
            'app_key'         => $policy->appKey,
            'app_id'          => $policy->appId,
            'level'           => $policy->level,
            'category'        => $policy->category,
            'message_regex'   => $policy->messageRegex,
            'message_glob'    => $policy->messageGlob,
            'older_than_days' => $policy->olderThanDays,
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
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Api-Key, X-Api-Token, X-App-Key, X-App-Id',
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

    // ─── ID generators ────────────────────────────────────────────────────────

    private function ulid(): string
    {
        static $lastMs  = 0;
        static $lastRnd = 0;

        $ms = (int)(microtime(true) * 1000);

        if ($ms === $lastMs) {
            $lastRnd++;
        } else {
            $lastMs  = $ms;
            $lastRnd = random_int(0, 0x7FFFFFFFFF);
        }

        $enc = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ts  = $ms;
        $out = '';

        for ($i = 9; $i >= 0; $i--) {
            $out = $enc[$ts % 32] . $out;
            $ts  = intdiv($ts, 32);
        }

        $rnd = $lastRnd;
        for ($i = 15; $i >= 0; $i--) {
            $out = $enc[$rnd % 32] . $out;
            $rnd = intdiv($rnd, 32);
        }

        return strrev($out);
    }

    private function uuid4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
