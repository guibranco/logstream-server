<?php

declare(strict_types=1);

namespace LogService\Models;

/**
 * Seq-style message templates: "GitHub {event} for {repository}" plus a context
 * of {"event": "push", "repository": "guibranco/webhooks"} is stored as the
 * rendered message "GitHub push for guibranco/webhooks", with the template kept
 * alongside it so every entry logged from the same line can still be grouped.
 *
 * Placeholders are PSR-3 style: `{name}`, where name is a context key. A dotted
 * name (`{repository.full_name}`) that is not itself a key walks nested arrays.
 * A placeholder whose key is missing from the context is left as written, so
 * messages that happen to contain braces (JSON, code) are never altered.
 */
final class MessageTemplate
{
    /** Rendered in place of a null context value. */
    public const NULL_VALUE = '-';

    private const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_.]*)\}/';

    private function __construct()
    {
    }

    /**
     * Decides the message and template to store for an ingested entry.
     *
     * - A client-supplied template is stored as-is. If the client sent no
     *   message, the template is rendered to produce one.
     * - Otherwise, when the message has at least one placeholder that the
     *   context resolves, the message is rendered and the original text becomes
     *   the template.
     * - Anything else is stored unchanged, without a template.
     *
     * @return array{0: mixed, 1: ?string} [message, template]
     */
    public static function resolve(mixed $message, mixed $template, mixed $context): array
    {
        $context = is_array($context) ? $context : null;

        if (is_string($template) && $template !== '') {
            if ($message === null || $message === '') {
                $message = self::render($template, $context) ?? $template;
            }
            return [$message, $template];
        }

        if (!is_string($message)) {
            return [$message, null];
        }

        $rendered = self::render($message, $context);

        return $rendered === null ? [$message, null] : [$rendered, $message];
    }

    /**
     * Replaces every placeholder the context resolves.
     *
     * @return string|null The rendered text, or null when no placeholder was replaced.
     */
    public static function render(string $template, ?array $context): ?string
    {
        if ($context === null || $context === [] || !str_contains($template, '{')) {
            return null;
        }

        $replaced = 0;
        $rendered = preg_replace_callback(
            self::PLACEHOLDER,
            static function (array $match) use ($context, &$replaced): string {
                [$found, $value] = self::lookup($context, $match[1]);
                if (!$found) {
                    return $match[0];
                }
                $replaced++;
                return self::format($value);
            },
            $template,
        );

        return ($rendered === null || $replaced === 0) ? null : $rendered;
    }

    /** The text a context value is rendered as. */
    public static function format(mixed $value): string
    {
        return match (true) {
            $value === null                                => self::NULL_VALUE,
            is_bool($value)                                => $value ? 'true' : 'false',
            is_string($value)                              => $value,
            is_int($value), is_float($value)               => (string) $value,
            $value instanceof \Stringable                  => (string) $value,
            default                                        => (string) json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
        };
    }

    /**
     * @return array{0: bool, 1: mixed} [found, value]
     */
    private static function lookup(array $context, string $name): array
    {
        if (array_key_exists($name, $context)) {
            return [true, $context[$name]];
        }

        if (!str_contains($name, '.')) {
            return [false, null];
        }

        $value = $context;
        foreach (explode('.', $name) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }
}
