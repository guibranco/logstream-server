<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\CacheFlushableInterface;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Http\Router;
use LogService\Repository\Client;
use LogService\Repository\ClientRepository;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class ClientEndpointsTest extends TestCase
{
    private function makeClient(
        string  $appKey = 'billing-api',
        string  $name   = 'Billing API',
        bool    $active = true,
        ?string $token  = null,
    ): Client {
        return new Client(
            id:        1,
            name:      $name,
            appKey:    $appKey,
            active:    $active,
            createdAt: '2026-01-01T00:00:00+00:00',
            updatedAt: '2026-01-01T00:00:00+00:00',
            rawToken:  $token,
        );
    }

    private function makeRouter(
        ?ClientRepository     $clientRepo  = null,
        ?CacheFlushableInterface $writeAuthDb = null,
        string $storageType = 'mariadb',
        string $uiSecret    = 'test-ui-secret',
    ): Router {
        $storage   = $this->createStub(\LogService\Storage\StorageInterface::class);
        $hub       = new LogHub('');
        $writeAuth = new SingleKeyWriteAuth('');

        return new Router(
            storage:          $storage,
            hub:              $hub,
            writeAuth:        $writeAuth,
            uiSecret:         $uiSecret,
            storageType:      $storageType,
            clientRepository: $clientRepo,
            writeAuthDb:      $writeAuthDb,
        );
    }

    private function authed(string $method, string $path, ?string $jsonBody = null): ServerRequest
    {
        $req = (new ServerRequest($method, "http://localhost{$path}"))
            ->withHeader('Authorization', 'Bearer test-ui-secret');
        if ($jsonBody !== null) {
            $req = $req->withBody(Utils::streamFor($jsonBody));
        }
        return $req;
    }

    // ── GET /api/clients ──────────────────────────────────────────────────────

    #[Test]
    public function list_returns_200_with_clients(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('all')->willReturn([$this->makeClient()]);

        $response = $this->makeRouter($repo)->handle($this->authed('GET', '/api/clients'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['clients']);
        self::assertSame('billing-api', $body['clients'][0]['app_key']);
        self::assertArrayNotHasKey('api_token', $body['clients'][0]);
    }

    #[Test]
    public function list_returns_503_when_not_in_db_mode(): void
    {
        $response = $this->makeRouter(null, null, 'file')
            ->handle($this->authed('GET', '/api/clients'));

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function list_returns_401_without_auth(): void
    {
        $repo     = $this->createMock(ClientRepository::class);
        $response = $this->makeRouter($repo)->handle(
            new ServerRequest('GET', 'http://localhost/api/clients')
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // ── GET /api/clients/{key} ────────────────────────────────────────────────

    #[Test]
    public function get_returns_200_for_existing_client(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('findByKey')->with('billing-api')->willReturn($this->makeClient());

        $response = $this->makeRouter($repo)->handle($this->authed('GET', '/api/clients/billing-api'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('billing-api', $body['app_key']);
        self::assertArrayNotHasKey('api_token', $body);
    }

    #[Test]
    public function get_returns_404_for_unknown_client(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $response = $this->makeRouter($repo)->handle($this->authed('GET', '/api/clients/unknown'));

        self::assertSame(404, $response->getStatusCode());
    }

    // ── POST /api/clients ─────────────────────────────────────────────────────

    #[Test]
    public function create_returns_201_with_token(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('create')->willReturn($this->makeClient(token: 'generated-token-abc'));

        $response = $this->makeRouter($repo)->handle(
            $this->authed('POST', '/api/clients', json_encode(['name' => 'Billing API', 'app_key' => 'billing-api']))
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertArrayHasKey('api_token', $body);
        self::assertArrayHasKey('_note', $body);
    }

    #[Test]
    public function create_returns_422_when_name_is_missing(): void
    {
        $response = $this->makeRouter($this->createMock(ClientRepository::class))->handle(
            $this->authed('POST', '/api/clients', json_encode(['app_key' => 'billing-api']))
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_422_for_invalid_app_key_format(): void
    {
        $response = $this->makeRouter($this->createMock(ClientRepository::class))->handle(
            $this->authed('POST', '/api/clients', json_encode(['name' => 'Bad App', 'app_key' => 'Has Spaces!']))
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_409_on_duplicate_app_key(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('create')->willThrowException(
            new \RuntimeException("An application with app_key 'billing-api' already exists.")
        );

        $response = $this->makeRouter($repo)->handle(
            $this->authed('POST', '/api/clients', json_encode(['name' => 'Billing API', 'app_key' => 'billing-api']))
        );

        self::assertSame(409, $response->getStatusCode());
    }

    // ── PUT /api/clients/{key} ────────────────────────────────────────────────

    #[Test]
    public function update_returns_200_with_no_token(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('findByKey')->willReturn($this->makeClient());
        $repo->method('update')->willReturn($this->makeClient(name: 'New Name'));

        $writeAuthDb = $this->createMock(CacheFlushableInterface::class);
        $writeAuthDb->expects(self::once())->method('flushCache');

        $response = $this->makeRouter($repo, $writeAuthDb)->handle(
            $this->authed('PUT', '/api/clients/billing-api', json_encode(['name' => 'New Name']))
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('api_token', $body);
    }

    #[Test]
    public function update_returns_404_for_unknown_client(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $response = $this->makeRouter($repo)->handle(
            $this->authed('PUT', '/api/clients/unknown', json_encode(['name' => 'X']))
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // ── POST /api/clients/{key}/rotate ────────────────────────────────────────

    #[Test]
    public function rotate_returns_200_with_new_token(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('rotateToken')->willReturn($this->makeClient(token: 'new-token-xyz'));

        $writeAuthDb = $this->createMock(CacheFlushableInterface::class);
        $writeAuthDb->expects(self::once())->method('flushCache');

        $response = $this->makeRouter($repo, $writeAuthDb)->handle(
            $this->authed('POST', '/api/clients/billing-api/rotate')
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('api_token', $body);
    }

    #[Test]
    public function rotate_returns_404_for_unknown_client(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('rotateToken')->willThrowException(
            new \RuntimeException("Client 'unknown' not found.")
        );

        $response = $this->makeRouter($repo)->handle(
            $this->authed('POST', '/api/clients/unknown/rotate')
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // ── DELETE /api/clients/{key} ─────────────────────────────────────────────

    #[Test]
    public function delete_returns_200_with_log_count(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('delete')->willReturn(42);

        $writeAuthDb = $this->createMock(CacheFlushableInterface::class);
        $writeAuthDb->expects(self::once())->method('flushCache');

        $response = $this->makeRouter($repo, $writeAuthDb)->handle(
            $this->authed('DELETE', '/api/clients/billing-api')
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('billing-api', $body['deleted']);
        self::assertSame(42, $body['logs_deleted']);
    }

    #[Test]
    public function delete_returns_404_for_unknown_client(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('delete')->willThrowException(
            new \RuntimeException("Client 'unknown' not found.")
        );

        $response = $this->makeRouter($repo)->handle(
            $this->authed('DELETE', '/api/clients/unknown')
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function rotate_route_takes_priority_over_generic_key_route(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->method('rotateToken')->willReturn($this->makeClient(token: 'tok'));

        $response = $this->makeRouter($repo)->handle(
            $this->authed('POST', '/api/clients/billing-api/rotate')
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
