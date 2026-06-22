<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use LogService\Auth\SingleKeyWriteAuth;
use LogService\Http\Router;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class InfoEndpointTest extends TestCase
{
    private function makeRouter(string $storageType = 'file'): Router
    {
        $storage   = $this->createStub(\LogService\Storage\StorageInterface::class);
        $hub       = new LogHub('');
        $writeAuth = new SingleKeyWriteAuth('test-secret');

        return new Router(
            storage:     $storage,
            hub:         $hub,
            writeAuth:   $writeAuth,
            uiSecret:    'test-ui-secret',
            storageType: $storageType,
        );
    }

    #[Test]
    public function returns_200_with_no_auth(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns_json_content_type(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function returns_version_field(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('version', $body);
        self::assertNotEmpty($body['version']);
    }

    #[Test]
    public function returns_file_storage_type(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('file', $body['storage_type']);
    }

    #[Test]
    public function returns_mariadb_storage_type(): void
    {
        $router   = $this->makeRouter('mariadb');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('mariadb', $body['storage_type']);
    }

    #[Test]
    public function client_management_false_for_file_storage(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['features']['client_management']);
    }

    #[Test]
    public function client_management_true_for_mariadb_storage(): void
    {
        $router   = $this->makeRouter('mariadb');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['features']['client_management']);
    }

    #[Test]
    public function websocket_feature_always_true(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['features']['websocket']);
    }

    #[Test]
    public function features_key_is_present(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('features', $body);
        self::assertArrayHasKey('client_management', $body['features']);
        self::assertArrayHasKey('retention',         $body['features']);
        self::assertArrayHasKey('websocket',         $body['features']);
    }

    #[Test]
    public function cors_header_is_present(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        self::assertNotEmpty($response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
