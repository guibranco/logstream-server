<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Ingest\IngestConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IngestConfigTest extends TestCase
{
    #[Test]
    public function defaults_to_buffered_ingest_with_the_async_driver(): void
    {
        $config = IngestConfig::fromEnv([]);

        self::assertTrue($config->isBuffered());
        self::assertSame(IngestConfig::DRIVER_ASYNC, $config->dbDriver);
        self::assertSame(500, $config->batchSize);
        self::assertSame(500, $config->flushIntervalMs);
        self::assertSame(50_000, $config->capacity);
        self::assertSame(10_000, $config->waitTimeoutMs);
        self::assertSame(8_000, $config->shutdownTimeoutMs);
    }

    #[Test]
    public function reads_every_setting_from_the_environment(): void
    {
        $config = IngestConfig::fromEnv([
            'INGEST_MODE'                => ' SYNC ',
            'DB_DRIVER'                  => 'PDO',
            'INGEST_BATCH_SIZE'          => '100',
            'INGEST_FLUSH_INTERVAL_MS'   => '250',
            'INGEST_BUFFER_CAPACITY'     => '1000',
            'INGEST_WAIT_TIMEOUT_MS'     => '2000',
            'INGEST_SHUTDOWN_TIMEOUT_MS' => '3000',
        ]);

        self::assertFalse($config->isBuffered());
        self::assertSame(IngestConfig::DRIVER_PDO, $config->dbDriver);
        self::assertSame(100, $config->batchSize);
        self::assertSame(250, $config->flushIntervalMs);
        self::assertSame(1000, $config->capacity);
        self::assertSame(2000, $config->waitTimeoutMs);
        self::assertSame(3000, $config->shutdownTimeoutMs);
    }

    #[Test]
    public function empty_values_fall_back_to_the_defaults(): void
    {
        $config = IngestConfig::fromEnv(['INGEST_MODE' => '', 'DB_DRIVER' => '', 'INGEST_BATCH_SIZE' => '']);

        self::assertTrue($config->isBuffered());
        self::assertSame(IngestConfig::DRIVER_ASYNC, $config->dbDriver);
        self::assertSame(500, $config->batchSize);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function invalidEnvironments(): array
    {
        return [
            'unknown mode'            => [['INGEST_MODE' => 'queue'], 'INGEST_MODE'],
            'unknown driver'          => [['DB_DRIVER' => 'mysqli'], 'DB_DRIVER'],
            'non-numeric batch size'  => [['INGEST_BATCH_SIZE' => 'lots'], 'INGEST_BATCH_SIZE'],
            'zero interval'           => [['INGEST_FLUSH_INTERVAL_MS' => '0'], 'INGEST_FLUSH_INTERVAL_MS'],
            'negative capacity'       => [['INGEST_BUFFER_CAPACITY' => '-5'], 'INGEST_BUFFER_CAPACITY'],
            'capacity below a batch'  => [['INGEST_BATCH_SIZE' => '500', 'INGEST_BUFFER_CAPACITY' => '100'], 'INGEST_BUFFER_CAPACITY'],
        ];
    }

    #[Test]
    #[DataProvider('invalidEnvironments')]
    public function rejects_invalid_settings(array $env, string $mentions): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($mentions);

        IngestConfig::fromEnv($env);
    }
}
