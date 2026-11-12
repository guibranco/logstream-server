<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\CachedRetentionVolumeAnalyzer;
use LogService\Retention\RetentionPolicy;
use LogService\Retention\RetentionVolumeAnalyzerInterface;
use LogService\Retention\RetentionVolumeReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CachedRetentionVolumeAnalyzerTest extends TestCase
{
    private float $now = 1000.0;
    private int $calls = 0;

    #[Test]
    public function it_reuses_the_report_within_the_ttl(): void
    {
        $cache = $this->cache();

        $first = $cache->analyze([], 30);
        $this->now += 299;
        $second = $cache->analyze([], 30);

        self::assertSame($first, $second);
        self::assertSame(1, $this->calls);
    }

    #[Test]
    public function it_recomputes_after_the_ttl(): void
    {
        $cache = $this->cache();

        $cache->analyze([], 30);
        $this->now += 301;
        $cache->analyze([], 30);

        self::assertSame(2, $this->calls);
    }

    #[Test]
    public function it_recomputes_when_the_window_or_policies_change(): void
    {
        $cache = $this->cache();

        $cache->analyze([], 30);
        $cache->analyze([], 60);
        $cache->analyze([RetentionPolicy::fromArray(['name' => 'a', 'older_than_days' => 7])], 60);
        $cache->analyze([RetentionPolicy::fromArray(['name' => 'a', 'older_than_days' => 8])], 60);

        self::assertSame(4, $this->calls);
    }

    #[Test]
    public function it_recomputes_after_invalidate(): void
    {
        $cache = $this->cache();

        $cache->analyze([], 30);
        $cache->invalidate();
        $cache->analyze([], 30);

        self::assertSame(2, $this->calls);
    }

    private function cache(): CachedRetentionVolumeAnalyzer
    {
        $inner = new class ($this->calls) implements RetentionVolumeAnalyzerInterface {
            public function __construct(private int &$calls) {}

            public function analyze(array $policies, int $days): RetentionVolumeReport
            {
                $this->calls++;
                return RetentionVolumeReport::fromDailyTotals($policies, [], $days, RetentionVolumeReport::SIZE_BASIS_FILE);
            }
        };

        return new CachedRetentionVolumeAnalyzer($inner, 300, fn(): float => $this->now);
    }
}
