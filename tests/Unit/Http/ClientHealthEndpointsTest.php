<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\CacheFlushableInterface;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Health\ClientActivityTracker;
use LogService\Http\Router;
use LogService\Repository\Client;
use LogService\Repository\ClientRepository;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

/** GET /api/clients/{key}/health, health fields on the client endpoints, last-seen on ingest. */
final class ClientHealthEndpointsTest extends TestCase
{
    private function client(
        ?\DateTimeImmutable $lastSeen = null,
        ?int                $timeout  = null,
        string              $appKey   = 'billing-api',
    ): Client {
        return new Client(
            id:                  1,
            name:                'Billing API',
            appKey:              $appKey,
            active:              true,
            createdAt:           '2026-01-01T00:00:00+00:00',
            updatedAt:           '2026-01-01T00:00:00+00:00',
            lastSeenAt:          $lastSeen,
            heartbeatTimeoutMin: $timeout,
        );
    }

    private function router(
        ?ClientRepository      $repo,
        ?ClientActivityTracker $activity    = null,
        string                 $storageType = 'mariadb',
        ?StorageInterface      $storage     = null,
        ?CacheFlushableInterface $writeAuthDb = null,
    ): Router {
        return new Router(
            storage:          $storage ?? $this->createStub(StorageInterface::class),
            hub:              new LogHub(''),
            writeAuth:        new SingleKeyWriteAuth('write-secret'),
            uiSecret:         'test-ui-secret',
            storageType:      $storageType,
            clientRepository: $repo,
            clientActivity:   $activity,
            writeAuthDb:      $writeAuthDb,
        );
    }

    private function authed(string $method, string $path, ?string $jsonBody = null): ServerRequest
    {
        $req = (new ServerRequest($method, "http://localhost{$path}"))
            ->withHeader('Authorization', 'Bearer test-ui-secret');
        return $jsonBody === null ? $req : $req->withBody(Utils::streamFor($jsonBody));
    }

    private function ingestRequest(array $body): ServerRequest
    {
        return (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withHeader('Authorization', 'Bearer write-secret')
            ->withBody(Utils::streamFor(json_encode($body)));
    }

    /** @return array<string, mixed> */
    private static function body($response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private static function minutesAgo(int $minutes): \DateTimeImmutable
    {
        return new \DateTimeImmutable("-{$minutes} minutes", new \DateTimeZone('UTC'));
    }

    // ── GET /api/clients/{key}/health ─────────────────────────────────────────

    #[Test]
    public function health_reports_never_seen(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client(timeout: 10));

        $response = $this->router($repo)->handle($this->authed('GET', '/api/clients/billing-api/health'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'app_key'         => 'billing-api',
            'status'          => 'never_seen',
            'last_seen'       => null,
            'last_seen_ago'   => null,
            'timeout_minutes' => 10,
        ], self::body($response));
    }

    #[Test]
    public function health_reports_active(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client(self::minutesAgo(3), 10));

        $body = self::body($this->router($repo)->handle($this->authed('GET', '/api/clients/billing-api/health')));

        self::assertSame('active', $body['status']);
        self::assertMatchesRegularExpression('/^3m \d+s$/', $body['last_seen_ago']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $body['last_seen']);
    }

    #[Test]
    public function health_reports_silent(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client(self::minutesAgo(47), 10));

        $body = self::body($this->router($repo)->handle($this->authed('GET', '/api/clients/billing-api/health')));

