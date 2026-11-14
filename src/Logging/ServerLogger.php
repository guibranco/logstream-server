<?php

declare(strict_types=1);

namespace LogService\Logging;

use LogService\Models\LogEntry;
use LogService\Support\Identifier;

/**
 * The server's own logger.
 *
 * Every message goes to the console as before ("[Storage]  File (...)").
 * When self-logging is enabled it is also stored as a log entry of the
 * reserved application "logstream-server", so startup messages, PHP
 * deprecations, warnings and errors, and ingest/retention problems can be
 * searched and watched in LogStream itself.
 *
 * Lifecycle (bin/server.php):
 *   1. new ServerLogger() + registerPhpHandlers() — first thing at boot, so
 *      deprecations raised while loading classes are caught.
 *   2. configure(SelfLogConfig) — once the environment is loaded. Disabled:
 *      nothing is stored. Enabled: messages below the level are dropped.
 *   3. attach(sink) — once storage (and the ingest buffer) exist. Messages
 *      logged before this are kept in memory and stored now.
 *
 * Every entry of one server process shares a trace_id, so a whole run (boot
 * to shutdown) can be pulled up by trace.
 */
final class ServerLogger
{
    public const CATEGORY_PHP_PREFIX = 'php.';

    /** Identical PHP errors (same type, message, file, line) are stored at most once per window. */
    private const DEDUPE_WINDOW_SECONDS = 300.0;

    /** Messages kept while waiting for attach(); older ones beyond this are dropped. */
    private const MAX_PENDING = 1000;

    private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    private const STATE_COLLECTING = 'collecting';
    private const STATE_ENABLED    = 'enabled';
    private const STATE_DISABLED   = 'disabled';

    private string $state    = self::STATE_COLLECTING;
    private int    $minRank  = 0;
    private string $appId    = 'default';
    private ?string $userAgent = null;
    private readonly string $traceId;
    private readonly int $pid;

    private ?SelfLogSinkInterface $sink = null;

    /** @var list<array{level: string, category: string, message: string, context: array<string, mixed>, at: \DateTimeImmutable}> */
    private array $pending = [];
    private int $pendingDropped = 0;

    /** Guards against a store that itself logs (e.g. a PHP warning raised while writing). */
    private bool $storing = false;
    private bool $storeFailing = false;

    /** @var array<string, array{until: float, suppressed: int}> */
    private array $seen = [];

    private readonly \Closure $console;
    private readonly \Closure $clock;

    /**
     * @param (callable(bool $isError, string $line): void)|null $console     Console writer; injectable for tests.
     * @param (callable(): float)|null                           $clock       Monotonic seconds; injectable for tests.
     * @param string|null                                        $projectRoot Stripped from file paths in PHP errors.
     */
    public function __construct(
        ?callable $console = null,
        ?callable $clock = null,
        private readonly ?string $projectRoot = null,
    ) {
        $this->console = $console !== null
            ? \Closure::fromCallable($console)
            : static function (bool $isError, string $line): void {
                fwrite($isError ? STDERR : STDOUT, $line . "\n");
            };
        $this->clock   = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => hrtime(true) / 1e9;
        $this->traceId = Identifier::uuid4();
        $this->pid     = getmypid() ?: 0;
    }

    // ── Setup ─────────────────────────────────────────────────────────────────

    /**
     * Route PHP errors, uncaught exceptions and fatal errors through this logger.
     *
     * Also reports every error type (E_ALL): distribution php.ini files often
     * hide deprecations, which are exactly what this is meant to surface. The
     * handler prints them itself, so nothing is shown twice; @ still silences.
     */
    public function registerPhpHandlers(): void
    {
        error_reporting(E_ALL);
        set_error_handler($this->handlePhpError(...));
        set_exception_handler(function (\Throwable $e): void {
            $this->handleException($e);
            exit(255); // what PHP exits with for an uncaught exception, so supervisors see the crash
        });
        register_shutdown_function($this->handleShutdown(...));
    }

    public function configure(SelfLogConfig $config, string $version = 'dev'): void
    {
        $this->appId     = $config->appId;
        $this->minRank   = self::rank($config->minLevel);
        $this->userAgent = sprintf('LogStream-Server/%s (PHP %s; %s)', $version, PHP_VERSION, PHP_OS_FAMILY);

        if (!$config->enabled) {
            $this->state   = self::STATE_DISABLED;
            $this->pending = [];
            return;
        }

        $this->state   = self::STATE_ENABLED;
        $this->pending = array_values(array_filter(
            $this->pending,
            fn(array $record) => self::rank($record['level']) >= $this->minRank,
        ));
    }

