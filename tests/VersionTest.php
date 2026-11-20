<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests;

use Dev1\Pey\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    /**
     * Semantic Versioning 2.0.0, from https://semver.org (official regex).
     */
    private const SEMVER = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        . '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'
        . '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    public function testVersionIsSemanticVersion(): void
    {
        self::assertMatchesRegularExpression(self::SEMVER, Version::VERSION);
    }

    public function testUserAgentIdentifiesPackageAndVersion(): void
    {
        self::assertStringStartsWith('dev1-pey-php-sdk/' . Version::VERSION . ' ', Version::USER_AGENT);
    }

    public function testUserAgentDeclaresTheSdkAsUnofficial(): void
    {
        self::assertStringContainsString('unofficial', Version::USER_AGENT);
    }

    public function testUserAgentIsAValidHeaderValue(): void
    {
        // RFC 9110 field-value: visible ASCII, spaces and tabs only. No CR/LF.
        self::assertMatchesRegularExpression('/^[\x21-\x7E][\x20-\x7E\t]*$/', Version::USER_AGENT);
    }
}
