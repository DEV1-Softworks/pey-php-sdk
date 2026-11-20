<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Validation;

use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Validation\Clabe;
use Dev1\Pey\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

#[CoversClass(Clabe::class)]
#[UsesClass(Violations::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class ClabeTest extends TestCase
{
    /**
     * Valid CLABEs from public documentation.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideKnownValidClabes(): iterable
    {
        yield 'Banco de México documentation example (032 180 00011835971 9)' => ['032180000118359719'];
        yield 'commonly cited example (002 010 07777777777 1)' => ['002010077777777771'];
        yield 'Pey documentation example' => ['012180001234567899'];
        // Format and check digit are fine; whether the bank exists is Pey's call.
        yield 'all zeros (check digit 0)' => ['000000000000000000'];
    }

    #[DataProvider('provideKnownValidClabes')]
    public function testKnownClabesAreValid(string $clabe): void
    {
        self::assertTrue(Clabe::isValid($clabe));
        self::assertSame((int) $clabe[17], Clabe::checkDigit(substr($clabe, 0, 17)));

        $violations = new Violations();
        Clabe::check($clabe, 'clabe', $violations);
        self::assertTrue($violations->isEmpty());
    }

    /**
     * Every wrong check digit for every known valid CLABE.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideWrongCheckDigits(): iterable
    {
        foreach (self::provideKnownValidClabes() as [$clabe]) {
            for ($digit = 0; $digit <= 9; ++$digit) {
                if ($digit !== (int) $clabe[17]) {
                    $wrong = substr($clabe, 0, 17) . $digit;
                    yield $wrong => [$wrong];
                }
            }
        }
    }

    #[DataProvider('provideWrongCheckDigits')]
    public function testAWrongCheckDigitIsRejected(string $clabe): void
    {
        self::assertFalse(Clabe::isValid($clabe));

        $violations = new Violations();
        Clabe::check($clabe, 'clabe', $violations);
        self::assertSame(['clabe' => ['has an invalid check digit.']], $violations->all());
    }

    public function testASingleDigitTypoIsDetected(): void
    {
        // The weighted check digit catches any single-digit substitution.
        $valid = '032180000118359719';
        for ($position = 0; $position < 17; ++$position) {
            $typo = $valid;
            $typo[$position] = (string) (((int) $valid[$position] + 1) % 10);
            self::assertFalse(Clabe::isValid($typo), \sprintf('Typo at position %d went undetected.', $position));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedClabes(): iterable
    {
        yield 'empty' => [''];
        yield '17 digits' => ['03218000011835971'];
        yield '19 digits' => ['0321800001183597190'];
        yield 'spaces' => ['032 180 00011835971 9'];
        yield 'dashes' => ['032-180-00011835971-9'];
        yield 'letters' => ['03218000011835971X'];
        yield 'trailing line break' => ["032180000118359719\n"];
        yield 'leading space' => [' 032180000118359719'];
        yield 'full-width digits' => ['０３２１８００００１１８３５９７１９'];
        yield 'negative sign' => ['-32180000118359719'];
    }

    #[DataProvider('provideMalformedClabes')]
    public function testMalformedClabesAreRejected(string $clabe): void
    {
        self::assertFalse(Clabe::isValid($clabe));

        $violations = new Violations();
        Clabe::check($clabe, 'bankAccount.clabe', $violations);
        self::assertSame(['bankAccount.clabe' => ['must be exactly 18 digits.']], $violations->all());
    }

    public function testErrorsNeverEchoTheClabe(): void
    {
        $violations = new Violations();
        Clabe::check('032180000118359710', 'clabe', $violations);

        try {
            $violations->throwIfAny();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertStringNotContainsString('0321800001183597', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidCheckDigitInputs(): iterable
    {
        yield '16 digits' => ['0321800001183597'];
        yield '18 digits' => ['032180000118359719'];
        yield 'letters' => ['0321800001183597A'];
        yield 'trailing line break' => ["03218000011835971\n"];
    }

    #[DataProvider('provideInvalidCheckDigitInputs')]
    public function testCheckDigitNeedsExactly17Digits(string $input): void
    {
        $this->expectException(ValidationException::class);

        Clabe::checkDigit($input);
    }

    /**
     * Property: for any 17-digit prefix, exactly one of the ten possible final
     * digits yields a valid CLABE, and it matches an independent implementation.
     */
    public function testExactlyOneCheckDigitIsValidForAnyPrefix(): void
    {
        $randomizer = new Randomizer(new Mt19937(20261120));

        for ($i = 0; $i < 500; ++$i) {
            $prefix = '';
            for ($d = 0; $d < 17; ++$d) {
                $prefix .= (string) $randomizer->getInt(0, 9);
            }

            $valid = [];
            for ($digit = 0; $digit <= 9; ++$digit) {
                if (Clabe::isValid($prefix . $digit)) {
                    $valid[] = $digit;
                }
            }

            self::assertSame([self::referenceCheckDigit($prefix)], $valid, 'Prefix ' . $prefix);
            self::assertSame($valid[0], Clabe::checkDigit($prefix));
        }
    }

    /**
     * Independent implementation of Banco de México's algorithm, written
     * differently on purpose: weights cycle by index modulo 3.
     */
    private static function referenceCheckDigit(string $first17): int
    {
        $weights = [3, 7, 1];
        $products = array_map(
            static fn(string $digit, int $index): int => ((int) $digit * $weights[$index % 3]) % 10,
            str_split($first17),
            array_keys(str_split($first17)),
        );

        return (10 - array_sum($products) % 10) % 10;
    }
}
