<?php

declare(strict_types=1);

namespace LogService\Ingest;

use LogService\Logging\SelfLogConfig;
use LogService\Models\LogEntry;
use LogService\Models\MessageTemplate;
use LogService\Support\Identifier;

/**
 * Turns raw log items into LogEntry objects — the part of ingestion that does
 * not depend on the transport. Used by POST /api/logs, /api/demo/run and UDP.
 */
final class EntryFactory
{
    /**
     * Items that fail validation become messages in the returned errors,
     * keyed like the input ("Entry #i: …").
     *
     * @param array<int, mixed> $rawLogs
     * @param bool        $isSingleKey    Single-key auth: each item may carry its own app_key.
     * @param string|null $defaultAppKey  app_key for items without one (single-key auth only).
     * @param string|null $resolvedAppKey The authenticated app_key; wins over the items otherwise.
     * @param string|null $senderIp       Taken from the connection, never from the payload.
     * @return array{0: array<int, LogEntry>, 1: list<string>}
     */
    public static function build(
        array   $rawLogs,
        bool    $isSingleKey,
        ?string $defaultAppKey,
        ?string $resolvedAppKey,
        string  $topAppId,
        ?string $topUserAgent,
        ?string $topBatchId,
        ?string $senderIp,
    ): array {
        $entries = [];
        $errors  = [];

        foreach ($rawLogs as $i => $raw) {
            $entryAppKey = $isSingleKey
                ? ($raw['app_key'] ?? $defaultAppKey)
                : $resolvedAppKey;

            if (SelfLogConfig::isReservedAppKey(is_string($entryAppKey) ? $entryAppKey : null)) {
                $errors[] = "Entry #{$i}: app_key '" . SelfLogConfig::APP_KEY . "' is reserved for the server's own logs";
                continue;
            }

            try {
                [$message, $template] = MessageTemplate::resolve(
                    $raw['message'] ?? null,
                    $raw['message_template'] ?? null,
                    $raw['context'] ?? null,
                );

                $entries[$i] = LogEntry::fromArray([
                    'id'         => Identifier::ulid(),
                    'trace_id'   => $raw['trace_id']   ?? Identifier::uuid4(),
                    'batch_id'   => $raw['batch_id']   ?? $topBatchId,
                    'app_key'    => $entryAppKey,
                    'app_id'     => $raw['app_id']     ?? $topAppId,
                    'user_agent' => $raw['user_agent'] ?? $topUserAgent,
                    'level'      => $raw['level']      ?? 'info',
                    'category'   => $raw['category']   ?? 'general',
                    'message'    => $message           ?? '',
                    'message_template' => $template,
                    'context'    => $raw['context']    ?? null,
                    'timestamp'  => $raw['timestamp']  ?? (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
                    'sender_ip'  => $senderIp,
                ]);
            } catch (\Throwable $e) {
                $errors[] = "Entry #{$i}: " . $e->getMessage();
            }
        }

        return [$entries, $errors];
    }
}
