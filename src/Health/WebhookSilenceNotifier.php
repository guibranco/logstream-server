<?php

declare(strict_types=1);

namespace LogService\Health;

use React\Http\Browser;
use React\Promise\PromiseInterface;

/**
 * POSTs silence alerts as JSON to a webhook URL (CLIENT_SILENCE_WEBHOOK_URL).
 * Non-blocking: the request runs on the event loop; ClientSilenceMonitor logs
 * a failure and retries on its next check.
 */
final class WebhookSilenceNotifier implements SilenceNotifierInterface
{
    public function __construct(
        private readonly string  $url,
        private readonly Browser $browser = new Browser(),
        private readonly float   $timeoutSeconds = 10.0,
    ) {
        if (!self::isValidUrl($url)) {
            throw new \InvalidArgumentException('Webhook URL must be an absolute http(s) URL.');
        }
    }

    public static function isValidUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true);
    }

    /** Fulfils on a 2XX/3XX response; rejects on a network error, timeout or 4XX/5XX. */
    public function notify(array $payload): PromiseInterface
    {
        return $this->browser
            ->withTimeout($this->timeoutSeconds)
            ->post(
                $this->url,
                ['Content-Type' => 'application/json', 'User-Agent' => 'logstream-server'],
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
    }
}
