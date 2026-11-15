<?php

declare(strict_types=1);

namespace LogService\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * HTTP middleware that writes every failed request (4XX and 5XX) to the
 * server's own log, under the category "http":
 *
 *   4XX → warning   "POST /api/logs → 401 Unauthorized: invalid token"
 *   5XX → error     "GET /api/logs → 503 Service Unavailable: ..."
 *
 * The context carries what is needed to find the caller and the cause —
 * method, path, status, duration_ms, client_ip, user_agent, the X-Api-Key
 * (never the token or the Authorization header) and the response's "error",
 * "reason" and first "errors" — but not the query string or the body.
 *
 * An exception thrown (or a promise rejected) by the handler is logged as an
 * error with its class and location and answered with a JSON 500, instead of
 * React's default HTML page.
 */
final class FailedRequestLogger
{
    /** Response bodies larger than this are not inspected for an error message. */
    private const MAX_BODY_BYTES = 16_384;

    /** How many of a batch's "errors" are copied into the context. */
    private const MAX_ERRORS = 5;

    private const MAX_FIELD_LENGTH = 500;

    private readonly \Closure $log;
    private readonly \Closure $clock;

    /**
     * @param callable(string $level, string $message, array<string, mixed> $context): void $log
     *        e.g. ServerLogger::channel('http')
     * @param (callable(): float)|null $clock Monotonic seconds; injectable for tests.
     */
    public function __construct(
        callable $log,
        private readonly ClientIpResolver $ipResolver = new ClientIpResolver(),
        ?callable $clock = null,
    ) {
        $this->log   = \Closure::fromCallable($log);
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * @param callable(ServerRequestInterface): (ResponseInterface|PromiseInterface<ResponseInterface>) $next
     * @return ResponseInterface|PromiseInterface<ResponseInterface>
     */
    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface|PromiseInterface
    {
        $started = ($this->clock)();

        try {
            $result = $next($request);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, $started);
        }

        if ($result instanceof ResponseInterface) {
            $this->inspect($request, $result, $started);
            return $result;
        }

        return resolve($result)->then(
            function (ResponseInterface $response) use ($request, $started): ResponseInterface {
                $this->inspect($request, $response, $started);
                return $response;
            },
            fn(\Throwable $e): ResponseInterface => $this->failed($request, $e, $started),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function inspect(ServerRequestInterface $request, ResponseInterface $response, float $started): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        $context = $this->requestContext($request, $status, $started) + $this->errorDetails($response);
        $detail  = $context['reason'] ?? $context['error'] ?? null;

        $this->write($status >= 500 ? 'error' : 'warning', $request, $status, $response->getReasonPhrase(), $detail, $context);
    }

    private function failed(ServerRequestInterface $request, \Throwable $e, float $started): ResponseInterface
    {
        $context = $this->requestContext($request, 500, $started) + [
            'exception' => $e::class,
            'error'     => self::clip($e->getMessage()),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ];

        $this->write('error', $request, 500, 'Internal Server Error', 'Uncaught ' . $e::class . ': ' . $e->getMessage(), $context);

        return new Response(
            500,
            ['Content-Type' => 'application/json', 'Access-Control-Allow-Origin' => '*'],
            json_encode(['error' => 'Internal server error'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    /** @param array<string, mixed> $context */
    private function write(
        string $level,
        ServerRequestInterface $request,
        int $status,
        string $phrase,
        ?string $detail,
        array $context,
    ): void {
        $message = sprintf('%s %s → %d%s', $request->getMethod(), $context['path'], $status, $phrase !== '' ? " {$phrase}" : '');
        if ($detail !== null && $detail !== '' && strcasecmp($detail, $phrase) !== 0) {
            $message .= ': ' . self::clip($detail);
        }

        try {
            ($this->log)($level, $message, $context);
        } catch (\Throwable) {
            // Logging must never turn a response into a failure.
        }
    }

    /** @return array<string, mixed> */
    private function requestContext(ServerRequestInterface $request, int $status, float $started): array
    {
        $context = [
            'method'      => $request->getMethod(),
            'path'        => self::clip($request->getUri()->getPath() ?: '/'),
            'status'      => $status,
            'duration_ms' => round((($this->clock)() - $started) * 1000, 2),
            'client_ip'   => $this->ipResolver->resolve($request),
        ];

        $userAgent = $request->getHeaderLine('User-Agent');
        if ($userAgent !== '') {
            $context['user_agent'] = self::clip($userAgent);
        }

        // The app key identifies the caller; its token and the Authorization header are secrets.
        $appKey = $request->getHeaderLine('X-Api-Key');
        if ($appKey !== '') {
            $context['app_key'] = self::clip($appKey);
        }

        return $context;
    }

    /**
     * "error", "reason" and the first few "errors" of a JSON error body.
     *
     * @return array<string, mixed>
     */
    private function errorDetails(ResponseInterface $response): array
    {
        $data = self::readJsonBody($response);

        $details = [];
        foreach (['error', 'reason'] as $field) {
            if (is_string($data[$field] ?? null) && $data[$field] !== '') {
                $details[$field] = self::clip($data[$field]);
            }
        }

        $errors = is_array($data['errors'] ?? null) ? $data['errors'] : [];
        $strings = array_slice(array_values(array_filter($errors, 'is_string')), 0, self::MAX_ERRORS);
        if ($strings !== []) {
            $details['errors'] = array_map(self::clip(...), $strings);
            if (count($errors) > self::MAX_ERRORS) {
                $details['errors_total'] = count($errors);
            }
            // A batch rejected entry by entry has no "error": say what went wrong.
            $details['error'] ??= $details['errors'][0];
        }

        return $details;
    }

    /**
     * The decoded body of a small, seekable JSON response; [] for anything else.
     * The body is rewound afterwards so it is still sent in full.
     *
     * @return array<mixed>
     */
    private static function readJsonBody(ResponseInterface $response): array
    {
        $body = $response->getBody();
        $size = $body->getSize();
        $readable = str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')
            && $size !== null && $size > 0 && $size <= self::MAX_BODY_BYTES
            && $body->isSeekable();

        $data = null;
        if ($readable) {
            try {
                $body->rewind();
                $data = json_decode($body->getContents(), true);
                $body->rewind();
            } catch (\Throwable) {
                $data = null;
            }
        }

        return is_array($data) ? $data : [];
    }

    private static function clip(string $value): string
    {
        return mb_strlen($value) > self::MAX_FIELD_LENGTH
            ? mb_substr($value, 0, self::MAX_FIELD_LENGTH) . '…'
            : $value;
    }
}
