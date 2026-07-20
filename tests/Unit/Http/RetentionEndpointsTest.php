<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\SingleKeyWriteAuth;
use LogService\Http\Router;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionEngineInterface;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionPolicyRepositoryInterface;
use LogService\Retention\RetentionResult;
use LogService\Retention\RetentionRunner;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class RetentionEndpointsTest extends TestCase
{
    private function makeRouter(
        ?RetentionRunner                      $retentionRunner  = null,
        ?RetentionPolicyRepositoryInterface   $policyRepository = null,
    ): Router {
        return new Router(
            storage:          $this->createStub(StorageInterface::class),
            hub:              new LogHub(''),
            writeAuth:        new SingleKeyWriteAuth(''),
            uiSecret:         'test-ui-secret',
            storageType:      'file',
            retentionRunner:  $retentionRunner,
            policyRepository: $policyRepository,
        );
    }

    private function makeRunner(
        RetentionEngineInterface            $engine,
        ?RetentionPolicyRepositoryInterface $repository = null,
    ): RetentionRunner {
        return new RetentionRunner($engine, RetentionConfig::disabled(), $repository);
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

    // ── Auth ───────────────────────────────────────────────────────────────────

    #[Test]
    public function retention_routes_return_401_without_auth(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/api/retention/policies'));

        self::assertSame(401, $response->getStatusCode());
    }

    // ── POST /api/retention/run ───────────────────────────────────────────────

    #[Test]
    public function run_all_returns_503_when_not_configured(): void
    {
        $response = $this->makeRouter()->handle($this->authed('POST', '/api/retention/run'));

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function run_all_returns_the_total_pruned_across_policies(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([
            RetentionPolicy::fromArray(['name' => 'a', 'older_than_days' => 7]),
            RetentionPolicy::fromArray(['name' => 'b', 'level' => 'debug']),
        ]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->method('purge')->willReturnOnConsecutiveCalls(
            new RetentionResult(policy: 'a', pruned: 3),
            new RetentionResult(policy: 'b', pruned: 4),
        );

        $runner   = $this->makeRunner($engine, $repo);
        $response = $this->makeRouter($runner)->handle($this->authed('POST', '/api/retention/run'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $body['total_pruned']);
        self::assertCount(2, $body['policies']);
    }

    #[Test]
    public function run_all_passes_the_dry_run_query_flag_through(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([RetentionPolicy::fromArray(['name' => 'a', 'older_than_days' => 7])]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->with(self::isInstanceOf(RetentionPolicy::class), true)
            ->willReturn(new RetentionResult(policy: 'a', pruned: 0, dryRun: true));

        $runner   = $this->makeRunner($engine, $repo);
        $response = $this->makeRouter($runner)->handle(
            $this->authed('POST', '/api/retention/run?dry_run=1'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function run_all_captures_a_per_policy_engine_error_in_the_result(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([RetentionPolicy::fromArray(['name' => 'a', 'older_than_days' => 7])]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->method('purge')->willThrowException(new \RuntimeException('db exploded'));

        $runner   = $this->makeRunner($engine, $repo);
        $response = $this->makeRouter($runner)->handle($this->authed('POST', '/api/retention/run'));
        $body     = json_decode((string) $response->getBody(), true);

        // RetentionRunner::runAll() swallows per-policy engine errors into the
        // result itself rather than throwing, so the endpoint still returns 200.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('db exploded', $body['policies'][0]['error']);
    }

    // ── POST /api/retention/policies/{name}/run ───────────────────────────────

    #[Test]
    public function run_one_returns_404_for_an_unknown_policy(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('find')->willReturn(null);

        $runner   = $this->makeRunner($this->createStub(RetentionEngineInterface::class), $repo);
        $response = $this->makeRouter($runner)->handle(
            $this->authed('POST', '/api/retention/policies/unknown/run'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function run_one_returns_500_when_the_engine_throws(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $repo   = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('find')->with('x')->willReturn($policy);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->method('purge')->willThrowException(new \RuntimeException('db exploded'));

        $runner   = $this->makeRunner($engine, $repo);
        $response = $this->makeRouter($runner)->handle($this->authed('POST', '/api/retention/policies/x/run'));

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function run_one_runs_only_the_named_policy(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]);
        $repo   = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('find')->with('x')->willReturn($policy);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())->method('purge')->willReturn(new RetentionResult(policy: 'x', pruned: 9));

        $runner   = $this->makeRunner($engine, $repo);
        $response = $this->makeRouter($runner)->handle($this->authed('POST', '/api/retention/policies/x/run'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(9, $body['total_pruned']);
    }

    // ── GET /api/retention/policies ───────────────────────────────────────────

    #[Test]
    public function list_returns_503_when_repository_not_configured(): void
    {
        $runner   = $this->makeRunner($this->createStub(RetentionEngineInterface::class));
        $response = $this->makeRouter($runner, null)->handle($this->authed('GET', '/api/retention/policies'));

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function list_returns_all_policies(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([
            RetentionPolicy::fromArray(['name' => 'a']),
            RetentionPolicy::fromArray(['name' => 'b']),
        ]);

        $response = $this->makeRouter(null, $repo)->handle($this->authed('GET', '/api/retention/policies'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $body['policies']);
    }

    // ── GET /api/retention/policies/{name} ────────────────────────────────────

    #[Test]
    public function get_returns_404_for_unknown_policy(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('find')->willReturn(null);

        $response = $this->makeRouter(null, $repo)->handle($this->authed('GET', '/api/retention/policies/x'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function get_returns_the_policy(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('find')->with('x')->willReturn(RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']));

        $response = $this->makeRouter(null, $repo)->handle($this->authed('GET', '/api/retention/policies/x'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('debug', $body['level']);
    }

    // ── POST /api/retention/policies ──────────────────────────────────────────

    #[Test]
    public function create_returns_400_when_name_is_missing(): void
    {
        $repo     = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $response = $this->makeRouter(null, $repo)->handle(
            $this->authed('POST', '/api/retention/policies', json_encode(['level' => 'debug'])),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_400_for_invalid_json(): void
    {
        $repo     = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $response = $this->makeRouter(null, $repo)->handle(
            $this->authed('POST', '/api/retention/policies', 'not json'),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function create_returns_201_on_success(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->expects(self::once())->method('create');

        $response = $this->makeRouter(null, $repo)->handle(
            $this->authed('POST', '/api/retention/policies', json_encode(['name' => 'x', 'level' => 'debug'])),
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('x', $body['name']);
    }

    #[Test]
    public function create_returns_409_on_duplicate_name(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('create')->willThrowException(new \RuntimeException("Policy 'x' already exists."));

        $response = $this->makeRouter(null, $repo)->handle(
            $this->authed('POST', '/api/retention/policies', json_encode(['name' => 'x'])),
        );

        self::assertSame(409, $response->getStatusCode());
    }

    // ── PUT /api/retention/policies/{name} ────────────────────────────────────

    #[Test]
    public function update_returns_400_for_invalid_json(): void
    {
        $repo     = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $response = $this->makeRouter(null, $repo)->handle(
            $this->authed('PUT', '/api/retention/policies/x', 'not json'),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_200_on_success(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->expects(self::once())->method('update');

        $response = $this->makeRouter(null, $repo)->handle(
            $this->authed('PUT', '/api/retention/policies/x', json_encode(['level' => 'error'])),
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('x', $body['name']);
        self::assertSame('error', $body['level']);
    }

    #[Test]
    public function update_returns_404_when_policy_not_found(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('update')->willThrowException(new \RuntimeException("Policy 'x' not found."));

        $response = $this->makeRouter(null, $repo)->handle(
            $this->authed('PUT', '/api/retention/policies/x', json_encode(['level' => 'error'])),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    // ── DELETE /api/retention/policies/{name} ─────────────────────────────────

    #[Test]
    public function delete_returns_204_on_success(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->expects(self::once())->method('delete')->with('x');

        $response = $this->makeRouter(null, $repo)->handle($this->authed('DELETE', '/api/retention/policies/x'));

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_404_when_policy_not_found(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('delete')->willThrowException(new \RuntimeException("Policy 'x' not found."));

        $response = $this->makeRouter(null, $repo)->handle($this->authed('DELETE', '/api/retention/policies/x'));

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Unknown retention sub-route ───────────────────────────────────────────

    #[Test]
    public function unknown_retention_subpath_returns_404(): void
    {
        $repo     = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $response = $this->makeRouter(null, $repo)->handle($this->authed('PATCH', '/api/retention/policies/x'));

        self::assertSame(404, $response->getStatusCode());
    }
}
