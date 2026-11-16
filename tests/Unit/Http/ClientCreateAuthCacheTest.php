<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Http;

use GuzzleHttp\Psr7\Utils;
use LogService\Auth\DatabaseWriteAuth;
use LogService\Database\Connection;
use LogService\Http\Router;
use LogService\Repository\ClientRepository;
use LogService\Udp\UdpPacketValidator;
use LogService\WebSocket\LogHub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Http\Message\ServerRequest;

/**
 * Regression: a key looked up before it was registered (an early UDP packet
 * or HTTP write) is cached as unknown. Creating that client must clear the
 * miss so both transports accept the new credentials straight away.
 *
 * Uses the real ClientRepository and DatabaseWriteAuth on in-memory SQLite.
 */
final class ClientCreateAuthCacheTest extends TestCase
{
    private const APP_KEY = 'billing-api';

    private DatabaseWriteAuth $auth;
    private Router $router;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("
            CREATE TABLE clients (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT    NOT NULL,
                app_key      TEXT    NOT NULL UNIQUE,
                api_token    TEXT    NOT NULL,
                active       INTEGER NOT NULL DEFAULT 1,
                last_seen_at TEXT    NULL,
                heartbeat_timeout_min INTEGER NULL,
                created_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $db         = Connection::wrap($pdo);
        $this->auth = new DatabaseWriteAuth($db);

        $this->router = new Router(
            storage:          $this->createStub(\LogService\Storage\StorageInterface::class),
            hub:              new LogHub(''),
            writeAuth:        $this->auth,
            uiSecret:         'test-ui-secret',
            storageType:      'mariadb',
            clientRepository: new ClientRepository($db),
            writeAuthDb:      $this->auth,
        );
    }

    #[Test]
    public function a_cached_miss_is_cleared_when_the_client_is_created(): void
    {
        // 1. Early traffic for the unregistered key caches a miss.
        self::assertNull($this->auth->tokenFor(self::APP_KEY));
        self::assertSame(401, $this->ingest('not-yet-issued')->getStatusCode());

        // 2. The administrator registers the client.
        $created = $this->router->handle(
            (new ServerRequest('POST', 'http://localhost/api/clients'))
                ->withHeader('Authorization', 'Bearer test-ui-secret')
                ->withBody(Utils::streamFor(json_encode(['name' => 'Billing API', 'app_key' => self::APP_KEY])))
        );
        self::assertSame(201, $created->getStatusCode());
        $token = json_decode((string) $created->getBody(), true)['api_token'];
        self::assertIsString($token);

        // 3. HTTP accepts the new credentials without a restart.
        $status = $this->ingest($token)->getStatusCode();
        self::assertGreaterThanOrEqual(200, $status);
        self::assertLessThan(300, $status);

        // 4. UDP accepts a packet signed with the new token.
        $validator = new UdpPacketValidator([$this->auth, 'tokenFor']);
        $packet    = [
            'v'     => 1,
            'key'   => self::APP_KEY,
            'ts'    => (int) (microtime(true) * 1000),
            'nonce' => bin2hex(random_bytes(8)),
            'log'   => ['app_id' => 'prod', 'level' => 'info', 'message' => 'hello'],
        ];
        $packet['sig'] = hash_hmac(
            'sha256',
            UdpPacketValidator::canonical($packet['key'], $packet['ts'], $packet['nonce'], $packet['log']),
            $token,
        );

        $result = $validator->validate(json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        self::assertTrue($result->ok, 'UDP packet signed with the new token is accepted');
    }

    private function ingest(string $token): \Psr\Http\Message\ResponseInterface
    {
        return $this->router->handle(
            (new ServerRequest('POST', 'http://localhost/api/logs'))
                ->withHeader('X-Api-Key', self::APP_KEY)
                ->withHeader('X-Api-Token', $token)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(Utils::streamFor(json_encode([
                    'app_id'  => 'prod',
                    'level'   => 'info',
                    'message' => 'hello',
                ])))
        );
    }
}
