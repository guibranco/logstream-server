<?php

declare(strict_types=1);

namespace LogService\Auth;

/**
 * Immutable result of an authentication attempt.
 *
 * On success it carries the resolved app_key so the router can use it
 * as the default value for log entries whose body omits app_key.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool    $ok,
        public readonly ?string $appKey,
        public readonly ?string $reason,
    ) {}

    public static function success(string $appKey): self
    {
        return new self(true, $appKey, null);
    }

    public static function failure(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
