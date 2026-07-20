<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\AuthResult;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Auth\WriteAuthInterface;
use LogService\Http\Router;
use LogService\Models\LogEntry;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class LogEndpointsTest extends TestCase
{
    private function makeRouter(
        ?StorageInterface  $storage   = null,
        ?WriteAuthInterface $writeAuth = null,
        string             $uiSecret  = 'test-ui-secret',
    ): Router {
        return new Router(
            storage:     $storage ?? $this->createStub(StorageInterface::class),
            hub:         new LogHub(''),
            writeAuth:   $writeAuth ?? new SingleKeyWriteAuth(''),
            uiSecret:    $uiSecret,
            storageType: 'file',
        );
    }

    private function authed(string $method, string $path): ServerRequest
    {
        return (new ServerRequest($method, "http://localhost{$path}"))
            ->withHeader('Authorization', 'Bearer test-ui-secret');
    }

    // ── OPTIONS ────────────────────────────────────────────────────────────────

    #[Test]
    public function options_returns_204_with_cors_headers(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('OPTIONS', 'http://localhost/anything'));

        self::assertSame(204, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    // ── GET /api/health ────────────────────────────────────────────────────────

    #[Test]
    public function health_returns_200_with_status_and_connection_count(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/api/health'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $body['status']);
        self::assertArrayHasKey('time', $body);
        self::assertSame(0, $body['ws_connections']);
    }

    // ── Static routes ──────────────────────────────────────────────────────────

    #[Test]
    public function docs_serves_the_swagger_ui_html(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/docs'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function root_also_serves_the_swagger_ui_html(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function favicon_is_served(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/favicon.ico'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('image/x-icon', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function openapi_yaml_is_served(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/openapi.yaml'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/yaml', $response->getHeaderLine('Content-Type'));
    }

    // ── Unknown route ──────────────────────────────────────────────────────────

    #[Test]
    public function unknown_route_returns_404(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/nope'));

        self::assertSame(404, $response->getStatusCode());
    }

    // ── POST /api/logs — auth ─────────────────────────────────────────────────

    #[Test]
    public function ingest_returns_401_on_auth_failure(): void
    {
        $writeAuth = new SingleKeyWriteAuth('correct-secret');
        $request   = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withHeader('Authorization', 'Bearer wrong-secret')
            ->withBody(Utils::streamFor('{}'));

        $response = $this->makeRouter(writeAuth: $writeAuth)->handle($request);

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('reason', $body);
    }

    // ── POST /api/logs — validation ───────────────────────────────────────────

    #[Test]
    public function ingest_returns_400_for_invalid_json_body(): void
    {
        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor('not json'));

        $response = $this->makeRouter()->handle($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function ingest_returns_400_when_app_key_and_app_id_are_missing(): void
    {
        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor(json_encode(['message' => 'hi'])));

        $response = $this->makeRouter()->handle($request);

        self::assertSame(400, $response->getStatusCode());
    }

    // ── POST /api/logs — success paths ────────────────────────────────────────

    #[Test]
    public function ingest_saves_a_single_entry_and_broadcasts_it(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('save')->with(self::isInstanceOf(LogEntry::class));

        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor(json_encode([
                'app_key' => 'my-app',
                'app_id'  => 'prod',
                'message' => 'hello world',
            ])));

        $response = $this->makeRouter($storage)->handle($request);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $body['saved']);
        self::assertCount(1, $body['entries']);
        self::assertNull($body['errors']);
    }

    #[Test]
    public function ingest_saves_a_batch_of_entries(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::exactly(2))->method('save');

        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor(json_encode([
                'app_key' => 'my-app',
                'app_id'  => 'prod',
                'logs'    => [
                    ['message' => 'first'],
                    ['message' => 'second'],
                ],
            ])));

        $response = $this->makeRouter($storage)->handle($request);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(2, $body['saved']);
    }

    #[Test]
    public function ingest_reports_per_entry_errors_without_failing_the_whole_batch(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('save');

        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor(json_encode([
                'app_key' => 'my-app',
                'app_id'  => 'prod',
                'logs'    => [
                    ['message' => 'good entry'],
                    ['message' => ['not', 'a', 'string']], // triggers a TypeError inside LogEntry::fromArray
                ],
            ])));

        $response = $this->makeRouter($storage)->handle($request);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $body['saved']);
        self::assertNotEmpty($body['errors']);
    }

    #[Test]
    public function ingest_returns_400_when_every_entry_in_the_batch_fails(): void
    {
        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor(json_encode([
                'app_key' => 'my-app',
                'app_id'  => 'prod',
                'logs'    => [['message' => ['not', 'a', 'string']]],
            ])));

        $response = $this->makeRouter()->handle($request);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $body['saved']);
    }

    #[Test]
    public function ingest_resolves_app_key_from_the_authenticated_client_when_not_single_key(): void
    {
        $storage   = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())->method('save')->with(
            self::callback(fn(LogEntry $e) => $e->appKey === 'resolved-app'),
        );

        $writeAuth = $this->createStub(WriteAuthInterface::class);
        $writeAuth->method('authenticate')->willReturn(AuthResult::success('resolved-app'));

        $request = (new ServerRequest('POST', 'http://localhost/api/logs'))
            ->withBody(Utils::streamFor(json_encode([
                'app_key' => 'ignored-because-db-mode',
                'app_id'  => 'prod',
                'message' => 'hi',
            ])));

        $response = $this->makeRouter($storage, $writeAuth)->handle($request);

        self::assertSame(201, $response->getStatusCode());
    }

    // ── GET /api/logs ──────────────────────────────────────────────────────────

    #[Test]
    public function search_returns_401_without_auth(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/api/logs'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function search_is_authorised_via_query_token(): void
    {
        $response = $this->makeRouter()->handle(
            new ServerRequest('GET', 'http://localhost/api/logs?token=test-ui-secret'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function search_forwards_filters_limit_and_offset_to_storage(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())
            ->method('search')
            ->with(
                self::callback(fn(array $f) => $f['app_key'] === 'my-app' && $f['level'] === 'error'),
                50,
                10,
            )
            ->willReturn(['total' => 0, 'limit' => 50, 'offset' => 10, 'entries' => []]);

        $response = $this->makeRouter($storage)->handle(
            $this->authed('GET', '/api/logs?app_key=my-app&level=error&limit=50&offset=10'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function search_clamps_limit_to_the_1_to_1000_range(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::once())
            ->method('search')
            ->with(self::isArray(), 1000, 0)
            ->willReturn(['total' => 0, 'limit' => 1000, 'offset' => 0, 'entries' => []]);

        $response = $this->makeRouter($storage)->handle(
            $this->authed('GET', '/api/logs?limit=99999'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // ── GET /api/logs/{id} ─────────────────────────────────────────────────────

    #[Test]
    public function get_by_id_returns_401_without_auth(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/api/logs/abc123'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function get_by_id_returns_404_when_not_found(): void
    {
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('findById')->willReturn(null);

        $response = $this->makeRouter($storage)->handle($this->authed('GET', '/api/logs/missing'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function get_by_id_returns_200_with_the_entry(): void
    {
        $entry = LogEntry::fromArray([
            'id'         => '01AAA',
            'trace_id'   => 'trace-1',
            'batch_id'   => null,
            'app_key'    => 'my-app',
            'app_id'     => 'prod',
            'user_agent' => null,
            'level'      => 'info',
            'category'   => 'general',
            'message'    => 'hi',
            'context'    => null,
            'timestamp'  => '2024-01-01T00:00:00.000Z',
            'created_at' => '2024-01-01T00:00:00.000Z',
        ]);

        $storage = $this->createMock(StorageInterface::class);
        $storage->method('findById')->with('01AAA')->willReturn($entry);

        $response = $this->makeRouter($storage)->handle($this->authed('GET', '/api/logs/01AAA'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('01AAA', $body['id']);
    }
}
