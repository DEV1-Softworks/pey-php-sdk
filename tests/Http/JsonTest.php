<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Json::class)]
#[CoversClass(JsonNumber::class)]
final class JsonTest extends TestCase
{
    public function testItWritesJsonNumbersVerbatim(): void
    {
        $json = Json::encodeObject(['amount' => new JsonNumber('150.00'), 'tip' => new JsonNumber('0.10')]);

        self::assertSame('{"amount":150.00,"tip":0.10}', $json);
    }

    public function testItEncodesAnEmptyBodyAsAnObject(): void
    {
        self::assertSame('{}', Json::encodeObject([]));
    }

    public function testItEncodesScalarsNestedObjectsAndLists(): void
    {
        $json = Json::encodeObject([
            'concept' => 'Órden #1/2 "café"',
            'numRef' => '0012345',
            'generateImage' => true,
            'nothing' => null,
            'count' => 3,
            'ratio' => 1.0,
            'metadata' => ['orderId' => '12345', 'gift' => false],
            'list' => [1, 'two', new JsonNumber('3.50')],
            'empty' => [],
        ]);

        self::assertSame(
            '{"concept":"Órden #1/2 \"café\"","numRef":"0012345","generateImage":true,"nothing":null,"count":3,"ratio":1.0,'
            . '"metadata":{"orderId":"12345","gift":false},"list":[1,"two",3.50],"empty":[]}',
            $json,
        );
    }

    public function testItEncodesIntegerKeysOfNonListArraysAsStrings(): void
    {
        self::assertSame('{"5":"a","x":"b"}', Json::encodeObject([5 => 'a', 'x' => 'b']));
    }

    public function testItEncodesJsonSerializableValues(): void
    {
        $value = new class implements \JsonSerializable {
            public function jsonSerialize(): JsonNumber
            {
                return new JsonNumber('99.99');
            }
        };

        self::assertSame('{"amount":99.99}', Json::encodeObject(['amount' => $value]));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function provideUnencodableBodies(): iterable
    {
        yield 'invalid UTF-8' => [['concept' => "\xB1\x31"]];
        yield 'NaN' => [['value' => \NAN]];
        yield 'infinity' => [['value' => \INF]];
        yield 'plain object' => [['value' => new \stdClass()]];
        yield 'resource-like closure' => [['value' => static fn(): null => null]];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    #[DataProvider('provideUnencodableBodies')]
    public function testItRefusesValuesItCannotEncode(array $body): void
    {
        $this->expectException(\JsonException::class);

        Json::encodeObject($body);
    }

    public function testItRefusesExcessiveNesting(): void
    {
        $value = ['leaf' => true];
        for ($i = 0; $i < 70; ++$i) {
            $value = ['nested' => $value];
        }

        $this->expectException(\JsonException::class);

        Json::encodeObject($value);
    }

    public function testItDecodesNumbersAsDecimalStrings(): void
    {
        $decoded = Json::decode('{"amount":118.50,"base":100,"negative":-0.5,"big":12345678901234567890,"exp":1.5e3,"zero":0}');

        self::assertSame([
            'amount' => '118.50',
            'base' => '100',
            'negative' => '-0.5',
            'big' => '12345678901234567890',
            'exp' => '1.5e3',
            'zero' => '0',
        ], $decoded);
    }

    public function testItLeavesDigitsInsideStringsUntouched(): void
    {
        $decoded = Json::decode('{"concept":"Order 123 \"x 4.5\" \\\\ 6","escaped":"a\\"7","unicode":"é 8"}');

        self::assertSame([
            'concept' => 'Order 123 "x 4.5" \\ 6',
            'escaped' => 'a"7',
            'unicode' => 'é 8',
        ], $decoded);
    }

    public function testItDecodesNestedStructuresAndKeywords(): void
    {
        $decoded = Json::decode('{"items":[{"amount":1.5,"paid":true,"tip":null}],"nextToken":null,"ok":false}');

        self::assertSame([
            'items' => [['amount' => '1.5', 'paid' => true, 'tip' => null]],
            'nextToken' => null,
            'ok' => false,
        ], $decoded);
    }

    public function testItDecodesTopLevelLists(): void
    {
        self::assertSame(['1', '2.5'], Json::decode('[1, 2.5]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidDocuments(): iterable
    {
        yield 'empty' => [''];
        yield 'garbage' => ['<html>Bad Gateway</html>'];
        yield 'truncated' => ['{"amount": 1'];
        yield 'unterminated string' => ['{"a": "b'];
        yield 'top-level string' => ['"hello"'];
        yield 'top-level number' => ['42'];
        yield 'top-level null' => ['null'];
        yield 'leading zero' => ['{"a": 01}'];
    }

    #[DataProvider('provideInvalidDocuments')]
    public function testItRejectsInvalidDocuments(string $json): void
    {
        $this->expectException(\JsonException::class);

        Json::decode($json);
    }

    public function testItRejectsInvalidUtf8InsteadOfFailingSilently(): void
    {
        $this->expectException(\JsonException::class);

        Json::decode("{\"a\":\"\xB1\x31\"}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidNumberLiterals(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with decimals' => ['0.00'];
        yield 'integer' => ['150'];
        yield 'two decimals' => ['150.00'];
        yield 'push maximum' => ['1000000.00'];
        yield 'negative' => ['-5.25'];
    }

    #[DataProvider('provideValidNumberLiterals')]
    public function testJsonNumberAcceptsPlainDecimals(string $literal): void
    {
        self::assertSame($literal, (new JsonNumber($literal))->literal);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidNumberLiterals(): iterable
    {
        yield 'empty' => [''];
        yield 'exponent' => ['1e3'];
        yield 'leading zero' => ['01'];
        yield 'trailing dot' => ['1.'];
        yield 'leading dot' => ['.5'];
        yield 'plus sign' => ['+1'];
        yield 'thousands separator' => ['1,000.00'];
        yield 'space' => [' 1'];
        yield 'injection' => ['1,"admin":true'];
        yield 'NaN' => ['NaN'];
    }

    #[DataProvider('provideInvalidNumberLiterals')]
    public function testJsonNumberRejectsAnythingElse(string $literal): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JsonNumber($literal);
    }
}
