<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Logging;

use LogService\Logging\SelfLogConfig;
use LogService\Logging\SelfLogSinkInterface;
use LogService\Logging\ServerLogger;
use LogService\Models\LogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerLoggerTest extends TestCase
{
    /** @var list<array{0: bool, 1: string}> [isError, line] */
    private array $console = [];

    private float $now = 1000.0;

    /** @var list<LogEntry> */
    private array $written = [];

    /** @var list<LogEntry> */
    private array $writtenNow = [];

    private bool $sinkFails = false;

    private int $errorReporting;

    protected function setUp(): void
    {
        // Distribution php.ini files often leave E_DEPRECATED out; the server sets E_ALL.
        $this->errorReporting = error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        error_reporting($this->errorReporting);
    }

    private function logger(): ServerLogger
    {
        return new ServerLogger(
            console:     function (bool $isError, string $line): void { $this->console[] = [$isError, $line]; },
            clock:       fn() => $this->now,
            projectRoot: '/app',
        );
    }

    private function sink(): SelfLogSinkInterface
    {
        return new class ($this) implements SelfLogSinkInterface {
            public function __construct(private readonly ServerLoggerTest $test) {}

            public function write(LogEntry $entry): void
            {
                $this->test->record($entry, false);
            }

            public function writeNow(LogEntry $entry): void
            {
                $this->test->record($entry, true);
            }
        };
    }

    /** @internal called by the fake sink */
    public function record(LogEntry $entry, bool $now): void
    {
        if ($this->sinkFails) {
            throw new \RuntimeException('storage is down');
        }
        $now ? $this->writtenNow[] = $entry : $this->written[] = $entry;
    }

    private function enabled(string $level = 'info'): ServerLogger
    {
        $logger = $this->logger();
        $logger->configure(new SelfLogConfig(enabled: true, minLevel: $level, appId: 'vinhedo2'), '1.2.3');
        $logger->attach($this->sink());

        return $logger;
    }

    // ── Console ───────────────────────────────────────────────────────────────

    #[Test]
    public function prints_messages_in_the_existing_console_format(): void
    {
        $logger = $this->logger();
        $logger->info('storage', 'File (/data)');
        $logger->warning('config', 'UI_SECRET is not set');
        $logger->info('retention', 'Disabled');

        self::assertSame([
            [false, '[Storage]  File (/data)'],
            [true,  '[Config]   WARNING: UI_SECRET is not set'],
            [false, '[Retention] Disabled'],
        ], $this->console);
    }

    #[Test]
    public function still_prints_when_disabled(): void
    {
        $logger = $this->logger();
        $logger->configure(new SelfLogConfig(enabled: false), 'dev');
        $logger->attach($this->sink());
        $logger->error('http', 'boom');

        self::assertCount(1, $this->console);
        self::assertSame([], $this->written);
        self::assertFalse($logger->isEnabled());
    }

    // ── Storing ───────────────────────────────────────────────────────────────

    #[Test]
    public function stores_entries_under_the_reserved_app_key(): void
    {
        $logger = $this->enabled();
        $logger->info('startup', 'LogStream server started', ['http_port' => 8081]);

        self::assertCount(1, $this->written);
        $entry = $this->written[0];
        self::assertSame('logstream-server', $entry->appKey);
        self::assertSame('vinhedo2', $entry->appId);
        self::assertSame('info', $entry->level);
        self::assertSame('startup', $entry->category);
        self::assertSame('LogStream server started', $entry->message);
        self::assertSame(8081, $entry->context['http_port']);
        self::assertArrayHasKey('pid', $entry->context);
        self::assertStringStartsWith('LogStream-Server/1.2.3 (PHP ', $entry->userAgent);
        self::assertNull($entry->senderIp);
    }

    #[Test]
    public function every_entry_of_a_run_shares_the_trace_id(): void
    {
        $logger = $this->enabled();
        $logger->info('a', 'one');
        $logger->error('b', 'two');

        self::assertSame($logger->traceId(), $this->written[0]->traceId);
        self::assertSame($logger->traceId(), $this->written[1]->traceId);
        self::assertNotSame($this->written[0]->id, $this->written[1]->id);
    }

    #[Test]
    public function messages_logged_before_attach_are_stored_on_attach(): void
    {
        $logger = $this->logger();
        $logger->debug('boot', 'too detailed');
        $logger->info('storage', 'File (/data)');
        $logger->configure(new SelfLogConfig(enabled: true, minLevel: 'info', appId: 'x'), 'dev');
        $logger->warning('config', 'UI_SECRET is not set');

        self::assertSame([], $this->written);

        $logger->attach($this->sink());

        self::assertSame(['File (/data)', 'UI_SECRET is not set'], array_map(fn(LogEntry $e) => $e->message, $this->written));
        self::assertSame('x', $this->written[0]->appId);
    }

    #[Test]
    public function messages_logged_before_a_disabling_configure_are_never_stored(): void
    {
        $logger = $this->logger();
        $logger->info('storage', 'File (/data)');
        $logger->configure(new SelfLogConfig(enabled: false), 'dev');
        $logger->attach($this->sink());

        self::assertSame([], $this->written);
    }

    #[Test]
    public function messages_below_the_level_are_printed_but_not_stored(): void
    {
        $logger = $this->enabled('warning');
        $logger->info('ingest', 'Buffered');
        $logger->warning('ingest', 'Flush failed');

        self::assertCount(2, $this->console);
        self::assertSame(['Flush failed'], array_map(fn(LogEntry $e) => $e->message, $this->written));
    }

    #[Test]
    public function a_failing_store_is_reported_once_and_never_thrown(): void
    {
        $logger          = $this->enabled();
        $this->sinkFails = true;
        $logger->info('a', 'one');
        $logger->info('a', 'two');

        $failures = array_filter($this->console, fn(array $l) => str_contains($l[1], 'Could not store'));
        self::assertCount(1, $failures);

        $this->sinkFails = false;
        $logger->info('a', 'three');
        self::assertSame(['three'], array_map(fn(LogEntry $e) => $e->message, $this->written));
    }

    #[Test]
    public function the_channel_logs_under_its_category(): void
    {
        $logger = $this->enabled();
        ($logger->channel('ingest'))('error', 'Dropped entry X', ['entry_id' => 'X']);

        self::assertSame('ingest', $this->written[0]->category);
        self::assertSame('error', $this->written[0]->level);
        self::assertSame('X', $this->written[0]->context['entry_id']);
    }

    // ── PHP errors ────────────────────────────────────────────────────────────

    #[Test]
    public function stores_a_php_deprecation_with_its_location(): void
    {
        $logger  = $this->enabled();
        $handled = $logger->handlePhpError(E_DEPRECATED, 'Implicitly nullable parameter', '/app/src/Foo.php', 12);

        self::assertTrue($handled);
        $entry = $this->written[0];
        self::assertSame('notice', $entry->level);
        self::assertSame('php.deprecated', $entry->category);
        self::assertSame('Implicitly nullable parameter in src/Foo.php:12', $entry->message);
        self::assertSame('E_DEPRECATED', $entry->context['php_error']);
        self::assertSame('src/Foo.php', $entry->context['file']);
        self::assertSame(12, $entry->context['line']);
    }

    #[Test]
    public function maps_warnings_and_recoverable_errors(): void
    {
        $logger = $this->enabled();
        $logger->handlePhpError(E_WARNING, 'Undefined array key', '/app/a.php', 1);
        $logger->handlePhpError(E_USER_DEPRECATED, 'old API', '/app/a.php', 2);
        $logger->handlePhpError(E_RECOVERABLE_ERROR, 'bad', '/app/a.php', 3);

        self::assertSame(
            [['warning', 'php.warning'], ['notice', 'php.deprecated'], ['error', 'php.error']],
            array_map(fn(LogEntry $e) => [$e->level, $e->category], $this->written),
        );
    }

    #[Test]
    public function repeated_php_errors_are_stored_once_per_window_with_a_count(): void
    {
        $logger = $this->enabled();
        $logger->handlePhpError(E_DEPRECATED, 'same', '/app/a.php', 1);
        $logger->handlePhpError(E_DEPRECATED, 'same', '/app/a.php', 1);
        $logger->handlePhpError(E_DEPRECATED, 'same', '/app/a.php', 1);

        self::assertCount(1, $this->written);
        self::assertCount(1, $this->console);

        $this->now += 301;
        $logger->handlePhpError(E_DEPRECATED, 'same', '/app/a.php', 1);

        self::assertCount(2, $this->written);
        self::assertSame(2, $this->written[1]->context['repeated']);
    }

    #[Test]
    public function respects_the_silence_operator_and_error_reporting(): void
    {
        $logger   = $this->enabled();
        $previous = error_reporting(E_ALL & ~E_DEPRECATED);
        try {
            self::assertFalse($logger->handlePhpError(E_DEPRECATED, 'hidden', '/app/a.php', 1));
        } finally {
            error_reporting($previous);
        }

        self::assertSame([], $this->written);
    }

    #[Test]
    public function leaves_user_errors_to_php(): void
    {
        self::assertFalse($this->enabled()->handlePhpError(E_USER_ERROR, 'fatal', '/app/a.php', 1));
        self::assertSame([], $this->written);
    }

    #[Test]
    public function an_uncaught_exception_is_stored_right_away_as_critical(): void
    {
        $logger = $this->enabled();
        $logger->handleException(new \RuntimeException('MariaDB is gone'));

        self::assertSame([], $this->written);
        self::assertCount(1, $this->writtenNow);
        $entry = $this->writtenNow[0];
        self::assertSame('critical', $entry->level);
        self::assertSame('php.exception', $entry->category);
        self::assertStringStartsWith('Uncaught RuntimeException: MariaDB is gone in ', $entry->message);
        self::assertSame(\RuntimeException::class, $entry->context['exception']);
        self::assertArrayHasKey('trace', $entry->context);
    }

    #[Test]
    public function a_write_now_log_bypasses_the_buffer(): void
    {
        $logger = $this->enabled();
        $logger->log('warning', 'shutdown', 'Second signal — exiting now.', now: true);

        self::assertSame([], $this->written);
        self::assertCount(1, $this->writtenNow);
    }
}
