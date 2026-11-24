<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage\ClickHouse;

use LogService\Storage\ClickHouse\ClickHouseQuery;
use LogService\Storage\ContextFilter;
use LogService\Storage\SearchFilters;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClickHouseQueryTest extends TestCase
{
    /** @return array{0: list<string>, 1: array<string, string>} */
    private static function where(array $filters): array
    {
        return ClickHouseQuery::where(SearchFilters::fromArray($filters));
    }

    #[Test]
    public function no_criteria_compile_to_no_conditions(): void
    {
        self::assertSame([[], []], ClickHouseQuery::where(SearchFilters::none()));
    }

    #[Test]
    public function one_value_is_equality_and_several_are_in(): void
    {
        [$where, $params] = self::where(['app_key' => 'billing-api', 'sender_ip' => ['10.0.0.1', '10.0.0.2']]);

        self::assertSame(['app_key = {p0:String}', 'sender_ip IN ({p1:String}, {p2:String})'], $where);
        self::assertSame(['p0' => 'billing-api', 'p1' => '10.0.0.1', 'p2' => '10.0.0.2'], $params);
    }

    #[Test]
    public function level_compares_the_enum_name_so_unknown_levels_match_nothing(): void
    {
        [$where] = self::where(['level' => ['error', 'fatal']]);

        self::assertSame(['toString(level) IN ({p0:String}, {p1:String})'], $where);
    }

    #[Test]
    public function ascii_substrings_use_lower_like_with_the_pattern_escaped(): void
    {
        [$where, $params] = self::where(['search' => 'Card 50%_off\\x', 'category' => 'Pay']);

        self::assertSame(['lower(category) LIKE {p0:String}', 'lower(message) LIKE {p1:String}'], $where);
        self::assertSame('%pay%', $params['p0']);
        self::assertSame('%card 50\\%\\_off\\\\x%', $params['p1']);
    }

    #[Test]
    public function non_ascii_substrings_use_the_utf8_aware_function(): void
    {
        [$where, $params] = self::where(['search' => 'SÃO']);

        self::assertSame(["positionCaseInsensitiveUTF8(ifNull(message, ''), {p0:String}) > 0"], $where);
        self::assertSame(['p0' => 'SÃO'], $params);
    }

    #[Test]
    public function context_filters_pass_every_path_segment_as_a_parameter(): void
    {
        [$where, $params] = self::where([
            'context' => ContextFilter::fromQuery(['order.id' => '42'], ["user.\"role'" => 'bot']),
        ]);

        $ctx = "ifNull(context, '')";
        self::assertSame([
            "(JSONHas({$ctx}, {p0:String}, {p1:String}) AND "
            . "if(JSONType({$ctx}, {p0:String}, {p1:String}) = 'String', JSONExtractString({$ctx}, {p0:String}, {p1:String}), JSONExtractRaw({$ctx}, {p0:String}, {p1:String})) = {p2:String})",
            "(NOT JSONHas({$ctx}, {p3:String}, {p4:String}) OR "
            . "if(JSONType({$ctx}, {p3:String}, {p4:String}) = 'String', JSONExtractString({$ctx}, {p3:String}, {p4:String}), JSONExtractRaw({$ctx}, {p3:String}, {p4:String})) != {p5:String})",
        ], $where);
        self::assertSame(['p0' => 'order', 'p1' => 'id', 'p2' => '42', 'p3' => 'user', 'p4' => "\"role'", 'p5' => 'bot'], $params);
    }

    #[Test]
    public function context_search_is_a_whole_word_regex(): void
    {
        [$where, $params] = self::where(['context_search' => 'expired today']);

        self::assertSame(["match(ifNull(context, ''), {p0:String})"], $where);
        self::assertSame('(?i)(?:^|[^\p{L}\p{N}_])expired[^\p{L}\p{N}_]+today(?:[^\p{L}\p{N}_]|$)', $params['p0']);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function wholeWords(): iterable
    {
        // [term, context JSON, matches] — checked with PCRE, which reads these patterns like RE2.
        yield 'a whole value'           => ['card_declined', '{"code":"card_declined"}', true];
        yield 'part of a word'          => ['card', '{"code":"card_declined"}', false];
        yield 'a word in a sentence'    => ['expired', '{"msg":"Card expired today"}', true];
        yield 'a phrase'                => ['expired today', '{"msg":"Card expired today"}', true];
        yield 'words out of order'      => ['today expired', '{"msg":"Card expired today"}', false];
        yield 'case-insensitive'        => ['CARD', '{"msg":"Card expired today"}', true];
        yield 'a number'                => ['1234', '{"invoice_id":1234}', true];
        yield 'a key'                   => ['invoice_id', '{"invoice_id":1234}', true];
        yield 'accented letters'        => ['são', '{"city":"São Paulo"}', true];
        yield 'regex characters stay text' => ['a.b', '{"x":"axb"}', false];
    }

    #[Test]
    #[DataProvider('wholeWords')]
    public function whole_word_patterns_match_like_mariadb_fulltext(string $term, string $json, bool $expected): void
    {
        $pattern = ClickHouseQuery::wholeWordPattern($term);
        self::assertNotNull($pattern);

        $pcre = '/' . str_replace(['(?i)', '/'], ['', '\/'], $pattern) . '/iu';
        self::assertSame($expected, (bool) preg_match($pcre, $json));
    }

    #[Test]
    public function a_term_without_words_matches_nothing(): void
    {
        self::assertNull(ClickHouseQuery::wholeWordPattern('--- ...'));
        self::assertSame([['0'], []], self::where(['context_search' => '--- ...']));
    }

    #[Test]
    public function dates_are_parsed_by_clickhouse_as_utc(): void
    {
        [$where, $params] = self::where(['date_from' => '2026-11-20T00:00:00Z', 'date_to' => '2026-11-21 23:59:59']);

        self::assertSame([
            "timestamp >= parseDateTime64BestEffortOrNull({p0:String}, 6, 'UTC')",
            "timestamp <= parseDateTime64BestEffortOrNull({p1:String}, 6, 'UTC')",
        ], $where);
        self::assertSame(['p0' => '2026-11-20T00:00:00Z', 'p1' => '2026-11-21 23:59:59'], $params);
    }
}
