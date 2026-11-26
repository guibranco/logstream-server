<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Retention;

use LogService\Retention\ClickHouseRetentionConditions;
use LogService\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClickHouseRetentionConditionsTest extends TestCase
{
    private static function fixed(array $data): RetentionPolicy
    {
        return RetentionPolicy::fromArray(['name' => 'p'] + $data)->frozen();
    }

    #[Test]
    public function an_empty_policy_has_no_conditions(): void
    {
        $builder = new ClickHouseRetentionConditions();

        self::assertSame([], $builder->build(RetentionPolicy::fromArray(['name' => 'empty'])));
        self::assertSame([], $builder->params());
    }

    #[Test]
    public function the_cutoff_is_read_as_utc(): void
    {
        $policy  = self::fixed(['older_than_days' => 7]);
        $builder = new ClickHouseRetentionConditions();

        self::assertSame(["timestamp < toDateTime64({r0:String}, 6, 'UTC')"], $builder->build($policy));
        self::assertSame(['r0' => $policy->getCutoffDate()->format('Y-m-d H:i:s')], $builder->params());
    }

    #[Test]
    public function field_filters_are_equalities_and_level_compares_the_enum_name(): void
    {
        $builder = new ClickHouseRetentionConditions();
        $conditions = $builder->build(self::fixed([
            'app_key' => 'nginx', 'app_id' => 'vinhedo5', 'level' => 'debug', 'category' => 'access', 'transport' => 'udp',
        ]));

        self::assertSame([
            'app_key = {r0:String}',
            'app_id = {r1:String}',
            'category = {r2:String}',
            'transport = {r3:String}',
            'toString(level) = {r4:String}',
        ], $conditions);
        self::assertSame(['r0' => 'nginx', 'r1' => 'vinhedo5', 'r2' => 'access', 'r3' => 'udp', 'r4' => 'debug'], $builder->params());
    }

    #[Test]
    public function message_criteria_also_match_a_non_empty_template(): void
    {
        $builder    = new ClickHouseRetentionConditions();
        $conditions = $builder->build(self::fixed(['message_regex' => '^Health check', 'message_glob' => '*50%_off*']));

        self::assertSame([
            "(match(message, {r0:String}) OR (message_template IS NOT NULL AND message_template != '' AND match(message_template, {r0:String})))",
            "(message ILIKE {r1:String} OR (message_template IS NOT NULL AND message_template != '' AND message_template ILIKE {r1:String}))",
        ], $conditions);
        self::assertSame(['r0' => '^Health check', 'r1' => '%50\\%\\_off%'], $builder->params());
    }

    #[Test]
    public function the_cutoff_can_be_left_out(): void
    {
        $builder = new ClickHouseRetentionConditions();

        self::assertSame(['app_key = {r0:String}'], $builder->build(self::fixed(['app_key' => 'x', 'older_than_days' => 3]), includeCutoff: false));
    }

    #[Test]
    public function one_builder_numbers_parameters_across_policies(): void
    {
        $builder = new ClickHouseRetentionConditions();
        $builder->build(self::fixed(['app_key' => 'a']));
        $second = $builder->build(self::fixed(['app_key' => 'b']));

        self::assertSame(['app_key = {r1:String}'], $second);
        self::assertSame(['r0' => 'a', 'r1' => 'b'], $builder->params());
    }
}
