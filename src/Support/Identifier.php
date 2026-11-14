<?php

declare(strict_types=1);

namespace LogService\Support;

/**
 * Identifiers for log entries: time-sortable entry IDs and UUID v4 trace IDs.
 * Shared by the ingest endpoint and the server's own logger.
 */
final class Identifier
{
    private static int $lastMs  = 0;
    private static int $lastRnd = 0;

    /** Time-sortable 26-character ID (ULID-like). */
    public static function ulid(): string
    {
        $ms = (int) (microtime(true) * 1000);

        if ($ms === self::$lastMs) {
            self::$lastRnd++;
        } else {
            self::$lastMs  = $ms;
            self::$lastRnd = random_int(0, 0x7FFFFFFFFF);
        }

        $enc = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ts  = $ms;
        $out = '';

        for ($i = 9; $i >= 0; $i--) {
            $out = $enc[$ts % 32] . $out;
            $ts  = intdiv($ts, 32);
        }

        $rnd = self::$lastRnd;
        for ($i = 15; $i >= 0; $i--) {
            $out = $enc[$rnd % 32] . $out;
            $rnd = intdiv($rnd, 32);
        }

        return strrev($out);
    }

    public static function uuid4(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
