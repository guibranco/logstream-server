<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use LogService\Auth\SingleKeyWriteAuth;
use LogService\Http\Router;
use LogService\Storage\ReportsHealth;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

/** GET /api/health with a log store that can check its backend (ClickHouse). */
final class HealthLogStoreTest extends TestCase
{
    private function health(StorageInterface $storage): array
    {
        $router = new Router(
            storage:     $storage,
            hub:         new LogHub(''),
            writeAuth:   new SingleKeyWriteAuth('test-secret'),
            uiSecret:    'test-ui-secret',
            storageType: 'mariadb',
            logStore:    'clickhouse',
        );

        $response = $router->handle(new ServerRequest('GET', 'http://localhost/api/health'));
        self::assertSame(200, $response->getStatusCode(), 'stays 200 so container health checks never restart-loop');

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function store(bool $reachable): StorageInterface
    {
        $store = $this->createStubForIntersectionOfInterfaces([StorageInterface::class, ReportsHealth::class]);
        $store->method('health')->willReturn([
            'type' => 'clickhouse', 'reachable' => $reachable, 'latency_ms' => $reachable ? 1.5 : null,
        ]);

        return $store;
    }

    #[Test]
    public function reports_a_reachable_log_store(): void
    {
        $body = $this->health($this->store(true));

        self::assertSame('ok', $body['status']);
        self::assertSame(['type' => 'clickhouse', 'reachable' => true, 'latency_ms' => 1.5], $body['log_store']);
    }

    #[Test]
    public function an_unreachable_log_store_makes_the_server_degraded(): void
    {
        $body = $this->health($this->store(false));

        self::assertSame('degraded', $body['status']);
        self::assertFalse($body['log_store']['reachable']);
    }

    #[Test]
    public function stores_that_cannot_check_their_backend_add_nothing(): void
    {
        $body = $this->health($this->createStub(StorageInterface::class));

        self::assertSame('ok', $body['status']);
        self::assertArrayNotHasKey('log_store', $body);
    }
}
