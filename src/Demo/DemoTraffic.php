<?php

declare(strict_types=1);

namespace LogService\Demo;

/**
 * Fake traffic for demo instances: "ShopFast", a made-up shop split into six
 * backend services (storefront-api, auth-service, checkout-api,
 * inventory-service, payment-worker, notification-worker).
 *
 * Each scenario is one request flowing through several services under a shared
 * trace_id, as message-template entries in the POST /api/logs format, so the
 * trace view, level filters, categories and templates all have data.
 *
 * Used by bin/demo-seed.php (start-up seeding) and by POST /api/demo/run (the
 * /demo page). Every name, e-mail and order here is made up.
 */
final class DemoTraffic
{
    /** Scenario name → short description, in the order the /demo page shows them. */
    public const SCENARIOS = [
        'random'            => 'A random mix of everything below',
        'login'             => 'User signs in',
        'login-failed'      => 'Failed sign-ins that may lock the account',
        'search'            => 'Product search',
        'slow-search'       => 'Product search slower than the 1 s threshold',
        'checkout'          => 'Checkout through inventory, payment and e-mail',
        'card-declined'     => 'Checkout with a declined card',
        'gateway-timeout'   => 'Payment gateway times out three times; order fails',
        'out-of-stock'      => 'Checkout aborted: item out of stock',
        'email-failure'     => 'Order confirmed but the e-mail fails',
        'background'        => 'Background job: health check, stock sync, settlement…',
        'db-pool-exhausted' => 'Critical: database connection pool exhausted',
    ];

    public const SERVICES = [
        'storefront-api'      => ['version' => '3.12.0', 'instances' => ['prod-eu-1', 'prod-eu-2']],
        'auth-service'        => ['version' => '1.8.4',  'instances' => ['prod-eu-1']],
        'checkout-api'        => ['version' => '2.4.1',  'instances' => ['prod-eu-1', 'prod-eu-2']],
        'inventory-service'   => ['version' => '5.0.2',  'instances' => ['prod-eu-1']],
        'payment-worker'      => ['version' => '1.3.7',  'instances' => ['worker-1', 'worker-2']],
        'notification-worker' => ['version' => '0.9.15', 'instances' => ['worker-1']],
    ];

    private const USERS    = ['ana.silva', 'liam.murphy', 'sofia.costa', 'noah.byrne', 'emma.santos', 'jack.walsh', 'mia.oliveira', 'sean.kelly'];
    private const PRODUCTS = ['SKU-1042' => 'Wireless Mouse', 'SKU-2210' => 'USB-C Hub', 'SKU-3001' => 'Mechanical Keyboard',
                              'SKU-3317' => '27" Monitor', 'SKU-4120' => 'Laptop Stand', 'SKU-5005' => 'Noise-Cancelling Headphones'];
    private const SEARCHES = ['keyboard', 'monitor 4k', 'usb hub', 'headphones', 'desk lamp', 'webcam'];
    private const CARDS    = ['visa', 'mastercard', 'amex'];
    private const DECLINES = ['insufficient_funds', 'card_expired', 'do_not_honor'];

    public static function isScenario(string $name): bool
    {
        return isset(self::SCENARIOS[$name]);
    }

    /**
     * Entries of one run of a scenario.
     *
     * @param float      $at    Unix time (with fractions).
     * @param bool       $endAt True: the trace's last step happens at $at (live
     *                          traffic, nothing stamped in the future). False:
     *                          its first step does (backfill).
     * @return list<array<string, mixed>>
     */
    public static function run(string $scenario, float $at, bool $endAt = true): array
    {
        if (!self::isScenario($scenario)) {
            throw new \InvalidArgumentException("Unknown demo scenario '{$scenario}'");
        }

        $trace = new DemoTrace();
        match ($scenario) {
            'random'            => self::random($trace),
            'login'             => self::login($trace, failed: self::chance(0.15)),
            'login-failed'      => self::login($trace, failed: true),
            'search'            => self::search($trace, slow: self::chance(0.1)),
            'slow-search'       => self::search($trace, slow: true),
            'checkout'          => self::checkout($trace, self::randomCheckoutOutcome()),
            'card-declined'     => self::checkout($trace, 'declined'),
            'gateway-timeout'   => self::checkout($trace, 'timeout'),
            'out-of-stock'      => self::checkout($trace, 'out-of-stock'),
            'email-failure'     => self::checkout($trace, 'email-failure'),
            'background'        => self::background($trace, mt_rand(1, 5)),
            'db-pool-exhausted' => self::background($trace, 0),
        };

        return $endAt ? $trace->entries(endAt: $at) : $trace->entries(startAt: $at);
    }

