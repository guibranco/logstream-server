<?php

declare(strict_types=1);

namespace LogService\Tests\Unit\Auth;

use LogService\Auth\SecretGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecretGeneratorTest extends TestCase
{
    #[Test]
    public function defaults_are_lss_lsu_and_lsa(): void
    {
        self::assertSame('lss_', SecretGenerator::forAppTokens()->prefix());
        self::assertSame('lsu_', SecretGenerator::forUiSecret()->prefix());
        self::assertSame('lsa_', SecretGenerator::forApiSecret()->prefix());
    }

    #[Test]
    public function generate_returns_prefix_and_43_url_safe_characters(): void
    {
        $secret = SecretGenerator::forApiSecret()->generate();

        self::assertMatchesRegularExpression('/^lsa_[A-Za-z0-9_-]{43}$/', $secret);
    }

    #[Test]
    public function generate_returns_a_different_value_each_time(): void
    {
        $generator = SecretGenerator::forUiSecret();

        self::assertNotSame($generator->generate(), $generator->generate());
    }

    #[Test]
    public function prefixes_are_read_from_the_environment(): void
    {
        $env = [
            'APP_TOKEN_PREFIX'  => 'app_',
            'UI_SECRET_PREFIX'  => 'ui-',
            'API_SECRET_PREFIX' => ' api_ ',
        ];

        self::assertSame('app_', SecretGenerator::forAppTokens($env)->prefix());
        self::assertSame('ui-', SecretGenerator::forUiSecret($env)->prefix());
        self::assertSame('api_', SecretGenerator::forApiSecret($env)->prefix());
    }

    #[Test]
    public function an_empty_prefix_disables_it(): void
    {
        $secret = SecretGenerator::forAppTokens(['APP_TOKEN_PREFIX' => ''])->generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret);
    }

    #[Test]
    #[DataProvider('invalidPrefixes')]
    public function invalid_prefixes_are_rejected(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SecretGenerator::withPrefix($prefix);
    }

    /** @return array<string, array{string}> */
    public static function invalidPrefixes(): array
    {
        return [
            'space'      => ['ls s_'],
            'equals'     => ['ls=_'],
            'newline'    => ["ls\n"],
            'too long'   => [str_repeat('a', 33)],
            'non-ascii'  => ['lsç_'],
        ];
    }
}
