<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseException;
use LogService\Storage\ClickHouseStorage;
use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Writes batches to ClickHouse over a non-blocking HTTP request, so a flush
 * never stalls the event loop (HTTP, WebSocket and UDP keep being served
 * while the INSERT runs).
 *
 * The request is built by {@see ClickHouseClient::insertRequest()}, the same
 * one the blocking path sends, with the rows of {@see ClickHouseStorage::row()}.
 * Retries are the IngestBuffer's job (exponential backoff); a batch that is
 * sent again after ClickHouse already stored it is collapsed by the table's
 * ReplacingMergeTree and by FINAL on reads.
 */
final class ClickHouseBatchWriter implements BatchWriterInterface
{
    private readonly Browser $browser;

    public function __construct(
        private readonly ClickHouseClient $client,
        ?Browser $browser = null,
    ) {
        $this->browser = ($browser ?? new Browser())
            ->withTimeout($client->settings->timeout)
            ->withFollowRedirects(false)
            // Read ClickHouse's error message instead of a generic "HTTP 500".
            ->withRejectErrorResponse(false);
    }

    public function write(array $entries): PromiseInterface
    {
        if ($entries === []) {
            return resolve(null);
        }

        [$url, $headerLines, $body] = $this->client->insertRequest(
            ClickHouseStorage::TABLE,
            ClickHouseStorage::COLUMNS,
            array_map(ClickHouseStorage::row(...), $entries),
        );

        return $this->browser->post($url, self::headers($headerLines), $body)->then(
            static function (ResponseInterface $response): null {
                if ($response->getStatusCode() !== 200) {
                    throw new ClickHouseException(
                        ClickHouseClient::describeError($response->getStatusCode(), (string) $response->getBody()),
                    );
                }

                return null;
            },
            function (\Throwable $e): never {
                throw $e instanceof ClickHouseException
                    ? $e
                    : new ClickHouseException("Cannot write to ClickHouse at {$this->client->settings->url}: {$e->getMessage()}", 0, $e);
            },
        );
    }

    public function name(): string
    {
        return 'clickhouse-async';
    }

    /**
     * @param list<string> $lines "Name: value"
     * @return array<string, string>
     */
    private static function headers(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value]   = explode(':', $line, 2);
            $headers[$name]   = ltrim($value);
        }

        return $headers;
    }
}