    /**
     * Random scenarios spread over the last $hours, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function backfill(float $hours, int $perHour, ?float $now = null): array
    {
        $now    = $now ?? microtime(true);
        $count  = max(0, (int) round($hours * $perHour));
        $starts = [];
        for ($i = 0; $i < $count; $i++) {
            $starts[] = $now - mt_rand(60, max(61, (int) ($hours * 3600)));
        }
        sort($starts);

        $entries = [];
        foreach ($starts as $start) {
            array_push($entries, ...self::run('random', $start, endAt: false));
        }
        return $entries;
    }

    // ── Scenarios ─────────────────────────────────────────────────────────────

    private static function random(DemoTrace $tr): void
    {
        $r = mt_rand(1, 100);
        match (true) {
            $r <= 35 => self::search($tr, slow: self::chance(0.1)),
            $r <= 60 => self::checkout($tr, self::randomCheckoutOutcome()),
            $r <= 80 => self::login($tr, failed: self::chance(0.15)),
            default  => self::background($tr, mt_rand(1, 5)),
        };
    }

    private static function login(DemoTrace $tr, bool $failed): void
    {
        $user = self::pick(self::USERS);
        $ip   = '203.0.113.' . mt_rand(2, 254);   // TEST-NET-3, documentation range
        $tr->log('storefront-api', 'debug', 'http', 'POST {path} received', ['path' => '/api/session', 'client_ip' => $ip]);

        if ($failed) {
            $attempts = mt_rand(2, 6);
            $tr->log('auth-service', 'warning', 'auth', 'Failed login for {user} ({attempts} attempts in 10 min)', ['user' => $user, 'attempts' => $attempts, 'client_ip' => $ip], 120);
            if ($attempts >= 5) {
                $tr->log('auth-service', 'notice', 'security', 'Account {user} locked for {minutes} minutes', ['user' => $user, 'minutes' => 15]);
            }
            $tr->log('storefront-api', 'info', 'http', '{method} {path} responded {status} in {elapsed_ms} ms', ['method' => 'POST', 'path' => '/api/session', 'status' => 401, 'elapsed_ms' => mt_rand(90, 180)]);
            return;
        }

        $tr->log('auth-service', 'info', 'auth', 'User {user} signed in', ['user' => $user, 'mfa' => self::chance(0.4)], 80)
           ->log('storefront-api', 'info', 'http', '{method} {path} responded {status} in {elapsed_ms} ms', ['method' => 'POST', 'path' => '/api/session', 'status' => 200, 'elapsed_ms' => mt_rand(60, 140)]);
    }

    private static function search(DemoTrace $tr, bool $slow): void
    {
        $query = self::pick(self::SEARCHES);
        $hits  = mt_rand(0, 48);
        $ms    = $slow ? mt_rand(1200, 3500) : mt_rand(25, 220);
        $tr->log('storefront-api', 'debug', 'search', 'Cache {result} for search "{query}"', ['result' => $slow || self::chance(0.4) ? 'miss' : 'hit', 'query' => $query])
           ->log('storefront-api', $hits === 0 ? 'notice' : 'info', 'search', 'Search "{query}" returned {hits} products', ['query' => $query, 'hits' => $hits], $ms);
        if ($slow) {
            $tr->log('storefront-api', 'warning', 'performance', 'Slow query: search took {elapsed_ms} ms (threshold {threshold_ms} ms)', ['elapsed_ms' => $ms, 'threshold_ms' => 1000]);
        }
    }

    private static function randomCheckoutOutcome(): string
    {
        $r = mt_rand(1, 100);
        return match (true) {
            $r <= 8  => 'out-of-stock',
            $r <= 13 => 'timeout',
            $r <= 18 => 'timeout-recovered',
            $r <= 28 => 'declined',
            $r <= 33 => 'email-failure',
            default  => 'ok',
        };
    }

    /** @param string $outcome ok | out-of-stock | declined | timeout | timeout-recovered | email-failure */
    private static function checkout(DemoTrace $tr, string $outcome): void
    {
        $user    = self::pick(self::USERS);
        $sku     = array_rand(self::PRODUCTS);
        $qty     = mt_rand(1, 3);
        $amount  = round(mt_rand(1500, 45000) / 100, 2);
        $orderId = 'ORD-' . mt_rand(100000, 999999);

        $tr->log('checkout-api', 'info', 'checkout', 'Checkout started for {user}: {items} item(s), {currency} {amount}', ['user' => $user, 'items' => $qty, 'currency' => 'EUR', 'amount' => $amount, 'order_id' => $orderId])
           ->log('inventory-service', 'debug', 'inventory', 'Reserving {quantity} × {sku}', ['sku' => $sku, 'product' => self::PRODUCTS[$sku], 'quantity' => $qty], 15);

        if ($outcome === 'out-of-stock') {
            $tr->log('inventory-service', 'notice', 'inventory', '{sku} out of stock (requested {quantity}, available {available})', ['sku' => $sku, 'quantity' => $qty, 'available' => 0])
               ->log('checkout-api', 'warning', 'checkout', 'Order {order_id} aborted: item unavailable', ['order_id' => $orderId]);
            return;
        }

        $card = self::pick(self::CARDS);
        $tr->log('inventory-service', 'info', 'inventory', 'Reserved {quantity} × {sku} for order {order_id}', ['sku' => $sku, 'quantity' => $qty, 'order_id' => $orderId])
           ->log('payment-worker', 'debug', 'payment', 'Charging {currency} {amount} on {card} for order {order_id}', ['currency' => 'EUR', 'amount' => $amount, 'card' => $card, 'order_id' => $orderId], 30);

        if ($outcome === 'timeout' || $outcome === 'timeout-recovered') {
            $retries = $outcome === 'timeout' ? 3 : mt_rand(1, 2);
            for ($i = 1; $i <= $retries; $i++) {
                $tr->log('payment-worker', 'warning', 'payment', 'Payment gateway timeout after {timeout_ms} ms, retry {attempt}/{max_attempts}', ['timeout_ms' => 5000, 'attempt' => $i, 'max_attempts' => 3, 'order_id' => $orderId], 5000);
            }
            if ($outcome === 'timeout') {
                $tr->log('payment-worker', 'error', 'payment', 'Payment for order {order_id} failed: gateway unreachable', [
                    'order_id'  => $orderId,
                    'exception' => ['type' => 'GatewayTimeoutException', 'message' => 'Upstream did not respond within 5000 ms', 'gateway' => 'payments.example.net'],
                ])->log('inventory-service', 'info', 'inventory', 'Released reservation for order {order_id}', ['order_id' => $orderId])
                  ->log('checkout-api', 'error', 'checkout', 'Order {order_id} failed at payment step', ['order_id' => $orderId, 'user' => $user]);
                return;
            }
        }

        if ($outcome === 'declined') {
            $tr->log('payment-worker', 'warning', 'payment', 'Card declined for order {order_id}: {reason}', ['order_id' => $orderId, 'reason' => self::pick(self::DECLINES), 'card' => $card], 400)
               ->log('inventory-service', 'info', 'inventory', 'Released reservation for order {order_id}', ['order_id' => $orderId])
               ->log('checkout-api', 'notice', 'checkout', 'Order {order_id} awaiting new payment method', ['order_id' => $orderId, 'user' => $user]);
            return;
        }

        $tr->log('payment-worker', 'info', 'payment', 'Payment captured for order {order_id} ({currency} {amount})', ['order_id' => $orderId, 'currency' => 'EUR', 'amount' => $amount, 'psp_reference' => strtoupper(bin2hex(random_bytes(6)))], mt_rand(300, 900))
           ->log('checkout-api', 'info', 'checkout', 'Order {order_id} confirmed', ['order_id' => $orderId, 'user' => $user, 'amount' => $amount])
           ->log('notification-worker', 'debug', 'email', 'Rendering template {template}', ['template' => 'order-confirmation', 'order_id' => $orderId], 50);

        if ($outcome === 'email-failure') {
            $tr->log('notification-worker', 'error', 'email', 'SMTP send failed for order {order_id}: {smtp_error}', ['order_id' => $orderId, 'smtp_error' => '451 4.7.1 Try again later', 'will_retry' => true]);
            return;
        }
        $tr->log('notification-worker', 'info', 'email', 'Confirmation e-mail sent to {recipient}', ['recipient' => "{$user}@example.com", 'order_id' => $orderId], mt_rand(100, 400));
    }

