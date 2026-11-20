<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the README and docs/ honest: every PHP example must parse, and every
 * SDK class it imports must exist.
 */
#[CoversNothing]
final class DocumentationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideExamples(): iterable
    {
        $root = \dirname(__DIR__);
        $docs = glob($root . '/docs/*.md');
        $files = [$root . '/README.md', ...($docs === false ? [] : $docs)];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            preg_match_all('/```php\n(.*?)```/s', $contents, $blocks);
            foreach ($blocks[1] as $index => $code) {
                yield \sprintf('%s #%d', substr($file, \strlen($root) + 1), $index + 1) => [$file, $code];
            }
        }
    }

    public function testThereAreExamplesToCheck(): void
    {
        self::assertGreaterThan(15, iterator_count(self::provideExamples()));
    }

    #[DataProvider('provideExamples')]
    public function testExamplesParse(string $file, string $code): void
    {
        try {
            $tokens = token_get_all('<?php ' . $code, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            self::fail(\sprintf('A PHP example in %s does not parse: %s', basename($file), $e->getMessage()));
        }

        self::assertNotSame([], $tokens);
    }

    #[DataProvider('provideExamples')]
    public function testImportedSdkClassesExist(string $file, string $code): void
    {
        preg_match_all('/^use (Dev1\\\\Pey\\\\[\w\\\\]+);/m', $code, $imports);

        foreach ($imports[1] as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || enum_exists($class),
                \sprintf('%s imports %s, which does not exist.', basename($file), $class),
            );
        }

        $this->addToAssertionCount(1);
    }
}
