<?php

declare(strict_types=1);

namespace LogService\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Single-secret write authentication for file storage mode.
 *
 * Accepts:
 *   Authorization: Bearer <API_SECRET>
 *   ?token=<API_SECRET>   (fallback, useful for quick curl tests)
 *
 * The resolved app_key is taken from the X-App-Key header or the request
 * body — the router handles that; we just surface 'unknown' as a safe default.
 */
final class SingleKeyWriteAuth implements WriteAuthInterface
{
    public function __construct(private readonly string $apiSecret) {}

    public function authenticate(ServerRequestInterface $request): AuthResult
    {
        if (empty($this->apiSecret)) {
            // Auth disabled — dev mode
            return AuthResult::success('unknown');
        }

        $token = $this->extractToken($request);

        if ($token === null) {
            return AuthResult::failure('Missing Authorization header or token query parameter');
        }

        if (!hash_equals($this->apiSecret, $token)) {
            return AuthResult::failure('Invalid API secret');
        }

        // The actual app_key comes from the request body; return a placeholder.
        return AuthResult::success('__single_key__');
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if (str_starts_with($header, 'Bearer ')) {
            $t = substr($header, 7);
            return $t !== '' ? $t : null;
        }

        $t = $request->getQueryParams()['token'] ?? '';
        return $t !== '' ? $t : null;
    }
}
