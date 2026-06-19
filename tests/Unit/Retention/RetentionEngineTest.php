<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\PrunerInterface;
use LogService\Retention\RetentionConfig;
use LogService\Retention\RetentionEngine;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetentionEngineTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // Disabled config
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_empty_array_when_config_is_disabled(): void
    {
        $pruner = $this->createMock(PrunerInterface::class);
        $pruner->expects(self::never())->method('prune');

        $engine  = new RetentionEngine($pruner, RetentionConfig::disabled());
        $results = $engine->run();

        self::assertSame([], $results);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Normal execution
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_runs_each_policy_and_returns_results(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'  => true,
            'policies' => [
                ['name' => 'policy-a', 'older_than_days' => 7],
                ['name' => 'policy-b', 'older_than_days' => 30],
            ],
        ]);

        $pruner = $this->createMock(PrunerInterface::class);
        $pruner->expects(self::exactly(2))
            ->method('prune')
            ->willReturnOnConsecutiveCalls(10, 5);

        $engine  = new RetentionEngine($pruner, $config);
        $results = $engine->run();

        self::assertCount(2, $results);
        self::assertSame('policy-a', $results[0]->policy);
        self::assertSame(10, $results[0]->pruned);
        self::assertNull($results[0]->error);

        self::assertSame('policy-b', $results[1]->policy);
        self::assertSame(5, $results[1]->pruned);
        self::assertNull($results[1]->error);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Error isolation
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_captures_policy_error_and_continues_with_remaining_policies(): void
    {
        $config = RetentionConfig::fromArray([
            'enabled'  => true,
            'policies' => [
                ['name' => 'will-throw', 'older_than_days' => 7],
                ['name' => 'will-succeed', 'older_than_days' => 30],
            ],
        ]);

        $pruner = $this->createMock(PrunerInterface::class);
        $pruner->expects(self::exactly(2))
            ->method('prune')
            ->willReturnCallback(function (RetentionPolicy $policy): int {
                if ($policy->name === 'will-throw') {
                    throw new \RuntimeException('disk full');
                }
                return 3;
            });

        $engine  = new RetentionEngine($pruner, $config);
        $results = $engine->run();

        self::assertCount(2, $results);

        self::assertSame('will-throw', $results[0]->policy);
        self::assertSame(0, $results[0]->pruned);
        self::assertSame('disk full', $results[0]->error);

        self::assertSame('will-succeed', $results[1]->policy);
        self::assertSame(3, $results[1]->pruned);
        self::assertNull($results[1]->error);
    }

    #[Test]
    public function it_returns_empty_array_when_no_policies_are_configured(): void
    {
        $config = RetentionConfig::fromArray(['enabled' => true, 'policies' => []]);
        $pruner = $this->createMock(PrunerInterface::class);
        $pruner->expects(self::never())->method('prune');

        $results = (new RetentionEngine($pruner, $config))->run();

        self::assertSame([], $results);
    }
}
