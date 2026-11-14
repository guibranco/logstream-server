<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\AuthResult;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Auth\WriteAuthInterface;
use LogService\Http\Router;
use LogService\Models\LogEntry;
use LogService\Repository\ClientRepository;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

/** The reserved "logstream-server" app_key and the self_logging flags in /api/info. */
final class SelfLogEndpointsTest extends TestCase
{
    private function makeRouter(
        ?StorageInterface   $storage     = null,
        ?WriteAuthInterface $writeAuth   = null,
        ?ClientRepository   $clientRepo  = null,
        bool                $selfLogging = false,
    ): Router {
        return new Router(
            storage:          $storage ?? $this->createStub(StorageInterface::class),
            hub:              new LogHub(''),
            writeAuth:        $writeAuth ?? new SingleKeyWriteAuth('write-secret'),
            uiSecret:         'test-ui-secret',
            storageType:      $clientRepo !== null ? 'mariadb' : 'file',
            clientRepository: $clientRepo,
            selfLogging:      $selfLogging,
        );
    }

    private function ingest(array $body): ServerRequest
    {
        return (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withHeader('Authorization', 'Bearer write-secret')
            ->withBody(Utils::streamFor(json_encode($body)));
    }

    // ── POST /api/logs ────────────────────────────────────────────────────────

    #[Test]
    public function ingest_rejects_the_reserved_app_key(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('save');

        $response = $this->makeRouter($storage)->handle($this->ingest([
            'app_key' => 'logstream-server',
            'app_id'  => 'prod',
            'message' => 'pretending to be the server',
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $body['saved']);
        self::assertStringContainsString('reserved', $body['errors'][0]);
    }

    #[Test]
    public function ingest_rejects_the_reserved_app_key_in_any_case(): void
    {
        $response = $this->makeRouter()->handle($this->ingest([
            'app_key' => ' LogStream-Server ',
            'app_id'  => 'prod',
            'message' => 'hi',
        ]));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function ingest_stores_the_other_entries_of_a_batch(): void
    {
        $saved   = [];
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('save')->willReturnCallback(function (LogEntry $e) use (&$saved): void {
            $saved[] = $e->appKey;
        });

        $response = $this->makeRouter($storage)->handle($this->ingest([
            'app_key' => 'billing-api',
            'app_id'  => 'prod',
            'logs'    => [
                ['message' => 'fine'],
                ['message' => 'spoofed', 'app_key' => 'logstream-server'],
            ],
        ]));
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['billing-api'], $saved);
        self::assertSame(['Entry #1: app_key \'logstream-server\' is reserved for the server\'s own logs'], $body['errors']);
    }

    #[Test]
    public function ingest_rejects_a_client_whose_registered_key_is_the_reserved_one(): void
    {
        $writeAuth = $this->createStub(WriteAuthInterface::class);
        $writeAuth->method('authenticate')->willReturn(AuthResult::success('logstream-server'));

        $response = $this->makeRouter(writeAuth: $writeAuth)->handle($this->ingest([
            'app_id'  => 'prod',
            'message' => 'hi',
        ]));

        self::assertSame(400, $response->getStatusCode());
    }

    // ── POST /api/clients ─────────────────────────────────────────────────────

    #[Test]
    public function a_client_cannot_be_registered_with_the_reserved_app_key(): void
    {
        $repo = $this->createMock(ClientRepository::class);
        $repo->expects(self::never())->method('create');

        $request = (new ServerRequest('POST', 'http://localhost/api/clients'))
            ->withHeader('Authorization', 'Bearer test-ui-secret')
            ->withBody(Utils::streamFor(json_encode(['name' => 'Me', 'app_key' => 'logstream-server'])));

        $response = $this->makeRouter(clientRepo: $repo)->handle($request);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('reserved', (string) $response->getBody());
    }

    // ── GET /api/info ─────────────────────────────────────────────────────────

    #[Test]
    public function info_reports_self_logging_enabled(): void
    {
        $response = $this->makeRouter(selfLogging: true)->handle(new ServerRequest('GET', 'http://localhost/api/info'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertTrue($body['features']['self_logging']);
        self::assertSame(['enabled' => true, 'app_key' => 'logstream-server'], $body['self_logging']);
    }

    #[Test]
    public function info_reports_self_logging_disabled(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/api/info'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertFalse($body['features']['self_logging']);
        self::assertFalse($body['self_logging']['enabled']);
    }
}
