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
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Retention\RetentionVolumeReport;
use LogService\Storage\StorageInterface;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

final class RetentionEndpointsTest extends TestCase
{
    private function makeRouter(
        ?RetentionRunner                      $retentionRunner  = null,
        ?RetentionPolicyRepositoryInterface   $policyRepository = null,
        ?RetentionVolumeAnalyzerInterface     $volumeAnalyzer   = null,
    ): Router {
        return new Router(
            storage:          $this->createStub(StorageInterface::class),
            hub:              new LogHub(''),
            writeAuth:        new SingleKeyWriteAuth(''),
            uiSecret:         'test-ui-secret',
            storageType:      'file',
            retentionRunner:  $retentionRunner,
            policyRepository: $policyRepository,
            volumeAnalyzer:   $volumeAnalyzer,
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

    #[Test]
    public function retention_routes_return_503_when_ui_secret_is_not_set(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->expects(self::never())->method('all');

        $router = new Router(
            storage:          $this->createStub(StorageInterface::class),
            hub:              new LogHub(''),
            writeAuth:        new SingleKeyWriteAuth(''),
            uiSecret:         '',
            storageType:      'file',
            retentionRunner:  $this->makeRunner($this->createStub(RetentionEngineInterface::class), $repo),
            policyRepository: $repo,
        );

        $response = $router->handle(new ServerRequest('POST', 'http://localhost/api/retention/run'));

        self::assertSame(503, $response->getStatusCode());
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
        self::assertSame(0, $body['failed']);
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

    /** The UI sends {"dry_run": true} in the body, not in the query string. */
    #[Test]
    #[DataProvider('dryRunBodies')]
    public function run_all_honours_the_dry_run_flag_in_the_json_body(string $body, bool $expectDryRun): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([RetentionPolicy::fromArray(['name' => 'a', 'older_than_days' => 7])]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->with(self::isInstanceOf(RetentionPolicy::class), $expectDryRun)
            ->willReturn(new RetentionResult(policy: 'a', pruned: 0, dryRun: $expectDryRun));

        $response = $this->makeRouter($this->makeRunner($engine, $repo))->handle(
            $this->authed('POST', '/api/retention/run', $body),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /** @return array<string, array{string, bool}> */
    public static function dryRunBodies(): array
    {
        return [
            'bool true'        => ['{"dry_run": true}', true],
            'string "1"'       => ['{"dry_run": "1"}', true],
            'int 1'            => ['{"dry_run": 1}', true],
            'with null policy' => ['{"dry_run": true, "policy": null}', true],
            'null flag'        => ['{"dry_run": null}', true],
            'empty object'     => ['{}', false],
            'bool false'       => ['{"dry_run": false}', false],
            'string "false"'   => ['{"dry_run": "false"}', false],
            'absent'           => ['{}', false],
            'no body'          => ['', false],
        ];
    }

    #[Test]
    public function run_all_is_a_dry_run_when_either_query_or_body_asks_for_it(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([RetentionPolicy::fromArray(['name' => 'a', 'older_than_days' => 7])]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->with(self::isInstanceOf(RetentionPolicy::class), true)
            ->willReturn(new RetentionResult(policy: 'a', pruned: 0, dryRun: true));

        $this->makeRouter($this->makeRunner($engine, $repo))->handle(
            $this->authed('POST', '/api/retention/run?dry_run=0', '{"dry_run": true}'),
        );
    }

    #[Test]
    public function run_all_runs_only_the_policy_named_in_the_json_body(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->expects(self::never())->method('all');
        $repo->expects(self::once())->method('find')->with('only-me')
            ->willReturn(RetentionPolicy::fromArray(['name' => 'only-me', 'older_than_days' => 7]));

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->with(self::callback(fn(RetentionPolicy $p) => $p->name === 'only-me'), true)
            ->willReturn(new RetentionResult(policy: 'only-me', pruned: 2, dryRun: true));

        $response = $this->makeRouter($this->makeRunner($engine, $repo))->handle(
            $this->authed('POST', '/api/retention/run', '{"dry_run": true, "policy": "only-me"}'),
        );
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['policies']);
        self::assertSame('only-me', $body['policies'][0]['policy']);
    }

    /** A malformed request must never fall through to a real run of every policy. */
    #[Test]
    #[DataProvider('invalidRunBodies')]
    public function run_all_rejects_an_invalid_body_without_running(string $body): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->expects(self::never())->method('all');
        $repo->expects(self::never())->method('find');

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::never())->method('purge');

        $response = $this->makeRouter($this->makeRunner($engine, $repo))->handle(
            $this->authed('POST', '/api/retention/run', $body),
        );

        self::assertSame(400, $response->getStatusCode());
    }

    /** @return array<string, array{string}> */
    public static function invalidRunBodies(): array
    {
        return [
            'truncated json'     => ['{"dry_run": true'],
            'not json'           => ['dry_run=true'],
            'json scalar'        => ['true'],
            'json list'          => ['[true]'],
            'empty json array'   => ['[]'],
            'policy as object'   => ['{"policy": {"name": "a"}}'],
            'empty policy'       => ['{"dry_run": false, "policy": ""}'],
            'blank policy'       => ['{"policy": "   "}'],
            'non-string policy'  => ['{"policy": 42}'],
            'array policy'       => ['{"policy": ["a"]}'],
        ];
    }

    #[Test]
    public function run_one_rejects_an_invalid_body_without_running(): void
    {
        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->expects(self::never())->method('find');

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::never())->method('purge');

        $response = $this->makeRouter($this->makeRunner($engine, $repo))->handle(
            $this->authed('POST', '/api/retention/policies/a/run', '{"dry_run": true'),
        );

        self::assertSame(400, $response->getStatusCode());
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
        self::assertSame(1, $body['failed']);
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

    // ── GET /api/retention/volume ─────────────────────────────────────────────

    #[Test]
    public function volume_returns_503_when_no_analyzer_is_configured(): void
    {
        $repo     = $this->createStub(RetentionPolicyRepositoryInterface::class);
        $response = $this->makeRouter(null, $repo)->handle($this->authed('GET', '/api/retention/volume'));

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function volume_returns_401_without_auth(): void
    {
        $response = $this->makeRouter()->handle(new ServerRequest('GET', 'http://localhost/api/retention/volume'));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function volume_passes_policies_and_default_window_to_the_analyzer(): void
    {
        $policy = RetentionPolicy::fromArray(['name' => 'debug', 'level' => 'debug', 'older_than_days' => 2]);
        $repo   = $this->createStub(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([$policy]);

        $analyzer = $this->createMock(RetentionVolumeAnalyzerInterface::class);
        $analyzer->expects(self::once())
            ->method('analyze')
            ->with([$policy], 90)
            ->willReturn(RetentionVolumeReport::fromDailyTotals([$policy], [], 90, RetentionVolumeReport::SIZE_BASIS_FILE));

        $response = $this->makeRouter(null, $repo, $analyzer)->handle($this->authed('GET', '/api/retention/volume'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(90, $body['buckets']);
        self::assertSame('debug', $body['policies'][0]['name']);
        self::assertSame(2, $body['policies'][0]['older_than_days']);
    }

    #[Test]
    public function volume_accepts_a_days_parameter(): void
    {
        $repo = $this->createStub(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([]);

        $analyzer = $this->createMock(RetentionVolumeAnalyzerInterface::class);
        $analyzer->expects(self::once())
            ->method('analyze')
            ->with([], 14)
            ->willReturn(RetentionVolumeReport::fromDailyTotals([], [], 14, RetentionVolumeReport::SIZE_BASIS_FILE));

        $response = $this->makeRouter(null, $repo, $analyzer)->handle($this->authed('GET', '/api/retention/volume?days=14'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    #[DataProvider('invalidVolumeDays')]
    public function volume_rejects_an_invalid_days_parameter(string $days): void
    {
        $repo     = $this->createStub(RetentionPolicyRepositoryInterface::class);
        $analyzer = $this->createMock(RetentionVolumeAnalyzerInterface::class);
        $analyzer->expects(self::never())->method('analyze');

        $response = $this->makeRouter(null, $repo, $analyzer)
            ->handle($this->authed('GET', '/api/retention/volume?days=' . urlencode($days)));

        self::assertSame(400, $response->getStatusCode());
    }

    public static function invalidVolumeDays(): array
    {
        return [
            'zero'       => ['0'],
            'negative'   => ['-1'],
            'too large'  => ['3651'],
            'not number' => ['abc'],
            'decimal'    => ['1.5'],
        ];
    }

    #[Test]
    public function volume_returns_500_when_the_analyzer_fails(): void
    {
        $repo = $this->createStub(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([]);
        $analyzer = $this->createStub(RetentionVolumeAnalyzerInterface::class);
        $analyzer->method('analyze')->willThrowException(new \RuntimeException('boom'));

        $response = $this->makeRouter(null, $repo, $analyzer)->handle($this->authed('GET', '/api/retention/volume'));

        self::assertSame(500, $response->getStatusCode());
    }
}
