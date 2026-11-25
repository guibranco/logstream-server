<?php

declare(strict_types=1);

namespace LogService\Storage\ClickHouse;

/**
 * Blocking client for ClickHouse's HTTP interface.
 *
 * Uses PHP's HTTP stream wrapper, so no extension is needed. Query values are
 * always sent as server-side parameters ({name:Type} in the SQL, param_name in
 * the URL): user input never becomes part of the SQL text.
 *
 * Every request asks for UTC ISO-8601 dates (…T10:00:00.123456Z), so reading
 * them back doesn't depend on PHP's default time zone.
 */
final class ClickHouseClient
{
    /** @var \Closure(string, list<string>, string): array{0: int, 1: string} */
    private readonly \Closure $transport;

    /**
     * @param (\Closure(string $url, list<string> $headers, string $body): array{0: int, 1: string})|null $transport
     *        Sends one POST and returns [status, body]; replaced in unit tests.
     */
    public function __construct(
        public readonly ClickHouseSettings $settings,
        ?\Closure $transport = null,
    ) {
        $this->transport = $transport ?? $this->streamTransport(...);
    }

    /**
     * Run a SELECT and decode its rows.
     *
     * @param array<string, string|int|float> $params Values for {name:Type} placeholders.
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $body = $this->request($sql . "\nFORMAT JSONEachRow", $params);

        $rows = [];
        foreach (explode("\n", $body) as $line) {
            if ($line === '') {
                continue;
            }
            try {
                $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new ClickHouseException('Unreadable response from ClickHouse: ' . $e->getMessage(), 0, $e);
            }
        }

        return $rows;
    }

    /**
     * A single value: the first column of the first row.
     *
     * @param array<string, string|int|float> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->select($sql, $params)[0] ?? null;

        return $row === null ? null : reset($row);
    }

    /**
     * Insert rows as JSONEachRow. Uses ClickHouse's asynchronous inserts, so
     * many small writes (sync ingest) are batched into few parts on the server,
     * and waits until the rows are written.
     *
     * @param list<string>               $columns
     * @param list<array<string, mixed>> $rows
     */
    public function insert(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        [$url, $headers, $body] = $this->insertRequest($table, $columns, $rows);
        [$status, $response]    = ($this->transport)($url, $headers, $body);

        if ($status !== 200) {
            throw new ClickHouseException(self::describeError($status, $response));
        }
    }

    /**
     * The HTTP request for an insert, so the blocking path above and the
     * non-blocking ClickHouseBatchWriter send exactly the same thing.
     *
     * @param list<string>               $columns
     * @param list<array<string, mixed>> $rows
     * @return array{0: string, 1: list<string>, 2: string} URL, headers, body
     */
    public function insertRequest(string $table, array $columns, array $rows): array
    {
        $lines = '';
        foreach ($rows as $row) {
            $lines .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        }

        [$url, $headers] = $this->prepare([], [
            'query'                 => "INSERT INTO {$table} (" . implode(', ', $columns) . ') FORMAT JSONEachRow',
            'async_insert'          => '1',
            'wait_for_async_insert' => '1',
        ]);

        return [$url, $headers, $lines];
    }

    /** True if the server answers /ping within the timeout. */
    public function ping(): bool
    {
        try {
            $context = stream_context_create(['http' => [
                'method'        => 'GET',
                'timeout'       => min($this->settings->timeout, 2.0),
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents(rtrim($this->settings->url, '/') . '/ping', false, $context);

            return is_string($body) && trim($body) === 'Ok.';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * POST $body; with no "query" setting, the body is the query.
     *
     * @param array<string, string|int|float> $params   Query parameters (param_<name>).
     * @param array<string, string>           $settings Extra URL settings.
     */
    public function request(string $body, array $params = [], array $settings = []): string
    {
        [$url, $headers]     = $this->prepare($params, $settings);
        [$status, $response] = ($this->transport)($url, $headers, $body);

        if ($status !== 200) {
            throw new ClickHouseException(self::describeError($status, $response));
        }

        return $response;
    }

    /**
     * First line of ClickHouse's error ("Code: 62. DB::Exception: …"), without
     * the version and the stack trace.
     */
    public static function describeError(int $status, string $body): string
    {
        $first = trim(strtok($body, "\n") ?: '');
        $first = preg_replace('/\s*\(version .*\)\s*$/', '', $first) ?? $first;   // "(version 26.8.30.8 (official build))"

        return "ClickHouse error (HTTP {$status})" . ($first !== '' ? ": {$first}" : '');
    }

    /**
     * URL (database, settings, param_<name> values) and headers (credentials).
     *
     * @param array<string, string|int|float> $params
     * @param array<string, string>           $settings
     * @return array{0: string, 1: list<string>}
     */
    private function prepare(array $params, array $settings): array
    {
        $query = ['database' => $this->settings->database]
            + $settings
            + [
                'date_time_output_format' => 'iso',
                'output_format_json_quote_64bit_integers' => '0',
            ];
        foreach ($params as $name => $value) {
            if (!preg_match('/^[A-Za-z_]\w*$/', $name)) {
                throw new \InvalidArgumentException("Invalid ClickHouse parameter name '{$name}'");
            }
            $query['param_' . $name] = (string) $value;
        }

        return [
            rtrim($this->settings->url, '/') . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            [
                'X-ClickHouse-User: ' . $this->settings->user,
                'X-ClickHouse-Key: ' . $this->settings->password,
                'Content-Type: text/plain; charset=utf-8',
            ],
        ];
    }

    /**
     * @param list<string> $headers
     * @return array{0: int, 1: string}
     */
    private function streamTransport(string $url, array $headers, string $body): array
    {
        $context = stream_context_create(['http' => [
            'method'          => 'POST',
            'header'          => $headers,
            'content'         => $body,
            'timeout'         => $this->settings->timeout,
            'ignore_errors'   => true,   // read the error body on 4xx/5xx
            'follow_location' => 0,
            // HTTP/1.0 (the wrapper's default): ClickHouse answers unchunked and closes the connection.
        ]]);

        // fopen + stream metadata instead of $http_response_header (deprecated in PHP 8.5).
        $started = hrtime(true);
        $stream  = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            if ((hrtime(true) - $started) / 1e9 >= $this->settings->timeout) {
                throw new ClickHouseException("ClickHouse at {$this->settings->url} did not answer within {$this->settings->timeout}s");
            }
            $error = error_get_last()['message'] ?? 'no response';
            throw new ClickHouseException(
                "Cannot reach ClickHouse at {$this->settings->url}: " . preg_replace('/^fopen\([^)]*\): /', '', $error),
            );
        }

        try {
            $meta     = stream_get_meta_data($stream);
            $response = stream_get_contents($stream);
            if ($response === false || stream_get_meta_data($stream)['timed_out']) {
                throw new ClickHouseException("ClickHouse at {$this->settings->url} did not answer within {$this->settings->timeout}s");
            }
        } finally {
            fclose($stream);
        }

        $status = 0;
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];   // the last status line wins
            }
        }

        return [$status, $response];
    }
}
