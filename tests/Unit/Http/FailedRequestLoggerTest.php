<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use LogService\Auth\SingleKeyWriteAuth;
use LogService\Http\ClientIpResolver;
use LogService\Http\FailedRequestLogger;
use LogService\Http\Router;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

final class FailedRequestLoggerTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    private float $now = 100.0;

    private function middleware(): FailedRequestLogger
    {
        return new FailedRequestLogger(
            function (string $level, string $message, array $context = []): void {
                $this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
            },
            new ClientIpResolver(),
            fn() => $this->now,
        );
    }

    private function request(string $method = 'GET', string $uri = 'http://localhost/api/logs', array $headers = []): ServerRequest
    {
        return new ServerRequest($method, $uri, $headers, '', '1.1', ['REMOTE_ADDR' => '203.0.113.7']);
    }

    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /** @param ResponseInterface|PromiseInterface<ResponseInterface> $result */
    private static function settle(ResponseInterface|PromiseInterface $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        $response = null;
        $result->then(function (ResponseInterface $r) use (&$response): void { $response = $r; });
        self::assertInstanceOf(ResponseInterface::class, $response);

        return $response;
    }

    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function does_not_log_successful_requests(): void
    {
        $this->middleware()($this->request(), fn() => new Response(200));
        $this->middleware()($this->request(), fn() => resolve(new Response(202)));
        $this->middleware()($this->request(), fn() => new Response(304));

        self::assertSame([], $this->logged);
    }

    #[Test]
    public function logs_a_4xx_as_a_warning_with_the_request_and_error(): void
    {
        $request = $this->request('POST', 'http://localhost/api/logs?secret=x', [
            'User-Agent'    => 'pancake/1.0',
            'X-Api-Key'     => 'billing',
            'X-Api-Token'   => 'lss_do-not-log',
            'Authorization' => 'Bearer do-not-log',
        ]);

        $response = $this->middleware()($request, function () {
            $this->now += 0.0125;
            return self::json(401, ['error' => 'Unauthorized', 'reason' => 'invalid token']);
        });

        self::assertSame(401, self::settle($response)->getStatusCode());
        self::assertCount(1, $this->logged);
        $entry = $this->logged[0];
        self::assertSame('warning', $entry['level']);
        self::assertSame('POST /api/logs → 401 Unauthorized: invalid token', $entry['message']);
        self::assertSame([
            'method'      => 'POST',
            'path'        => '/api/logs',
            'status'      => 401,
            'duration_ms' => 12.5,
            'client_ip'   => '203.0.113.7',
            'user_agent'  => 'pancake/1.0',
            'app_key'     => 'billing',
            'error'       => 'Unauthorized',
            'reason'      => 'invalid token',
        ], $entry['context']);
        self::assertStringNotContainsString('do-not-log', json_encode($entry));
    }

    #[Test]
    public function logs_a_5xx_as_an_error(): void
    {
        $this->middleware()($this->request(), fn() => resolve(self::json(503, ['error' => 'Retention API requires UI_SECRET to be set'])));

        self::assertSame('error', $this->logged[0]['level']);
        self::assertSame('GET /api/logs → 503 Service Unavailable: Retention API requires UI_SECRET to be set', $this->logged[0]['message']);
    }

    #[Test]
    public function logs_a_4xx_without_a_json_body(): void
    {
        $this->middleware()($this->request('GET', 'http://localhost/wp-login.php'), fn() => new Response(404, ['Content-Type' => 'text/plain'], 'nope'));

        self::assertSame('GET /wp-login.php → 404 Not Found', $this->logged[0]['message']);
        self::assertArrayNotHasKey('error', $this->logged[0]['context']);
    }

    #[Test]
    public function copies_the_first_errors_of_a_rejected_batch(): void
    {
        $errors = array_map(fn(int $i) => "Entry {$i}: 'message' is required", range(0, 6));

        $this->middleware()($this->request('POST'), fn() => self::json(400, ['saved' => 0, 'errors' => $errors]));

        $context = $this->logged[0]['context'];
        self::assertCount(5, $context['errors']);
        self::assertSame(7, $context['errors_total']);
        self::assertSame("Entry 0: 'message' is required", $context['error']);
        self::assertSame("POST /api/logs → 400 Bad Request: Entry 0: 'message' is required", $this->logged[0]['message']);
    }

    #[Test]
    public function leaves_the_response_body_readable(): void
    {
        $response = self::settle($this->middleware()($this->request(), fn() => self::json(401, ['error' => 'Unauthorized'])));

        self::assertSame(['error' => 'Unauthorized'], json_decode((string) $response->getBody(), true));
    }

    #[Test]
    public function turns_a_thrown_exception_into_a_logged_json_500(): void
    {
        $response = self::settle($this->middleware()($this->request(), function (): never {
            throw new \RuntimeException('database is gone');
        }));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => 'Internal server error'], json_decode((string) $response->getBody(), true));
        self::assertSame('error', $this->logged[0]['level']);
        self::assertSame('GET /api/logs → 500 Internal Server Error: Uncaught RuntimeException: database is gone', $this->logged[0]['message']);
        self::assertSame(\RuntimeException::class, $this->logged[0]['context']['exception']);
        self::assertSame(__FILE__, $this->logged[0]['context']['file']);
    }

    #[Test]
    public function turns_a_rejected_promise_into_a_logged_json_500(): void
    {
        $response = self::settle($this->middleware()($this->request(), fn() => reject(new \LogicException('boom'))));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(\LogicException::class, $this->logged[0]['context']['exception']);
    }

    #[Test]
    public function a_failing_logger_does_not_break_the_response(): void
    {
        $middleware = new FailedRequestLogger(function (): void {
            throw new \RuntimeException('log is down');
        });

        $response = self::settle($middleware($this->request(), fn() => self::json(400, ['error' => 'Invalid JSON body'])));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function logs_the_router_s_own_rejections(): void
    {
        $router = new Router(
            storage:     $this->createStub(StorageInterface::class),
            hub:         new LogHub(''),
            writeAuth:   new SingleKeyWriteAuth('write-secret'),
            uiSecret:    'ui-secret',
            storageType: 'file',
        );

        $this->middleware()($this->request('GET', 'http://localhost/api/logs'), $router->handle(...));
        $this->middleware()($this->request('GET', 'http://localhost/nowhere'), $router->handle(...));

        self::assertSame('GET /api/logs → 401 Unauthorized', $this->logged[0]['message']);
        self::assertSame('GET /nowhere → 404 Not Found', $this->logged[1]['message']);
        self::assertSame('Not found', $this->logged[1]['context']['error']);
    }
}
