<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Auth;

use LogService\Auth\SingleKeyWriteAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;

final class SingleKeyWriteAuthTest extends TestCase
{
    private function makeRequest(array $headers = [], array $query = []): ServerRequestInterface
    {
        $uri = 'http://localhost/api/logs';
        if (!empty($query)) {
            $uri .= '?' . http_build_query($query);
        }

        $request = new ServerRequest('POST', $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_accepts_a_valid_bearer_token(): void
    {
        $auth   = new SingleKeyWriteAuth('my-secret');
        $result = $auth->authenticate(
            $this->makeRequest(['Authorization' => 'Bearer my-secret'])
        );

        self::assertTrue($result->ok);
        self::assertSame('__single_key__', $result->appKey);
        self::assertNull($result->reason);
    }

    #[Test]
    public function it_rejects_a_wrong_bearer_token(): void
    {
        $auth   = new SingleKeyWriteAuth('my-secret');
        $result = $auth->authenticate(
            $this->makeRequest(['Authorization' => 'Bearer wrong'])
        );

        self::assertFalse($result->ok);
        self::assertNull($result->appKey);
        self::assertNotEmpty($result->reason);
    }

    #[Test]
    public function it_accepts_a_valid_token_query_parameter(): void
    {
        $auth   = new SingleKeyWriteAuth('my-secret');
        $result = $auth->authenticate(
            $this->makeRequest([], ['token' => 'my-secret'])
        );

        self::assertTrue($result->ok);
    }

    #[Test]
    public function it_rejects_a_wrong_token_query_parameter(): void
    {
        $auth   = new SingleKeyWriteAuth('my-secret');
        $result = $auth->authenticate(
            $this->makeRequest([], ['token' => 'bad'])
        );

        self::assertFalse($result->ok);
    }

    #[Test]
    public function it_rejects_a_request_with_no_credentials(): void
    {
        $auth   = new SingleKeyWriteAuth('my-secret');
        $result = $auth->authenticate($this->makeRequest());

        self::assertFalse($result->ok);
        self::assertStringContainsString('Missing', $result->reason);
    }

    #[Test]
    public function it_allows_all_requests_when_secret_is_empty(): void
    {
        $auth   = new SingleKeyWriteAuth('');
        $result = $auth->authenticate($this->makeRequest());

        self::assertTrue($result->ok);
    }

    #[Test]
    public function it_prefers_bearer_header_over_query_param(): void
    {
        // Correct header + wrong query param → should succeed
        $auth   = new SingleKeyWriteAuth('correct');
        $result = $auth->authenticate(
            $this->makeRequest(
                ['Authorization' => 'Bearer correct'],
                ['token' => 'wrong']
            )
        );

        self::assertTrue($result->ok);
    }

    #[Test]
    public function it_rejects_a_bearer_prefix_with_empty_token(): void
    {
        $auth   = new SingleKeyWriteAuth('my-secret');
        $result = $auth->authenticate(
            $this->makeRequest(['Authorization' => 'Bearer '])
        );

        self::assertFalse($result->ok);
    }
}
