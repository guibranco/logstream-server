<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Demo\DemoTraffic;
use LogService\Http\Router;
use LogService\Models\LogEntry;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

/** GET /demo, GET /api/demo/scenarios and POST /api/demo/run (DEMO_MODE only). */
final class DemoEndpointsTest extends TestCase
{
    private function makeRouter(bool $demoMode, ?StorageInterface $storage = null): Router
    {
        return new Router(
            storage:     $storage ?? $this->createStub(StorageInterface::class),
            hub:         new LogHub(''),
            writeAuth:   new SingleKeyWriteAuth('write-secret'),
            uiSecret:    'test-ui-secret',
            storageType: 'file',
            demoMode:    $demoMode,
        );
    }

    private function postRun(Router $router, mixed $body): array
    {
        $response = $router->handle(
            (new ServerRequest('POST', 'http://localhost/api/demo/run'))->withBody(Utils::streamFor(json_encode($body))),
        );
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function demo_paths_are_not_found_outside_demo_mode(): void
    {
        $router = $this->makeRouter(false);

        self::assertSame(404, $router->handle(new ServerRequest('GET', 'http://localhost/demo'))->getStatusCode());
        self::assertSame(404, $router->handle(new ServerRequest('GET', 'http://localhost/api/demo/scenarios'))->getStatusCode());
        self::assertSame(404, $this->postRun($router, ['scenario' => 'checkout'])[0]);
    }

    #[Test]
    public function demo_mode_serves_the_page_and_the_scenarios(): void
    {
        $router = $this->makeRouter(true);

        $page = $router->handle(new ServerRequest('GET', 'http://localhost/demo'));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('/api/demo/run', (string) $page->getBody());

        $list = json_decode((string) $router->handle(new ServerRequest('GET', 'http://localhost/api/demo/scenarios'))->getBody(), true);
        self::assertSame(array_keys(DemoTraffic::SCENARIOS), array_keys($list['scenarios']));
    }

    #[Test]
    public function info_reports_demo_mode(): void
    {
        foreach ([true, false] as $mode) {
            $info = json_decode((string) $this->makeRouter($mode)->handle(new ServerRequest('GET', 'http://localhost/api/info'))->getBody(), true);
            self::assertSame($mode, $info['features']['demo_mode']);
        }
    }

    #[Test]
    public function run_stores_the_scenario_entries_without_a_key(): void
    {
        $saved   = [];
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('save')->willReturnCallback(function (LogEntry $e) use (&$saved): void { $saved[] = $e; });

        [$status, $body] = $this->postRun($this->makeRouter(true, $storage), ['scenario' => 'checkout', 'count' => 3]);

        self::assertSame(201, $status);
        self::assertSame(count($saved), $body['saved']);
        self::assertCount(3, array_unique(array_map(static fn(LogEntry $e) => $e->traceId, $saved)));
        foreach ($saved as $entry) {
            self::assertArrayHasKey($entry->appKey, DemoTraffic::SERVICES);
            self::assertNotNull($entry->messageTemplate);
        }
    }

    #[Test]
    public function run_backfills_history(): void
    {
        $saved   = 0;
        $storage = $this->createStub(StorageInterface::class);
        $storage->method('save')->willReturnCallback(function () use (&$saved): void { $saved++; });

        [$status, $body] = $this->postRun($this->makeRouter(true, $storage), ['backfill_hours' => 1]);

        self::assertSame(201, $status);
        self::assertGreaterThan(0, $saved);
    }

    #[Test]
    public function run_validates_its_input(): void
    {
        $router = $this->makeRouter(true);

        self::assertSame(422, $this->postRun($router, ['scenario' => 'nope'])[0]);
        self::assertSame(422, $this->postRun($router, ['scenario' => 'login', 'count' => 0])[0]);
        self::assertSame(422, $this->postRun($router, ['scenario' => 'login', 'count' => 51])[0]);
        self::assertSame(422, $this->postRun($router, ['scenario' => 'login', 'count' => '5'])[0]);
        self::assertSame(422, $this->postRun($router, ['backfill_hours' => 7])[0]);
        self::assertSame(422, $this->postRun($router, ['backfill_hours' => 0])[0]);
        self::assertSame(400, $this->postRun($router, 'not an object')[0]);
    }
}
