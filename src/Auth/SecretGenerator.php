<?php

declare(strict_types=1);

namespace LogService\Auth;

/**
 * Generates the secrets LogStream hands out, each with a recognisable prefix.
 *
 *   lss_…  application token (X-Api-Token, MariaDB mode) — APP_TOKEN_PREFIX
 *   lsu_…  UI secret (UI_SECRET)                         — UI_SECRET_PREFIX
 *   lsa_…  API secret (API_SECRET, file mode)            — API_SECRET_PREFIX
 *
 * The prefix only labels the secret (so it can be recognised in logs, config
 * files and secret scanners); the random part is always 32 bytes from
 * random_bytes(), encoded as URL-safe base64 without padding (43 chars).
 *
 * Prefixes apply to newly generated secrets only. Authentication compares the
 * whole value, so secrets created before the prefixes existed keep working.
 */
final class SecretGenerator
{
    public const DEFAULT_APP_TOKEN_PREFIX  = 'lss_';
    public const DEFAULT_UI_SECRET_PREFIX  = 'lsu_';
    public const DEFAULT_API_SECRET_PREFIX = 'lsa_';

    public const ENV_APP_TOKEN_PREFIX  = 'APP_TOKEN_PREFIX';
    public const ENV_UI_SECRET_PREFIX  = 'UI_SECRET_PREFIX';
    public const ENV_API_SECRET_PREFIX = 'API_SECRET_PREFIX';

    /** Letters, digits, "_" and "-", up to 32 characters; empty disables the prefix. */
    private const PREFIX_PATTERN = '/^[A-Za-z0-9_-]{0,32}$/D';

    private function __construct(private readonly string $prefix) {}

    /**
     * @throws \InvalidArgumentException when the prefix has characters that are
     *         not safe in an HTTP header or an env file, or is too long.
     */
    public static function withPrefix(string $prefix): self
    {
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new \InvalidArgumentException(
                'Invalid secret prefix ' . json_encode($prefix, JSON_INVALID_UTF8_SUBSTITUTE)
                . ": use up to 32 letters, digits, '_' or '-'."
            );
        }

        return new self($prefix);
    }

    /**
     * Build a generator from an environment variable, falling back to the
     * default when the variable is not set. An empty value means no prefix.
     *
     * @param array<string, mixed> $env Usually $_ENV after Dotenv has loaded.
     */
    public static function fromEnv(array $env, string $variable, string $default): self
    {
        $value = $env[$variable] ?? null;

        return self::withPrefix(is_string($value) ? trim($value) : $default);
    }

    public static function forAppTokens(array $env = []): self
    {
        return self::fromEnv($env, self::ENV_APP_TOKEN_PREFIX, self::DEFAULT_APP_TOKEN_PREFIX);
    }

    public static function forUiSecret(array $env = []): self
    {
        return self::fromEnv($env, self::ENV_UI_SECRET_PREFIX, self::DEFAULT_UI_SECRET_PREFIX);
    }

    public static function forApiSecret(array $env = []): self
    {
        return self::fromEnv($env, self::ENV_API_SECRET_PREFIX, self::DEFAULT_API_SECRET_PREFIX);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** Prefix followed by 43 URL-safe base64 characters (32 random bytes). */
    public function generate(): string
    {
        return $this->prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