    /** Start storing; messages logged so far are stored now. */
    public function attach(SelfLogSinkInterface $sink): void
    {
        if ($this->state !== self::STATE_ENABLED) {
            return;
        }

        $this->sink = $sink;
        $pending    = $this->pending;
        $this->pending = [];

        if ($this->pendingDropped > 0) {
            $this->store([
                'level'    => 'warning',
                'category' => 'selflog',
                'message'  => "{$this->pendingDropped} early messages were not stored (more than " . self::MAX_PENDING . ' before storage was ready)',
                'context'  => [],
                'at'       => new \DateTimeImmutable(),
            ]);
            $this->pendingDropped = 0;
        }

        foreach ($pending as $record) {
            $this->store($record);
        }
    }

    public function isEnabled(): bool
    {
        return $this->state === self::STATE_ENABLED;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    // ── Logging ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    public function debug(string $category, string $message, array $context = []): void
    {
        $this->log('debug', $category, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $category, string $message, array $context = []): void
    {
        $this->log('info', $category, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string $category, string $message, array $context = []): void
    {
        $this->log('notice', $category, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $category, string $message, array $context = []): void
    {
        $this->log('warning', $category, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $category, string $message, array $context = []): void
    {
        $this->log('error', $category, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $category, string $message, array $context = []): void
    {
        $this->log('critical', $category, $message, $context);
    }

    /**
     * Print to the console and, when enabled, store.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $category, string $message, array $context = [], bool $now = false): void
    {
        $level = in_array($level, LogEntry::LEVELS, true) ? $level : 'info';
        $this->print($level, $category, $message);

        if ($this->state === self::STATE_DISABLED) {
            return;
        }

        $record = [
            'level'    => $level,
            'category' => $category,
            'message'  => $message,
            'context'  => $context,
            'at'       => new \DateTimeImmutable(),
        ];

        if ($this->state === self::STATE_COLLECTING || $this->sink === null) {
            if ($this->state === self::STATE_ENABLED && self::rank($level) < $this->minRank) {
                return;
            }
            if (count($this->pending) >= self::MAX_PENDING) {
                array_shift($this->pending);
                $this->pendingDropped++;
            }
            $this->pending[] = $record;
            return;
        }

        if (self::rank($level) < $this->minRank) {
            return;
        }

        $this->store($record, $now);
    }

    /**
     * A logging callable for components that take one (e.g. IngestBuffer):
     * fn(string $level, string $message, array $context = []).
     *
     * @return \Closure(string, string, array<string, mixed>=): void
     */
    public function channel(string $category): \Closure
    {
        return function (string $level, string $message, array $context = []) use ($category): void {
            $this->log($level, $category, $message, $context);
        };
    }

    // ── PHP handlers ──────────────────────────────────────────────────────────

    /** set_error_handler() callback: deprecations, notices, warnings and recoverable errors. */
    public function handlePhpError(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        // Respect error_reporting() and the @ operator.
        if (!(error_reporting() & $errno)) {
            return false;
        }
        // Let PHP terminate as usual; the shutdown handler logs it.
        if ($errno === E_USER_ERROR) {
            return false;
        }

        [$level, $kind, $label] = self::describePhpError($errno);
        $file = $this->relativePath($errfile);

        $context = [
            'php_error' => $label,
            'file'      => $file,
            'line'      => $errline,
        ];

        $signature = "{$errno}|{$errfile}|{$errline}|{$errstr}";
        $now       = ($this->clock)();
        if (isset($this->seen[$signature]) && $now < $this->seen[$signature]['until']) {
            $this->seen[$signature]['suppressed']++;
            return true;
        }
        if (($this->seen[$signature]['suppressed'] ?? 0) > 0) {
            $context['repeated'] = $this->seen[$signature]['suppressed'];
        }
        if (count($this->seen) >= 1000) {
            $this->seen = array_filter($this->seen, static fn(array $s) => $now < $s['until']);
        }
        $this->seen[$signature] = ['until' => $now + self::DEDUPE_WINDOW_SECONDS, 'suppressed' => 0];

        $this->log($level, self::CATEGORY_PHP_PREFIX . $kind, "{$errstr} in {$file}:{$errline}", $context);

        return true;
    }

    /** set_exception_handler() callback: an exception nothing caught. */
    public function handleException(\Throwable $e): void
    {
        $file = $this->relativePath($e->getFile());

        $this->log('critical', self::CATEGORY_PHP_PREFIX . 'exception', sprintf(
            'Uncaught %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $file,
            $e->getLine(),
        ), [
            'exception' => $e::class,
            'file'      => $file,
            'line'      => $e->getLine(),
            'trace'     => $e->getTraceAsString(),
        ], now: true);
        ($this->console)(true, $e->getTraceAsString());
    }

    /** register_shutdown_function() callback: fatal errors. */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !($error['type'] & self::FATAL_ERRORS)) {
            return;
        }

        $file = $this->relativePath($error['file']);

        $this->log('critical', self::CATEGORY_PHP_PREFIX . 'fatal', "{$error['message']} in {$file}:{$error['line']}", [
            'php_error' => self::describePhpError($error['type'])[2],
            'file'      => $file,
            'line'      => $error['line'],
        ], now: true);
    }

    // ──────────────────────────────────────────────────────────────────────────

    /** @param array{level: string, category: string, message: string, context: array<string, mixed>, at: \DateTimeImmutable} $record */
    private function store(array $record, bool $now = false): void
    {
        if ($this->storing || $this->sink === null) {
            return;
        }

        $this->storing = true;
        try {
            $entry = $this->toEntry($record);
            $now ? $this->sink->writeNow($entry) : $this->sink->write($entry);
            $this->storeFailing = false;
        } catch (\Throwable $e) {
            // Report once per outage, not once per message.
            if (!$this->storeFailing) {
                ($this->console)(true, $this->format('warning', 'selflog', 'Could not store the server\'s own log entry: ' . $e->getMessage()));
            }
            $this->storeFailing = true;
        } finally {
            $this->storing = false;
        }
    }

    /** @param array{level: string, category: string, message: string, context: array<string, mixed>, at: \DateTimeImmutable} $record */
    private function toEntry(array $record): LogEntry
    {
        $timestamp = $record['at']->format(\DateTimeInterface::RFC3339_EXTENDED);

        return LogEntry::fromArray([
            'id'         => Identifier::ulid(),
            'trace_id'   => $this->traceId,
            'batch_id'   => null,
            'app_key'    => SelfLogConfig::APP_KEY,
            'app_id'     => $this->appId,
            'user_agent' => $this->userAgent,
            'level'      => $record['level'],
            'category'   => $record['category'],
            'message'    => $record['message'],
            'context'    => $record['context'] + ['pid' => $this->pid],
            'timestamp'  => $timestamp,
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'sender_ip'  => null,
        ]);
    }

    private function print(string $level, string $category, string $message): void
    {
        ($this->console)(self::rank($level) >= self::rank('warning'), $this->format($level, $category, $message));
    }

    private function format(string $level, string $category, string $message): string
    {
        $tag    = str_pad('[' . ucfirst($category) . ']', 10) . ' ';
        $prefix = self::rank($level) >= self::rank('warning') ? strtoupper($level) . ': ' : '';

        return $tag . $prefix . $message;
    }

    private function relativePath(string $file): string
    {
        if ($this->projectRoot === null || $file === '') {
            return $file;
        }
        $root = rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private static function rank(string $level): int
    {
        $rank = array_search($level, LogEntry::LEVELS, true);

        return $rank === false ? 1 : $rank;
    }

    /** @return array{0: string, 1: string, 2: string} [level, category suffix, constant name] */
    private static function describePhpError(int $errno): array
    {
        return match ($errno) {
            E_DEPRECATED         => ['notice',   'deprecated', 'E_DEPRECATED'],
            E_USER_DEPRECATED    => ['notice',   'deprecated', 'E_USER_DEPRECATED'],
            E_NOTICE             => ['notice',   'notice',     'E_NOTICE'],
            E_USER_NOTICE        => ['notice',   'notice',     'E_USER_NOTICE'],
            E_WARNING            => ['warning',  'warning',    'E_WARNING'],
            E_USER_WARNING       => ['warning',  'warning',    'E_USER_WARNING'],
            E_CORE_WARNING       => ['warning',  'warning',    'E_CORE_WARNING'],
            E_COMPILE_WARNING    => ['warning',  'warning',    'E_COMPILE_WARNING'],
            E_RECOVERABLE_ERROR  => ['error',    'error',      'E_RECOVERABLE_ERROR'],
            E_ERROR              => ['critical', 'fatal',      'E_ERROR'],
            E_PARSE              => ['critical', 'fatal',      'E_PARSE'],
            E_CORE_ERROR         => ['critical', 'fatal',      'E_CORE_ERROR'],
            E_COMPILE_ERROR      => ['critical', 'fatal',      'E_COMPILE_ERROR'],
            E_USER_ERROR         => ['critical', 'fatal',      'E_USER_ERROR'],
            default              => ['warning',  'warning',    "E_{$errno}"],
        };
    }
}
