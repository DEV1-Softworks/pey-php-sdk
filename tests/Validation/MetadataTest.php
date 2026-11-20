<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Validation;

use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Metadata::class)]
#[UsesClass(Text::class)]
#[UsesClass(Violations::class)]
final class MetadataTest extends TestCase
{
    public function testTheDocumentedExampleIsValid(): void
    {
        self::assertTrue(Metadata::isValid(['orderId' => '12345', 'customerEmail' => 'customer@example.com', 'isGift' => false]));
    }

    public function testEveryScalarTypeAndNullAreAllowed(): void
    {
        self::assertTrue(Metadata::isValid([
            'string' => 'x',
            'int' => 123,
            'negative' => -5,
            'float' => 1.5,
            'true' => true,
            'false' => false,
            'null' => null,
            'empty' => '',
            7 => 'integer keys are sent as strings',
        ]));
    }

    public function testAnEmptyMapIsValid(): void
    {
        self::assertTrue(Metadata::isValid([]));
    }

    public function testFiftyEntriesAreTheMaximum(): void
    {
        $fifty = [];
        for ($i = 1; $i <= 50; ++$i) {
            $fifty['key' . $i] = $i;
        }

        self::assertTrue(Metadata::isValid($fifty));

        $violations = new Violations();
        Metadata::check($fifty + ['key51' => 51], 'metadata', $violations);
        self::assertSame(['metadata' => ['must have at most 50 entries.']], $violations->all());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideKeys(): iterable
    {
        yield '40 characters' => [str_repeat('k', 40), true];
        yield '40 accented characters' => [str_repeat('ñ', 40), true];
        yield '41 characters' => [str_repeat('k', 41), false];
        yield 'invalid UTF-8' => ["\xB1\x31", false];
    }

    #[DataProvider('provideKeys')]
    public function testKeysAreAtMost40Characters(string $key, bool $valid): void
    {
        $violations = new Violations();
        Metadata::check([$key => 'value'], 'metadata', $violations);

        self::assertSame($valid ? [] : ['metadata' => ['keys must be valid UTF-8 and at most 40 characters.']], $violations->all());
    }

    /**
     * Values are measured as Pey stores them: as strings.
     *
     * @return iterable<string, array{mixed, list<string>}>
     */
    public static function provideValues(): iterable
    {
        yield '500 characters' => [str_repeat('v', 500), []];
        yield '500 accented characters' => [str_repeat('é', 500), []];
        yield '501 characters' => [str_repeat('v', 501), ['must be at most 500 characters.']];
        yield 'invalid UTF-8' => ["\xB1\x31", ['must be valid UTF-8.']];
        yield 'nested array' => [['a' => 1], ['must be a string, number, boolean or null; nested arrays and objects are not allowed.']];
        yield 'list' => [[1, 2], ['must be a string, number, boolean or null; nested arrays and objects are not allowed.']];
        yield 'object' => [new \stdClass(), ['must be a string, number, boolean or null; nested arrays and objects are not allowed.']];
        yield 'infinity' => [\INF, ['must be a finite number.']];
        yield 'not a number' => [\NAN, ['must be a finite number.']];
        yield 'large integer' => [\PHP_INT_MAX, []];
        yield 'float' => [0.1, []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideValues')]
    public function testValuesAreScalarAndAtMost500Characters(mixed $value, array $expected): void
    {
        $violations = new Violations();
        Metadata::check(['orderId' => $value], 'metadata', $violations);

        self::assertSame($expected === [] ? [] : ['metadata.orderId' => $expected], $violations->all());
        self::assertSame($expected === [], Metadata::isValid(['orderId' => $value]));
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        $violations = new Violations();
        Metadata::check([
            'ok' => 'fine',
            'nested' => ['x'],
            str_repeat('k', 41) => 'v',
            'long' => str_repeat('v', 501),
        ], 'metadata', $violations);

        self::assertSame([
            'metadata.nested' => ['must be a string, number, boolean or null; nested arrays and objects are not allowed.'],
            'metadata' => ['keys must be valid UTF-8 and at most 40 characters.'],
            'metadata.long' => ['must be at most 500 characters.'],
        ], $violations->all());
    }

    public function testMessagesNeverEchoValues(): void
    {
        $violations = new Violations();
        Metadata::check(['email' => 'customer@example.com' . str_repeat('x', 500)], 'metadata', $violations);

        self::assertStringNotContainsString('customer@example.com', print_r($violations->all(), true));
    }
}
