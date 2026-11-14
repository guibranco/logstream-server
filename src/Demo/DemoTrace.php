<?php

declare(strict_types=1);

namespace LogService\Demo;

/**
 * Collects the entries of one demo scenario under a shared trace_id. Each step
 * is a few milliseconds after the previous one, so the trace view shows them
 * in order; the absolute time is fixed only when the entries are read.
 */
final class DemoTrace
{
    public readonly string $id;

    /** @var list<array<string, mixed>> */
    private array $entries = [];
    /** @var list<float> Seconds since the first step. */
    private array $offsets = [];
    private float $clock = 0.0;

    public function __construct()
    {
        $this->id = self::uuid4();
    }

    public function log(string $service, string $level, string $category, string $template, array $context = [], int $afterMs = 0): self
    {
        if ($this->entries !== []) {
            $this->clock += ($afterMs ?: mt_rand(2, 40)) / 1000;
        }
        $meta = DemoTraffic::SERVICES[$service];

        $this->offsets[] = $this->clock;
        $this->entries[] = [
            'app_key'          => $service,
            'app_id'           => $meta['instances'][array_rand($meta['instances'])],
            'user_agent'       => "{$service}/{$meta['version']}",
            'trace_id'         => $this->id,
            'level'            => $level,
            'category'         => $category,
            'message_template' => $template,
            'context'          => $context + ['service' => $service],
        ];
        return $this;
    }

    /**
     * The entries with RFC 3339 timestamps, anchored on the first step
     * ($startAt) or the last one ($endAt). Defaults to ending now.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(?float $startAt = null, ?float $endAt = null): array
    {
        $base = $startAt ?? (($endAt ?? microtime(true)) - $this->clock);
        $utc  = new \DateTimeZone('UTC');
        $out  = [];
        foreach ($this->entries as $i => $entry) {
            $ts    = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $base + $this->offsets[$i]))->setTimezone($utc);
            $out[] = $entry + ['timestamp' => $ts->format(\DateTimeInterface::RFC3339_EXTENDED)];
        }
        return $out;
    }

    private static function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
