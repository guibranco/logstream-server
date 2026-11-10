<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Models;

use LogService\Models\MessageTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageTemplateTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────────────────
    // render
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_replaces_placeholders_with_context_values(): void
    {
        self::assertSame(
            'GitHub push for guibranco/webhooks queued to github',
            MessageTemplate::render('GitHub {event} for {repository} queued to {queue}', [
                'event'      => 'push',
                'repository' => 'guibranco/webhooks',
                'queue'      => 'github',
            ]),
        );
    }

    #[Test]
    public function it_returns_null_when_nothing_is_replaced(): void
    {
        self::assertNull(MessageTemplate::render('GitHub webhook received', ['event' => 'push']));
        self::assertNull(MessageTemplate::render('GitHub {event}', null));
        self::assertNull(MessageTemplate::render('GitHub {event}', []));
        self::assertNull(MessageTemplate::render('GitHub {event}', ['other' => 1]));
    }

    #[Test]
    public function it_leaves_unknown_placeholders_and_other_braces_untouched(): void
    {
        self::assertSame(
            'push {missing} {"json": true} { spaced }',
            MessageTemplate::render('{event} {missing} {"json": true} { spaced }', ['event' => 'push']),
        );
    }

    #[Test]
    public function it_resolves_dotted_names_through_nested_context(): void
    {
        $context = ['repository' => ['full_name' => 'guibranco/webhooks', 'owner' => ['login' => 'guibranco']]];

        self::assertSame(
            'guibranco/webhooks by guibranco',
            MessageTemplate::render('{repository.full_name} by {repository.owner.login}', $context),
        );
    }

    #[Test]
    public function it_prefers_a_literal_dotted_key_over_a_nested_path(): void
    {
        $context = ['a.b' => 'literal', 'a' => ['b' => 'nested']];

        self::assertSame('literal', MessageTemplate::render('{a.b}', $context));
    }

    #[Test]
    public function it_renders_a_present_but_null_value(): void
    {
        self::assertSame('push (-)', MessageTemplate::render('{event} ({action})', ['event' => 'push', 'action' => null]));
    }

    #[Test]
    #[DataProvider('formattedValues')]
    public function it_formats_values(mixed $value, string $expected): void
    {
        self::assertSame($expected, MessageTemplate::format($value));
    }

    public static function formattedValues(): array
    {
        return [
            'null'    => [null, '-'],
            'true'    => [true, 'true'],
            'false'   => [false, 'false'],
            'string'  => ['ação', 'ação'],
            'int'     => [42, '42'],
            'float'   => [1.5, '1.5'],
            'list'    => [['a', 'b'], '["a","b"]'],
            'object'  => [['path' => 'a/b', 'ok' => true], '{"path":"a/b","ok":true}'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // resolve
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function resolve_renders_the_message_and_keeps_it_as_the_template(): void
    {
        self::assertSame(
            ['Worker started for 3 queues', 'Worker started for {count} queues'],
            MessageTemplate::resolve('Worker started for {count} queues', null, ['count' => 3]),
        );
    }

    #[Test]
    public function resolve_leaves_a_message_without_resolvable_placeholders_alone(): void
    {
        self::assertSame(['Worker started', null], MessageTemplate::resolve('Worker started', null, ['count' => 3]));
        self::assertSame(['Bad {json}', null], MessageTemplate::resolve('Bad {json}', null, null));
    }

    #[Test]
    public function resolve_keeps_a_client_rendered_message_and_template(): void
    {
        self::assertSame(
            ['Published 3 messages', 'Published {Count} messages'],
            MessageTemplate::resolve('Published 3 messages', 'Published {Count} messages', ['Count' => 99]),
        );
    }

    #[Test]
    public function resolve_renders_a_template_sent_without_a_message(): void
    {
        self::assertSame(
            ['Published 3 messages', 'Published {Count} messages'],
            MessageTemplate::resolve(null, 'Published {Count} messages', ['Count' => 3]),
        );
        self::assertSame(
            ['Published {Count} messages', 'Published {Count} messages'],
            MessageTemplate::resolve('', 'Published {Count} messages', null),
        );
    }

    #[Test]
    public function resolve_passes_non_string_messages_through(): void
    {
        self::assertSame([null, null], MessageTemplate::resolve(null, null, ['a' => 1]));
        self::assertSame([42, null], MessageTemplate::resolve(42, null, ['a' => 1]));
        self::assertSame(['x {a}', null], MessageTemplate::resolve('x {a}', '', 'not-an-array'));
    }
}
