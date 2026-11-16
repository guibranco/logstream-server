<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Ingest\EntryFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** EntryFactory is also covered through POST /api/logs (LogEndpointsTest, BufferedIngestEndpointsTest). */
final class EntryFactoryTest extends TestCase
{
    #[Test]
    public function builds_entries_with_defaults_and_ids(): void
    {
        [$entries, $errors] = EntryFactory::build(
            [['message' => 'one'], ['message' => 'two', 'app_id' => 'web-2', 'level' => 'error', 'batch_id' => 'b-2']],
            false, null, 'billing-api', 'web-1', 'agent/1.0', 'b-top', '10.0.0.7',
        );

        self::assertSame([], $errors);
        self::assertCount(2, $entries);
        self::assertSame('billing-api', $entries[0]->appKey);
        self::assertSame('web-1', $entries[0]->appId);
        self::assertSame('info', $entries[0]->level);
        self::assertSame('general', $entries[0]->category);
        self::assertSame('b-top', $entries[0]->batchId);
        self::assertSame('agent/1.0', $entries[0]->userAgent);
        self::assertSame('10.0.0.7', $entries[0]->senderIp);
        self::assertSame('web-2', $entries[1]->appId);
        self::assertSame('error', $entries[1]->level);
        self::assertSame('b-2', $entries[1]->batchId);
        self::assertNotSame($entries[0]->id, $entries[1]->id);
        self::assertNotSame($entries[0]->traceId, $entries[1]->traceId);
    }

    #[Test]
    public function transport_comes_from_the_caller_not_the_payload(): void
    {
        [$udp]  = EntryFactory::build([['message' => 'm', 'transport' => 'tcp']], false, null, 'k', 'w', null, null, null, 'udp');
        [$none] = EntryFactory::build([['message' => 'm', 'transport' => 'udp']], false, null, 'k', 'w', null, null, null);

        self::assertSame('udp', $udp[0]->transport);
        self::assertNull($none[0]->transport);
    }

    #[Test]
    public function resolved_app_key_wins_unless_single_key(): void
    {
        [$db] = EntryFactory::build([['message' => 'm', 'app_key' => 'spoofed']], false, null, 'billing-api', 'w', null, null, null);
        [$single] = EntryFactory::build([['message' => 'm', 'app_key' => 'own-key']], true, 'default-key', null, 'w', null, null, null);
        [$fallback] = EntryFactory::build([['message' => 'm']], true, 'default-key', null, 'w', null, null, null);

        self::assertSame('billing-api', $db[0]->appKey);
        self::assertSame('own-key', $single[0]->appKey);
        self::assertSame('default-key', $fallback[0]->appKey);
    }

    #[Test]
    public function reserved_key_and_invalid_items_become_errors(): void
    {
        [$entries, $errors] = EntryFactory::build(
            [['message' => 'ok'], ['message' => 'x', 'app_key' => 'logstream-server'], ['message' => 'y', 'timestamp' => ['not', 'a', 'date']]],
            true, 'app', null, 'w', null, null, null,
        );

        self::assertSame([0], array_keys($entries));
        self::assertCount(2, $errors);
        self::assertStringStartsWith("Entry #1: app_key 'logstream-server' is reserved", $errors[0]);
        self::assertStringStartsWith('Entry #2: ', $errors[1]);
    }
}
