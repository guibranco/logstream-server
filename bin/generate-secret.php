#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate new LogStream secrets with their prefixes.
 *
 *   php bin/generate-secret.php          → API_SECRET=lsa_… and UI_SECRET=lsu_… lines for .env
 *   php bin/generate-secret.php api      → one API secret (file mode write key)
 *   php bin/generate-secret.php ui       → one UI secret (read key)
 *   php bin/generate-secret.php app      → one application token (MariaDB mode)
 *
 * The prefixes come from API_SECRET_PREFIX, UI_SECRET_PREFIX and
 * APP_TOKEN_PREFIX (environment or .env), defaulting to lsa_, lsu_ and lss_.
 * Nothing is written anywhere: copy the output into your .env or secret store.
 * Existing secrets are not touched and keep working.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use LogService\Auth\SecretGenerator;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$env  = array_merge(getenv(), $_ENV);
$kind = strtolower($argv[1] ?? 'env');

try {
    switch ($kind) {
        case 'env':
            echo 'API_SECRET=' . SecretGenerator::forApiSecret($env)->generate() . "\n";
            echo 'UI_SECRET=' . SecretGenerator::forUiSecret($env)->generate() . "\n";
            break;
        case 'api':
            echo SecretGenerator::forApiSecret($env)->generate() . "\n";
            break;
        case 'ui':
            echo SecretGenerator::forUiSecret($env)->generate() . "\n";
            break;
        case 'app':
            echo SecretGenerator::forAppTokens($env)->generate() . "\n";
            break;
        default:
            fwrite(STDERR, "Usage: php bin/generate-secret.php [env|api|ui|app]\n");
            exit(2);
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
