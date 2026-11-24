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
    private function makeRouter(string $storageType = 'file', ?string $logStore = null): Router
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
            logStore:    $logStore,
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
    public function log_store_defaults_to_the_storage_type(): void
    {
        $response = $this->makeRouter('mariadb')->handle(new ServerRequest('GET', 'http://localhost/api/info'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('mariadb', $body['log_store']);
    }

    #[Test]
    public function reports_a_log_store_that_differs_from_the_control_plane(): void
    {
        $response = $this->makeRouter('mariadb', 'file')->handle(new ServerRequest('GET', 'http://localhost/api/info'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('mariadb', $body['storage_type']);
        self::assertSame('file', $body['log_store']);
        // Clients live in the control plane, whatever holds the entries.
        self::assertTrue($body['features']['client_management']);
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
        self::assertTrue($body['features']['multi_value_filters']);
    }

    #[Test]
    public function cors_header_is_present(): void
    {
        $router   = $this->makeRouter('file');
        $request  = new ServerRequest('GET', 'http://localhost/api/info');
        $response = $router->handle($request);

        self::assertNotEmpty($response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function reports_udp_disabled_without_a_udp_server(): void
    {
        $body = json_decode((string) $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/api/info'))->getBody(), true);

        self::assertFalse($body['features']['udp_ingest']);
        self::assertSame(['enabled' => false], $body['udp']);
    }

    #[Test]
    public function reports_udp_settings_and_health_counters(): void
    {
        $config    = \LogService\Udp\UdpConfig::fromEnv(['UDP_PORT' => '9999', 'UDP_MAX_PACKET_SIZE' => '1400']);
        $udpServer = new \LogService\Udp\UdpLogServer(
            new \LogService\Udp\UdpPacketValidator(fn(): ?string => null),
            new \LogService\Ingest\EntrySink($this->createStub(\LogService\Storage\StorageInterface::class), new LogHub('')),
        );
        $udpServer->handle('garbage', '10.0.0.1:5');

        $router = new Router(
            storage:     $this->createStub(\LogService\Storage\StorageInterface::class),
            hub:         new LogHub(''),
            writeAuth:   new SingleKeyWriteAuth('test-secret'),
            uiSecret:    'test-ui-secret',
            storageType: 'file',
            udpServer:   $udpServer,
            udpConfig:   $config,
        );

        $info = json_decode((string) $router->handle(new ServerRequest('GET', 'http://localhost/api/info'))->getBody(), true);
        self::assertTrue($info['features']['udp_ingest']);
        self::assertSame([
            'enabled'             => true,
            'port'                => 9999,
            'protocol_version'    => 1,
            'max_packet_size'     => 1400,
            'timestamp_window_ms' => 30000,
        ], $info['udp']);

        $health = json_decode((string) $router->handle(new ServerRequest('GET', 'http://localhost/api/health'))->getBody(), true);
        self::assertFalse($health['udp']['listening']);
        self::assertSame(1, $health['udp']['received']);
        self::assertSame(['invalid_json' => 1], $health['udp']['rejected']);
    }

    #[Test]
    public function health_reports_an_empty_rejection_map_as_an_object(): void
    {
        $router = new Router(
            storage:     $this->createStub(\LogService\Storage\StorageInterface::class),
            hub:         new LogHub(''),
            writeAuth:   new SingleKeyWriteAuth('test-secret'),
            uiSecret:    'test-ui-secret',
            storageType: 'file',
            udpServer:   new \LogService\Udp\UdpLogServer(
                new \LogService\Udp\UdpPacketValidator(fn(): ?string => null),
                new \LogService\Ingest\EntrySink($this->createStub(\LogService\Storage\StorageInterface::class), new LogHub('')),
            ),
            udpConfig:   \LogService\Udp\UdpConfig::fromEnv([]),
        );

        $raw = (string) $router->handle(new ServerRequest('GET', 'http://localhost/api/health'))->getBody();

        self::assertIsObject(json_decode($raw)->udp->rejected, 'serialised as {}, not []');
    }
}