    /** @param int $kind 0 = DB pool exhausted, 1–5 = routine jobs */
    private static function background(DemoTrace $tr, int $kind): void
    {
        switch ($kind) {
            case 0:
                $tr->log('checkout-api', 'warning', 'database', 'DB pool usage {used}/{size}', ['used' => 100, 'size' => 100])
                   ->log('checkout-api', 'critical', 'database', 'Database connection pool exhausted; rejecting requests for {seconds} s', ['seconds' => mt_rand(3, 15), 'pool' => 'orders-primary'], 200);
                break;
            case 1:
                $tr->log(array_rand(self::SERVICES), 'debug', 'health', 'Health check OK: {checks} checks, uptime {uptime_h} h', ['checks' => mt_rand(3, 6), 'uptime_h' => mt_rand(1, 240)]);
                break;
            case 2:
                $tr->log('inventory-service', 'info', 'jobs', 'Stock sync finished: {updated} SKUs updated in {elapsed_ms} ms', ['updated' => mt_rand(20, 400), 'elapsed_ms' => mt_rand(800, 4000)]);
                if (self::chance(0.4)) {
                    $tr->log('inventory-service', 'notice', 'inventory', 'Low stock for {sku}: {available} left', ['sku' => array_rand(self::PRODUCTS), 'available' => mt_rand(1, 5)]);
                }
                break;
            case 3:
                $used = mt_rand(70, 99);
                $tr->log('checkout-api', $used >= 95 ? 'warning' : 'debug', 'database', 'DB pool usage {used}/{size}', ['used' => $used, 'size' => 100]);
                break;
            case 4:
                $tr->log('payment-worker', 'info', 'jobs', 'Settlement batch {batch} reconciled: {count} payments, {currency} {total}', ['batch' => 'SET-' . date('Ymd') . '-' . mt_rand(10, 99), 'count' => mt_rand(20, 300), 'currency' => 'EUR', 'total' => round(mt_rand(100000, 2000000) / 100, 2)]);
                break;
            default:
                $tr->log('auth-service', 'info', 'jobs', 'Purged {count} expired sessions', ['count' => mt_rand(5, 500)]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    private static function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }
}
