<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\DatabaseConditionBuilder;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseConditionBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_no_conditions_for_an_empty_policy(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'empty']),
        );

        self::assertSame([], $conditions);
        self::assertSame([], $params);
    }

    #[Test]
    public function it_builds_a_date_cutoff_condition(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'older_than_days' => 7]),
        );

        self::assertSame(['timestamp < :cutoff'], $conditions);
        self::assertArrayHasKey(':cutoff', $params);
    }

    #[Test]
    public function it_builds_an_app_key_condition(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'app_key' => 'my-app']),
        );

        self::assertSame(['app_key = :app_key'], $conditions);
        self::assertSame('my-app', $params[':app_key']);
    }

    #[Test]
    public function it_builds_an_app_id_condition(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'app_id' => 'prod']),
        );

        self::assertSame(['app_id = :app_id'], $conditions);
        self::assertSame('prod', $params[':app_id']);
    }

    #[Test]
    public function it_builds_a_level_condition(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'level' => 'debug']),
        );

        self::assertSame(['level = :level'], $conditions);
        self::assertSame('debug', $params[':level']);
    }

    #[Test]
    public function it_builds_a_category_condition(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'category' => 'payments']),
        );

        self::assertSame(['category = :category'], $conditions);
        self::assertSame('payments', $params[':category']);
    }

    #[Test]
    public function it_builds_a_message_regex_condition(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'message_regex' => '.*@hotmail\\.com.*']),
        );

        self::assertSame(['message REGEXP :message_regex'], $conditions);
        self::assertSame('.*@hotmail\\.com.*', $params[':message_regex']);
    }

    #[Test]
    public function it_builds_a_message_glob_condition_translated_to_like(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'message_glob' => '*@hotmail.com*']),
        );

        self::assertSame(['message LIKE :message_glob'], $conditions);
        self::assertSame('%@hotmail.com%', $params[':message_glob']);
    }

    #[Test]
    public function it_escapes_existing_sql_wildcards_before_translating_the_glob(): void
    {
        [, $params] = DatabaseConditionBuilder::build(
            RetentionPolicy::fromArray(['name' => 'x', 'message_glob' => '100%_done?']),
        );

        self::assertSame('100\\%\\_done_', $params[':message_glob']);
    }

    #[Test]
    public function it_combines_all_conditions_with_and_semantics(): void
    {
        [$conditions, $params] = DatabaseConditionBuilder::build(RetentionPolicy::fromArray([
            'name'            => 'x',
            'older_than_days' => 7,
            'app_key'         => 'my-app',
            'app_id'          => 'prod',
            'level'           => 'debug',
            'category'        => 'payments',
            'message_regex'   => 'foo',
            'message_glob'    => 'bar*',
        ]));

        self::assertCount(7, $conditions);
        self::assertCount(7, $params);
    }
}
