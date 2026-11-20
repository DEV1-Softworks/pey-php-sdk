<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Guards project-level rules that are easy to break by accident:
 * the "unofficial" positioning and the runtime dependency allowlist.
 */
#[CoversNothing]
final class PackageMetadataTest extends TestCase
{
    /**
     * Runtime dependencies approved for this package. Adding anything else
     * requires explicit maintainer approval (see .claude/rules/security.md).
     */
    private const ALLOWED_RUNTIME_DEPENDENCIES = [
        'php',
        'php-http/discovery',
        'psr/clock',
        'psr/http-client',
        'psr/http-factory',
        'psr/http-message',
        'psr/log',
        'standard-webhooks/standard-webhooks',
    ];

    public function testPackageIdentity(): void
    {
        $composer = self::composerJson();

        self::assertSame('dev1/pey-core', $composer['name'] ?? null);
        self::assertSame('MIT', $composer['license'] ?? null);
        self::assertSame(['Dev1\\Pey\\' => 'src/'], self::psr4Autoload($composer));
    }

    public function testDescriptionPresentsThePackageAsUnofficial(): void
    {
        $description = self::composerJson()['description'] ?? null;

        self::assertIsString($description);
        self::assertStringStartsWith('Unofficial PHP SDK for the Pey API', $description);
    }

    public function testReadmeCarriesTheUnofficialNotice(): void
    {
        $readme = self::read('README.md');

        self::assertStringContainsString('Unofficial PHP SDK for the Pey API', $readme);
        self::assertStringContainsString('not affiliated with, endorsed by, or supported by Pey or Banco de México', $readme);
    }

    public function testRuntimeDependenciesAreLimitedToTheAllowlist(): void
    {
        $require = self::composerJson()['require'] ?? [];
        self::assertIsArray($require);

        $unexpected = [];
        foreach (array_keys($require) as $package) {
            $package = (string) $package;
            if (str_starts_with($package, 'ext-')) {
                continue;
            }
            if (!\in_array($package, self::ALLOWED_RUNTIME_DEPENDENCIES, true)) {
                $unexpected[] = $package;
            }
        }

        self::assertSame([], $unexpected, 'Runtime dependencies outside the approved allowlist.');
    }

    public function testGuzzleIsNeverARuntimeDependency(): void
    {
        $require = self::composerJson()['require'] ?? [];
        self::assertIsArray($require);

        self::assertArrayNotHasKey('guzzlehttp/guzzle', $require);
    }

    public function testEnvFilesAreGitIgnored(): void
    {
        $lines = array_map('trim', explode("\n", self::read('.gitignore')));

        self::assertContains('.env*', $lines);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function composerJson(): array
    {
        $decoded = json_decode(self::read('composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $composer
     *
     * @return array<array-key, mixed>
     */
    private static function psr4Autoload(array $composer): array
    {
        $autoload = $composer['autoload'] ?? null;
        self::assertIsArray($autoload);
        $psr4 = $autoload['psr-4'] ?? null;
        self::assertIsArray($psr4);

        return $psr4;
    }

    private static function read(string $relativePath): string
    {
        $contents = file_get_contents(\dirname(__DIR__) . '/' . $relativePath);
        self::assertIsString($contents, \sprintf('Unable to read %s.', $relativePath));

        return $contents;
    }
}