        self::assertSame('silent', $body['status']);
    }

    #[Test]
    public function health_uses_unflushed_activity(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client(self::minutesAgo(47), 10));
        $activity = new ClientActivityTracker($repo);
        $activity->touch('billing-api');

        $body = self::body($this->router($repo, $activity)->handle($this->authed('GET', '/api/clients/billing-api/health')));

        self::assertSame('active', $body['status']);
    }

    #[Test]
    public function health_returns_404_for_unknown_client(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $response = $this->router($repo)->handle($this->authed('GET', '/api/clients/ghost/health'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function health_requires_ui_secret(): void
    {
        $response = $this->router($this->createStub(ClientRepository::class))
            ->handle(new ServerRequest('GET', 'http://localhost/api/clients/billing-api/health'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function health_only_allows_get(): void
    {
        $response = $this->router($this->createStub(ClientRepository::class))
            ->handle($this->authed('POST', '/api/clients/billing-api/health'));

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function health_returns_503_in_file_mode(): void
    {
        $response = $this->router(null, storageType: 'file')
            ->handle($this->authed('GET', '/api/clients/billing-api/health'));

        self::assertSame(503, $response->getStatusCode());
    }

    // ── health fields on list / get ───────────────────────────────────────────

    #[Test]
    public function list_includes_last_seen_timeout_and_status(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('all')->willReturn([
            $this->client(self::minutesAgo(47), 10, 'billing-api'),
            $this->client(null, null, 'auth-service'),
        ]);

        $clients = self::body($this->router($repo)->handle($this->authed('GET', '/api/clients')))['clients'];

        self::assertSame('silent', $clients[0]['status']);
        self::assertSame(10, $clients[0]['heartbeat_timeout_min']);
        self::assertNotNull($clients[0]['last_seen_at']);
        self::assertTrue($clients[0]['active']);
        self::assertSame('never_seen', $clients[1]['status']);
        self::assertNull($clients[1]['last_seen_at']);
        self::assertNull($clients[1]['heartbeat_timeout_min']);
    }

    // ── PUT heartbeat_timeout_min ─────────────────────────────────────────────

    #[Test]
    public function update_sets_heartbeat_timeout(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client());
        $repo->method('update')->willReturn($this->client());
        $repo->expects(self::once())
            ->method('setHeartbeatTimeout')
            ->with('billing-api', 15)
            ->willReturn($this->client(timeout: 15));

        $response = $this->router($repo)->handle(
            $this->authed('PUT', '/api/clients/billing-api', json_encode(['heartbeat_timeout_min' => 15]))
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(15, self::body($response)['heartbeat_timeout_min']);
    }

    #[Test]
    public function update_clears_heartbeat_timeout_with_null(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client(timeout: 10));
        $repo->method('update')->willReturn($this->client(timeout: 10));
        $repo->expects(self::once())
            ->method('setHeartbeatTimeout')
            ->with('billing-api', null)
            ->willReturn($this->client());

        $response = $this->router($repo)->handle(
            $this->authed('PUT', '/api/clients/billing-api', json_encode(['heartbeat_timeout_min' => null]))
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull(self::body($response)['heartbeat_timeout_min']);
    }

    #[Test]
    public function update_leaves_timeout_alone_when_absent(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client(timeout: 10));
        $repo->method('update')->willReturn($this->client(timeout: 10));
        $repo->expects(self::never())->method('setHeartbeatTimeout');

        $response = $this->router($repo)->handle(
            $this->authed('PUT', '/api/clients/billing-api', json_encode(['name' => 'Billing']))
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function update_rejects_invalid_timeout(): void
    {
        foreach ([0, -5, 1.5, 'ten', 43201, true] as $invalid) {
            $repo = $this->createMock(ClientRepository::class);
            $repo->method('findByKey')->willReturn($this->client());
            $repo->expects(self::never())->method('update');

            $response = $this->router($repo)->handle(
                $this->authed('PUT', '/api/clients/billing-api', json_encode(['heartbeat_timeout_min' => $invalid]))
            );

            self::assertSame(422, $response->getStatusCode(), 'value: ' . var_export($invalid, true));
        }
    }

    // ── last-seen on ingestion ────────────────────────────────────────────────

    #[Test]
    public function synchronous_ingest_marks_app_keys_as_seen(): void
    {
        $activity = new ClientActivityTracker($this->createStub(ClientRepository::class));
        $router   = $this->router($this->createStub(ClientRepository::class), $activity);

        $request = $this->ingestRequest([
            'app_key' => 'billing-api',
            'app_id'  => 'web-1',
            'logs'   => [
                ['app_key' => 'billing-api', 'message' => 'one'],
                ['app_key' => 'auth-service', 'message' => 'two'],
            ],
        ]);

        $response = $router->handle($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($activity->lastSeen('billing-api'));
        self::assertNotNull($activity->lastSeen('auth-service'));
    }

    #[Test]
    public function failed_ingest_does_not_mark_as_seen(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('save')->willThrowException(new \RuntimeException('storage down'));
        $activity = new ClientActivityTracker($this->createStub(ClientRepository::class));
        $router   = $this->router($this->createStub(ClientRepository::class), $activity, storage: $storage);

        $request = $this->ingestRequest(['app_key' => 'billing-api', 'app_id' => 'web-1', 'message' => 'x']);

        $response = $router->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($activity->lastSeen('billing-api'));
    }

    #[Test]
    public function failed_timeout_update_still_flushes_the_auth_cache(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->client());
        $repo->method('update')->willReturn($this->client());
        $repo->method('setHeartbeatTimeout')->willThrowException(new \RuntimeException('db gone away'));

        $writeAuthDb = $this->createMock(CacheFlushableInterface::class);
        $writeAuthDb->expects(self::once())->method('flushCache');

        $response = $this->router($repo, writeAuthDb: $writeAuthDb)->handle(
            $this->authed('PUT', '/api/clients/billing-api', json_encode(['active' => false, 'heartbeat_timeout_min' => 5]))
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function delete_forgets_unwritten_activity(): void
    {
        $repo = $this->createStub(ClientRepository::class);
        $repo->method('delete')->willReturn(0);
        $activity = new ClientActivityTracker($repo);
        $activity->touch('billing-api');

        $response = $this->router($repo, $activity)->handle($this->authed('DELETE', '/api/clients/billing-api'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($activity->lastSeen('billing-api'));
    }
}
