<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Money;

use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Money\Amount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Amount::class)]
#[UsesClass(JsonNumber::class)]
#[UsesClass(Json::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class AmountTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideDecimalStrings(): iterable
    {
        yield 'zero' => ['0', 0, '0.00'];
        yield 'zero with decimals' => ['0.00', 0, '0.00'];
        yield 'one centavo' => ['0.01', 1, '0.01'];
        yield 'ten centavos, one decimal' => ['0.1', 10, '0.10'];
        yield 'integer pesos' => ['150', 15000, '150.00'];
        yield 'one decimal' => ['150.5', 15050, '150.50'];
        yield 'two decimals' => ['150.55', 15055, '150.55'];
        yield 'push notification maximum' => ['1000000', 100_000_000, '1000000.00'];
        yield 'largest accepted' => ['999999999999999.99', 99_999_999_999_999_999, '999999999999999.99'];
        yield 'trailing zeros beyond centavos' => ['12.3400000', 1234, '12.34'];
    }

    #[DataProvider('provideDecimalStrings')]
    public function testItParsesDecimalStrings(string $input, int $centavos, string $decimal): void
    {
        $amount = Amount::fromString($input);

        self::assertSame($centavos, $amount->centavos());
        self::assertSame($decimal, $amount->toDecimalString());
    }

    /**
     * Pey rounds half-up to two decimals; only the third decimal matters.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideHalfUpRounding(): iterable
    {
        yield 'below half rounds down' => ['10.004', '10.00'];
        yield 'just below half rounds down' => ['10.0049999999', '10.00'];
        yield 'exactly half rounds up' => ['10.005', '10.01'];
        yield 'above half rounds up' => ['10.006', '10.01'];
        yield 'nine rounds up' => ['10.009', '10.01'];
        yield 'carries into pesos' => ['0.995', '1.00'];
        yield 'carries across digits' => ['99.999', '100.00'];
        yield 'smallest amount rounds to zero' => ['0.004', '0.00'];
        yield 'smallest half rounds to a centavo' => ['0.005', '0.01'];
        yield 'float trap 1.005 is exact here' => ['1.005', '1.01'];
        yield 'float trap 2.675 is exact here' => ['2.675', '2.68'];
        yield 'float trap 1.015 is exact here' => ['1.015', '1.02'];
        yield 'largest amount carries into a new digit' => ['999999999999999.995', '1000000000000000.00'];
    }

    #[DataProvider('provideHalfUpRounding')]
    public function testItRoundsHalfUpLikePey(string $input, string $expected): void
    {
        self::assertSame($expected, Amount::fromString($input)->toDecimalString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedStrings(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'leading dot' => ['.5'];
        yield 'trailing dot' => ['5.'];
        yield 'leading zero' => ['0150'];
        yield 'double zero' => ['00.50'];
        yield 'plus sign' => ['+150'];
        yield 'exponent' => ['1e3'];
        yield 'upper-case exponent' => ['1.5E2'];
        yield 'thousands separator' => ['1,000.00'];
        yield 'decimal comma' => ['150,50'];
        yield 'leading space' => [' 150'];
        yield 'trailing space' => ['150 '];
        yield 'trailing line break' => ["150\n"];
        yield 'currency symbol' => ['$150'];
        yield 'two dots' => ['1.2.3'];
        yield 'infinity' => ['INF'];
        yield 'not a number' => ['NAN'];
        yield 'hexadecimal' => ['0x10'];
        yield 'too many integer digits' => ['1000000000000000'];
        yield 'full-width digits' => ['１５０'];
    }

    #[DataProvider('provideMalformedStrings')]
    public function testItRejectsMalformedStrings(string $input): void
    {
        try {
            Amount::fromString($input, 'tip');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['tip'], array_keys($e->errors()));
            self::assertFalse($e->wasRejectedByPey());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNegativeStrings(): iterable
    {
        yield 'negative' => ['-150.00'];
        yield 'negative zero' => ['-0'];
        yield 'negative garbage' => ['-abc'];
    }

    #[DataProvider('provideNegativeStrings')]
    public function testItRejectsNegativeStrings(string $input): void
    {
        try {
            Amount::fromString($input);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['amount' => ['must not be negative.']], $e->errors());
        }
    }

    public function testErrorsNeverEchoTheValue(): void
    {
        try {
            Amount::fromString('secret-looking-12345');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertStringNotContainsString('secret-looking-12345', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideCentavos(): iterable
    {
        yield 'zero' => [0, '0.00'];
        yield 'one centavo' => [1, '0.01'];
        yield 'nine centavos' => [9, '0.09'];
        yield 'ten centavos' => [10, '0.10'];
        yield 'one peso' => [100, '1.00'];
        yield 'mixed' => [123456, '1234.56'];
        yield 'PHP_INT_MAX' => [\PHP_INT_MAX, '92233720368547758.07'];
    }

    #[DataProvider('provideCentavos')]
    public function testItBuildsFromIntegerCentavos(int $centavos, string $decimal): void
    {
        $amount = Amount::fromCentavos($centavos);

        self::assertSame($centavos, $amount->centavos());
        self::assertSame($decimal, $amount->toDecimalString());
        self::assertSame($decimal, (string) $amount);
    }

    public function testItRejectsNegativeCentavos(): void
    {
        try {
            Amount::fromCentavos(-1, 'fee');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['fee' => ['must not be negative.']], $e->errors());
        }
    }

    public function testZero(): void
    {
        self::assertTrue(Amount::zero()->isZero());
        self::assertFalse(Amount::fromString('0.01')->isZero());
        self::assertSame('0.00', Amount::zero()->toDecimalString());
    }

    public function testComparisons(): void
    {
        $small = Amount::fromString('99.99');
        $large = Amount::fromString('100');
        $sameAsLarge = Amount::fromCentavos(10000);

        self::assertTrue($large->equals($sameAsLarge));
        self::assertFalse($large->equals($small));
        self::assertSame(0, $large->compareTo($sameAsLarge));
        self::assertSame(-1, $small->compareTo($large));
        self::assertSame(1, $large->compareTo($small));
        self::assertTrue($large->isGreaterThan($small));
        self::assertFalse($small->isGreaterThan($large));
        self::assertFalse($large->isGreaterThan($sameAsLarge));
        self::assertTrue($small->isLessThan($large));
        self::assertFalse($large->isLessThan($small));
        self::assertFalse($large->isLessThan($sameAsLarge));
    }

    public function testAdditionIsExact(): void
    {
        // 0.1 + 0.2 is the classic float trap.
        self::assertSame('0.30', Amount::fromString('0.1')->add(Amount::fromString('0.2'))->toDecimalString());

        // A Pey breakdown: base + tip + fee = total.
        $total = Amount::fromString('100')->add(Amount::fromString('15'))->add(Amount::fromString('3.5'));
        self::assertTrue($total->equals(Amount::fromString('118.50')));
    }

    public function testItIsImmutable(): void
    {
        $base = Amount::fromString('100');
        $base->add(Amount::fromString('1'));

        self::assertSame('100.00', $base->toDecimalString());
    }

    public function testItSerializesAsADecimalStringForConsumers(): void
    {
        self::assertSame('{"total":"150.00"}', json_encode(['total' => Amount::fromString('150')]));
    }

    public function testItIsSentToPeyAsAnExactJsonNumber(): void
    {
        self::assertSame('150.00', Amount::fromString('150')->toJsonNumber()->literal);
        self::assertSame('{"amount":0.01}', Json::encodeObject(['amount' => Amount::fromString('0.01')->toJsonNumber()]));
    }

    public function testNoPublicMethodAcceptsOrReturnsAFloat(): void
    {
        $offenders = [];

        foreach ((new \ReflectionClass(Amount::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $types = [$method->getReturnType()];
            foreach ($method->getParameters() as $parameter) {
                $types[] = $parameter->getType();
            }

            foreach ($types as $type) {
                if ($type !== null && str_contains((string) $type, 'float')) {
                    $offenders[] = $method->getName();
                }
            }
        }

        self::assertSame([], $offenders, 'Money must never be a float.');
    }
}
