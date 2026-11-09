<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Storage;

use LogService\Storage\ContextFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextFilterTest extends TestCase
{
    #[Test]
    public function it_returns_no_filters_when_parameters_are_absent(): void
    {
        self::assertSame([], ContextFilter::fromQuery(null, null));
    }

    #[Test]
    public function it_splits_dotted_keys_into_path_segments(): void
    {
        [$filter] = ContextFilter::fromQuery(['order.customer.id' => 'c-1'], null);

        self::assertSame(['order', 'customer', 'id'], $filter->path);
        self::assertSame('c-1', $filter->value);
        self::assertFalse($filter->negate);
    }

    #[Test]
    public function it_marks_exclude_filters_as_negated(): void
    {
        [$filter] = ContextFilter::fromQuery(null, ['role' => 'bot']);

        self::assertTrue($filter->negate);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function malformedInput(): iterable
    {
        yield 'scalar group'        => ['oops', null];
        yield 'empty segment'       => [['order..id' => '1'], null];
        yield 'trailing dot'        => [['order.' => '1'], null];
        yield 'nested array value'  => [['order' => ['id' => '1']], null];
        yield 'path too long'       => [[str_repeat('a', 201) => '1'], null];
    }

    #[Test]
    #[DataProvider('malformedInput')]
    public function it_rejects_malformed_input(mixed $include, mixed $exclude): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContextFilter::fromQuery($include, $exclude);
    }

    #[Test]
    public function it_rejects_more_than_the_maximum_number_of_filters(): void
    {
        $include = [];
        for ($i = 0; $i <= ContextFilter::MAX_FILTERS; $i++) {
            $include["k{$i}"] = 'v';
        }

        $this->expectException(\InvalidArgumentException::class);

        ContextFilter::fromQuery($include, null);
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function matchCases(): iterable
    {
        yield 'string'          => [['user' => 'ana'], 'ana', true];
        yield 'string mismatch' => [['user' => 'ana'], 'bob', false];
        yield 'int'             => [['n' => 42], '42', true];
        yield 'float'           => [['n' => 1.5], '1.5', true];
        yield 'bool true'       => [['ok' => true], 'true', true];
        yield 'bool false'      => [['ok' => false], 'false', true];
        yield 'json null'       => [['x' => null], 'null', true];
        yield 'object value'    => [['x' => ['y' => 1]], '{"y":1}', false];
    }

    /** @param array<string, mixed> $context */
    #[Test]
    #[DataProvider('matchCases')]
    public function it_compares_scalars_as_strings(array $context, string $value, bool $expected): void
    {
        $key      = array_key_first($context);
        [$filter] = ContextFilter::fromQuery([$key => $value], null);

        self::assertSame($expected, $filter->matches($context));
    }

    #[Test]
    public function it_walks_nested_objects(): void
    {
        [$filter] = ContextFilter::fromQuery(['order.id' => '42'], null);

        self::assertTrue($filter->matches(['order' => ['id' => 42]]));
        self::assertFalse($filter->matches(['order' => ['id' => 7]]));
        self::assertFalse($filter->matches(['order' => 'flat']));
        self::assertFalse($filter->matches(null));
    }

    #[Test]
    public function exclude_matches_when_the_property_is_absent_or_different(): void
    {
        [$filter] = ContextFilter::fromQuery(null, ['role' => 'bot']);

        self::assertTrue($filter->matches(['role' => 'human']));
        self::assertTrue($filter->matches([]));
        self::assertTrue($filter->matches(null));
        self::assertFalse($filter->matches(['role' => 'bot']));
    }

    // ── context_search ─────────────────────────────────────────────────────────

    #[Test]
    public function search_term_is_optional_and_trimmed(): void
    {
        self::assertNull(ContextFilter::parseSearchTerm(null));
        self::assertNull(ContextFilter::parseSearchTerm(''));
        self::assertSame('card_declined', ContextFilter::parseSearchTerm('  card_declined '));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSearchTerms(): iterable
    {
        yield 'too short'        => ['42'];
        yield 'short after trim' => ['  ab  '];
        yield 'too long'         => [str_repeat('x', 201)];
        yield 'array'            => [['card']];
    }

    #[Test]
    #[DataProvider('invalidSearchTerms')]
    public function it_rejects_invalid_search_terms(mixed $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContextFilter::parseSearchTerm($raw);
    }

    #[Test]
    public function fulltext_phrase_wraps_the_term_and_drops_inner_quotes(): void
    {
        self::assertSame('"card_declined"', ContextFilter::fulltextPhrase('card_declined'));
        self::assertSame('"say hi"', ContextFilter::fulltextPhrase('"say hi"'));
        self::assertSame('"+a -b*"', ContextFilter::fulltextPhrase('+a -b*'));
    }

    /** @return iterable<string, array{?array<mixed>, string, bool}> */
    public static function containsCases(): iterable
    {
        $ctx = ['code' => 'card_declined', 'order' => ['id' => 1234, 'items' => [['sku' => 'ABC-9']]], 'ok' => true];

        yield 'top-level value'         => [$ctx, 'declined', true];
        yield 'case-insensitive'        => [$ctx, 'CARD', true];
        yield 'nested number'           => [$ctx, '1234', true];
        yield 'value inside list'       => [$ctx, 'abc-9', true];
        yield 'key name'                => [$ctx, 'order', true];
        yield 'nested key name'         => [$ctx, 'sku', true];
        yield 'boolean rendered'        => [$ctx, 'true', true];
        yield 'absent'                  => [$ctx, 'refund', false];
        yield 'null context'            => [null, 'card', false];
    }

    /** @param array<mixed>|null $context */
    #[Test]
    #[DataProvider('containsCases')]
    public function contains_term_searches_keys_and_values_at_any_depth(?array $context, string $term, bool $expected): void
    {
        self::assertSame($expected, ContextFilter::containsTerm($context, $term));
    }

    #[Test]
    public function it_builds_a_quoted_json_path(): void
    {
        [$filter] = ContextFilter::fromQuery(['order.id' => '1'], null);

        self::assertSame('$."order"."id"', $filter->jsonPath());
    }

    #[Test]
    public function it_escapes_quotes_and_backslashes_in_the_json_path(): void
    {
        [$filter] = ContextFilter::fromQuery(['we"ird\\key' => '1'], null);

        self::assertSame('$."we\\"ird\\\\key"', $filter->jsonPath());
    }
}
