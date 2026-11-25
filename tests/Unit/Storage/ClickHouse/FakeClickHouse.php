<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage\ClickHouse;

use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseSettings;

/** Records every request sent through a ClickHouseClient and answers from a queue. */
final class FakeClickHouse
{
    /** @var list<array{url: string, query: array<string, string>, headers: list<string>, body: string}> */
    public array $requests = [];

    /** @var list<array{0: int, 1: string}> */
    private array $responses = [];

    public function client(?ClickHouseSettings $settings = null): ClickHouseClient
    {
        return new ClickHouseClient(
            $settings ?? new ClickHouseSettings(user: 'logstream', password: 'test-only-password'),
            function (string $url, array $headers, string $body): array {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $this->requests[] = ['url' => $url, 'query' => $query, 'headers' => $headers, 'body' => $body];

                return array_shift($this->responses) ?? [200, ''];
            },
        );
    }

    public function respond(int $status, string $body): self
    {
        $this->responses[] = [$status, $body];

        return $this;
    }

    /** @param list<array<string, mixed>> $rows */
    public function respondRows(array $rows): self
    {
        return $this->respond(200, implode('', array_map(static fn(array $r) => json_encode($r) . "\n", $rows)));
    }
}
