<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage\ClickHouse;

use LogService\Storage\ClickHouse\ClickHouseClient;
use LogService\Storage\ClickHouse\ClickHouseException;
use LogService\Storage\ClickHouse\ClickHouseSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClickHouseClientTest extends TestCase
{
    #[Test]
    public function select_sends_the_query_as_body_and_values_as_parameters(): void
    {
        $fake = (new FakeClickHouse())->respondRows([['id' => 'a', 'n' => 1], ['id' => 'b', 'n' => 2]]);

        $rows = $fake->client()->select('SELECT id, n FROM t WHERE app_key = {p0:String}', ['p0' => "x' OR 1=1 --"]);

        self::assertSame([['id' => 'a', 'n' => 1], ['id' => 'b', 'n' => 2]], $rows);

        $request = $fake->requests[0];
        self::assertSame("SELECT id, n FROM t WHERE app_key = {p0:String}\nFORMAT JSONEachRow", $request['body']);
        self::assertSame("x' OR 1=1 --", $request['query']['param_p0'], 'values travel as parameters, never in the SQL');
        self::assertSame('logstream', $request['query']['database']);
        self::assertSame('iso', $request['query']['date_time_output_format']);
        self::assertStringStartsWith('http://127.0.0.1:8123/?', $request['url']);
    }

    #[Test]
    public function credentials_go_in_headers_not_in_the_url(): void
    {
        $fake = new FakeClickHouse();
        $fake->client()->request('SELECT 1');

        self::assertContains('X-ClickHouse-User: logstream', $fake->requests[0]['headers']);
        self::assertContains('X-ClickHouse-Key: s3cret', $fake->requests[0]['headers']);
        self::assertStringNotContainsString('s3cret', $fake->requests[0]['url']);
    }

    #[Test]
    public function insert_sends_one_json_line_per_row_with_async_insert(): void
    {
        $fake = new FakeClickHouse();
        $fake->client()->insert('log_entries', ['id', 'message'], [
            ['id' => 'a', 'message' => 'São Paulo / 50%'],
            ['id' => 'b', 'message' => "line\nbreak"],
        ]);

        $request = $fake->requests[0];
        self::assertSame('INSERT INTO log_entries (id, message) FORMAT JSONEachRow', $request['query']['query']);
        self::assertSame('1', $request['query']['async_insert']);
        self::assertSame('1', $request['query']['wait_for_async_insert']);
        self::assertSame(
            "{\"id\":\"a\",\"message\":\"São Paulo / 50%\"}\n{\"id\":\"b\",\"message\":\"line\\nbreak\"}\n",
            $request['body'],
        );
    }

    #[Test]
    public function inserting_nothing_sends_nothing(): void
    {
        $fake = new FakeClickHouse();
        $fake->client()->insert('log_entries', ['id'], []);

        self::assertSame([], $fake->requests);
    }

    #[Test]
    public function value_returns_the_first_column_of_the_first_row(): void
    {
        $fake = (new FakeClickHouse())->respondRows([['count()' => 42]]);

        self::assertSame(42, $fake->client()->value('SELECT count() FROM t'));
        self::assertNull((new FakeClickHouse())->client()->value('SELECT 1 WHERE 0'));
    }

    #[Test]
    public function an_error_status_throws_with_the_first_line_of_the_message(): void
    {
        $fake = (new FakeClickHouse())->respond(
            400,
            "Code: 62. DB::Exception: Syntax error: failed at position 8. (SYNTAX_ERROR) (version 26.8.30.8 (official build))\nStack trace: …",
        );

        try {
            $fake->client()->request('SELEKT 1');
            self::fail('expected a ClickHouseException');
        } catch (ClickHouseException $e) {
            // Exact: the version suffix and the stack trace are dropped.
            self::assertSame('ClickHouse error (HTTP 400): Code: 62. DB::Exception: Syntax error: failed at position 8. (SYNTAX_ERROR)', $e->getMessage());
        }
    }

    #[Test]
    public function an_unreadable_response_throws(): void
    {
        $fake = (new FakeClickHouse())->respond(200, "{not json\n");

        $this->expectException(ClickHouseException::class);
        $this->expectExceptionMessage('Unreadable response');

        $fake->client()->select('SELECT 1');
    }

    #[Test]
    public function parameter_names_are_checked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new FakeClickHouse())->client()->request('SELECT 1', ['a&b' => 'x']);
    }

    #[Test]
    public function the_real_transport_reports_an_unreachable_server(): void
    {
        // No fake transport: PHP's HTTP stream wrapper against a port that refuses connections.
        $client = new ClickHouseClient(new ClickHouseSettings(url: 'http://127.0.0.1:1', timeout: 1));

        self::assertFalse($client->ping());

        $this->expectException(ClickHouseException::class);
        $this->expectExceptionMessage('Cannot reach ClickHouse at http://127.0.0.1:1');

        $client->request('SELECT 1');
    }
}
