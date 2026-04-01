<?php

declare(strict_types=1);

namespace LogService\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Strategy interface for authenticating write requests (POST /api/logs).
 *
 * Two implementations:
 *   SingleKeyWriteAuth   – file storage mode; checks a single API_SECRET bearer token
 *   DatabaseWriteAuth    – MariaDB mode; looks up X-Api-Key / X-Api-Token in the clients table
 */
interface WriteAuthInterface
{
    /**
     * Validate the write credentials on an incoming request.
     *
     * Returns an AuthResult that carries the resolved app_key on success
     * so the router can use it as the default for log entries.
     */
    public function authenticate(ServerRequestInterface $request): AuthResult;
}
