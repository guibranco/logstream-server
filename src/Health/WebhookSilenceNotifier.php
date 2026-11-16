<?php

declare(strict_types=1);

namespace LogService\Health;

use React\Http\Browser;

/**
 * POSTs silence alerts as JSON to a webhook URL (CLIENT_SILENCE_WEBHOOK_URL).
 * Non-blocking: the request runs on the event loop and a failure is only logged.
 */
final class WebhookSilenceNotifier implements SilenceNotifierInterface
{
    /** @var \Closure(string, string, array<string, mixed>): void */
    private readonly \Closure $log;

    /**
     * @param (callable(string, string, array<string, mixed>): void)|null $log
     */
    public function __construct(
        private readonly string  $url,
        private readonly Browser $browser = new Browser(),
        ?callable $log = null,
        private readonly float   $timeoutSeconds = 10.0,
    ) {
        if (!self::isValidUrl($url)) {
            throw new \InvalidArgumentException('Webhook URL must be an absolute http(s) URL.');
        }
        $this->log = $log !== null
            ? \Closure::fromCallable($log)
            : static function (string $level, string $message, array $context = []): void {};
    }

    public static function isValidUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true);
    }

    public function notify(array $payload): void
    {
        $appKey = (string) ($payload['app_key'] ?? '');

        $this->browser
            ->withTimeout($this->timeoutSeconds)
            ->post(
                $this->url,
                ['Content-Type' => 'application/json', 'User-Agent' => 'logstream-server'],
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            )
            ->then(
                null,
                function (\Throwable $e) use ($appKey): void {
                    ($this->log)('error', "Silence webhook for '{$appKey}' failed: " . $e->getMessage(), [
                        'app_key' => $appKey,
                    ]);
                },
            );
    }
}
