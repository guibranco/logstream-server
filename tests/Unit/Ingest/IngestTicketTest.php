<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Ingest;

use LogService\Ingest\IngestTicket;
use LogService\Models\LogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;

final class IngestTicketTest extends TestCase
{
    #[Test]
    public function it_settles_once_every_entry_is_persisted_or_dropped(): void
    {
        $ticket = new IngestTicket(3);
        $result = null;
        $ticket->wait(5, new StreamSelectLoop())->then(function ($r) use (&$result) { $result = $r; });

        $ticket->persisted($this->entry('A'));
        $ticket->dropped($this->entry('B'));
        self::assertNull($result);
        self::assertFalse($ticket->isSettled());

        $ticket->persisted($this->entry('C'));

        self::assertTrue($ticket->isSettled());
        self::assertSame(['B'], $result);
    }

    #[Test]
    public function waiting_on_a_settled_ticket_resolves_immediately(): void
    {
        $ticket = new IngestTicket(1);
        $ticket->persisted($this->entry('A'));

        $result = null;
        $ticket->wait(5)->then(function ($r) use (&$result) { $result = $r; });

        self::assertSame([], $result);
    }

    #[Test]
    public function wait_resolves_null_on_timeout(): void
    {
        $loop   = new StreamSelectLoop();
        $result = 'pending';

        (new IngestTicket(1))->wait(0.01, $loop)->then(function ($r) use (&$result) { $result = $r; });
        $loop->addTimer(0.2, fn() => $loop->stop());
        $loop->run();

        self::assertNull($result);
    }

    #[Test]
    public function extra_settlements_are_ignored(): void
    {
        $ticket = new IngestTicket(1);
        $ticket->persisted($this->entry('A'));
        $ticket->dropped($this->entry('A'));

        $result = null;
        $ticket->wait(5)->then(function ($r) use (&$result) { $result = $r; });

        self::assertSame([], $result);
    }

    private function entry(string $id): LogEntry
    {
        return LogEntry::fromArray([
            'id' => $id, 'trace_id' => 't', 'batch_id' => null, 'app_key' => 'a',
            'app_id' => 'b', 'user_agent' => null, 'level' => 'info', 'message' => 'm',
        ]);
    }
}
