<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionEngineInterface;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionPolicyRepositoryInterface;
use LogService\Retention\RetentionResult;
use LogService\Retention\RetentionRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetentionRunnerTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // runAll
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_empty_array_when_no_policies_configured(): void
    {
        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::never())->method('purge');

        $config = RetentionConfig::fromArray(['enabled' => true, 'policies' => []]);
        $runner = new RetentionRunner($engine, $config);

        self::assertSame([], $runner->runAll());
    }

    #[Test]
    public function it_purges_each_policy_and_returns_results(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'  => true,
            'policies' => [
                ['name' => 'alpha', 'older_than_days' => 7],
                ['name' => 'beta',  'older_than_days' => 30],
            ],
        ]);

        $resultA = new RetentionResult(policy: 'alpha', pruned: 10);
        $resultB = new RetentionResult(policy: 'beta',  pruned: 5);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::exactly(2))
            ->method('purge')
            ->willReturnOnConsecutiveCalls($resultA, $resultB);

        $results = (new RetentionRunner($engine, $config))->runAll();

        self::assertCount(2, $results);
        self::assertSame('alpha', $results[0]->policy);
        self::assertSame(10, $results[0]->pruned);
        self::assertSame('beta', $results[1]->policy);
        self::assertSame(5, $results[1]->pruned);
    }

    #[Test]
    public function it_captures_engine_exceptions_and_continues(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'  => true,
            'policies' => [
                ['name' => 'will-throw',   'older_than_days' => 7],
                ['name' => 'will-succeed', 'older_than_days' => 30],
            ],
        ]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::exactly(2))
            ->method('purge')
            ->willReturnCallback(function (RetentionPolicy $policy): RetentionResult {
                if ($policy->name === 'will-throw') {
                    throw new \RuntimeException('disk full');
                }
                return new RetentionResult(policy: $policy->name, pruned: 3);
            });

        $results = (new RetentionRunner($engine, $config))->runAll();

        self::assertCount(2, $results);
        self::assertSame('will-throw', $results[0]->policy);
        self::assertSame(0, $results[0]->pruned);
        self::assertSame('disk full', $results[0]->error);

        self::assertSame('will-succeed', $results[1]->policy);
        self::assertSame(3, $results[1]->pruned);
        self::assertNull($results[1]->error);
    }

    #[Test]
    public function it_passes_dry_run_flag_to_engine(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'  => true,
            'policies' => [['name' => 'p', 'older_than_days' => 7]],
        ]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->with(
                self::isInstanceOf(RetentionPolicy::class),
                true,
            )
            ->willReturn(new RetentionResult(policy: 'p', pruned: 0, dryRun: true));

        (new RetentionRunner($engine, $config))->runAll(dryRun: true);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // runPolicy
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_runs_a_single_named_policy(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'  => true,
            'policies' => [
                ['name' => 'alpha', 'older_than_days' => 7],
                ['name' => 'beta',  'older_than_days' => 30],
            ],
        ]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->with(
                self::callback(fn(RetentionPolicy $p) => $p->name === 'beta'),
                false,
            )
            ->willReturn(new RetentionResult(policy: 'beta', pruned: 7));

        $result = (new RetentionRunner($engine, $config))->runPolicy('beta');

        self::assertSame('beta', $result->policy);
        self::assertSame(7, $result->pruned);
    }

    #[Test]
    public function it_throws_for_unknown_policy_name(): void
    {
        $config = RetentionConfig::fromArray(['enabled' => true, 'policies' => []]);
        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::never())->method('purge');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/ghost/");

        (new RetentionRunner($engine, $config))->runPolicy('ghost');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // listPolicies
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_lists_all_configured_policies(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'  => true,
            'policies' => [
                ['name' => 'alpha', 'older_than_days' => 7],
                ['name' => 'beta',  'older_than_days' => 30],
            ],
        ]);

        $engine  = $this->createMock(RetentionEngineInterface::class);
        $runner  = new RetentionRunner($engine, $config);
        $polices = $runner->listPolicies();

        self::assertCount(2, $polices);
        self::assertSame('alpha', $polices[0]->name);
        self::assertSame('beta', $polices[1]->name);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Repository-driven behaviour (policy created after server start)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function run_all_uses_repository_policies_when_repository_is_provided(): void
    {
        $config = RetentionConfig::fromArray(['enabled' => true, 'policies' => []]);

        $livePolicy = RetentionPolicy::fromArray(['name' => 'live', 'older_than_days' => 1]);

        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([$livePolicy]);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->with(self::callback(fn(RetentionPolicy $p) => $p->name === 'live'), false)
            ->willReturn(new RetentionResult(policy: 'live', pruned: 5));

        $results = (new RetentionRunner($engine, $config, $repo))->runAll();

        self::assertCount(1, $results);
        self::assertSame('live', $results[0]->policy);
    }

    #[Test]
    public function run_policy_resolves_via_repository(): void
    {
        $config = RetentionConfig::fromArray(['enabled' => true, 'policies' => []]);

        $livePolicy = RetentionPolicy::fromArray(['name' => 'live', 'older_than_days' => 1]);

        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('find')->with('live')->willReturn($livePolicy);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::once())
            ->method('purge')
            ->willReturn(new RetentionResult(policy: 'live', pruned: 3));

        $result = (new RetentionRunner($engine, $config, $repo))->runPolicy('live');

        self::assertSame('live', $result->policy);
        self::assertSame(3, $result->pruned);
    }

    #[Test]
    public function run_policy_throws_when_repository_returns_null(): void
    {
        $config = RetentionConfig::fromArray(['enabled' => true, 'policies' => []]);

        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('find')->willReturn(null);

        $engine = $this->createMock(RetentionEngineInterface::class);
        $engine->expects(self::never())->method('purge');

        $this->expectException(\InvalidArgumentException::class);

        (new RetentionRunner($engine, $config, $repo))->runPolicy('ghost');
    }

    #[Test]
    public function list_policies_uses_repository_when_provided(): void
    {
        $config = RetentionConfig::fromArray(['enabled' => true, 'policies' => [
            ['name' => 'stale', 'older_than_days' => 90],
        ]]);

        $livePolicy = RetentionPolicy::fromArray(['name' => 'live', 'older_than_days' => 1]);

        $repo = $this->createMock(RetentionPolicyRepositoryInterface::class);
        $repo->method('all')->willReturn([$livePolicy]);

        $engine   = $this->createMock(RetentionEngineInterface::class);
        $policies = (new RetentionRunner($engine, $config, $repo))->listPolicies();

        self::assertCount(1, $policies);
        self::assertSame('live', $policies[0]->name);
    }
}
