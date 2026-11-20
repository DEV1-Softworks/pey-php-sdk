<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Validation;

use Dev1\Pey\Validation\NumRef;
use Dev1\Pey\Validation\PhoneNumber;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ulid::class)]
#[CoversClass(NumRef::class)]
#[CoversClass(PhoneNumber::class)]
#[UsesClass(Violations::class)]
final class IdentifierFormatsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideUlids(): iterable
    {
        // Valid ULIDs taken from Pey's docs where they are well-formed.
        yield 'bank account id from the docs' => ['01JBAVWE4NS5TGXHWGMX3KH23Q', true];
        yield 'transaction id from a webhook example' => ['01M3QR8H0RH0XBCKYYD242YXNV', true];
        yield 'payment link id from the docs' => ['01KRFJJYPMP24MF3EFX1043RNC', true];
        yield 'lower-case (ULIDs are case-insensitive)' => ['01jbavwe4ns5tgxhwgmx3kh23q', true];
        yield 'minimum' => ['00000000000000000000000000', true];
        yield 'maximum' => ['7ZZZZZZZZZZZZZZZZZZZZZZZZZ', true];

        // Malformed ids that appear in Pey's own spec examples.
        yield 'spec example with 24 characters' => ['01HXYZ1234567890ABCDEFGH', false];
        yield 'spec example with 22 characters' => ['01J5KXYZ1234567890ABCD', false];

        yield 'timestamp overflow' => ['80000000000000000000000000', false];
        yield 'excluded letter I' => ['01JBAVWE4NS5TGXHWGMX3KH2I1', false];
        yield 'excluded letter L' => ['01JBAVWE4NS5TGXHWGMX3KH2L1', false];
        yield 'excluded letter O' => ['01JBAVWE4NS5TGXHWGMX3KH2O1', false];
        yield 'excluded letter U' => ['01JBAVWE4NS5TGXHWGMX3KH2U1', false];
        yield '27 characters' => ['01JBAVWE4NS5TGXHWGMX3KH23QA', false];
        yield 'empty' => ['', false];
        yield 'CoDi QR IDC' => ['AB12CD34EF', false];
        yield 'path traversal' => ['../../../../../../../etc/pa', false];
        yield 'trailing line break' => ["01JBAVWE4NS5TGXHWGMX3KH23Q\n", false];
    }

    #[DataProvider('provideUlids')]
    public function testUlids(string $value, bool $valid): void
    {
        self::assertSame($valid, Ulid::isValid($value));

        $violations = new Violations();
        Ulid::check($value, 'bankAccountId', $violations);
        self::assertSame(
            $valid ? [] : ['bankAccountId' => ['must be a ULID (26 Crockford base32 characters).']],
            $violations->all(),
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideNumRefs(): iterable
    {
        yield 'Pey default' => ['0', true];
        yield 'one digit' => ['7', true];
        yield 'seven digits' => ['1234567', true];
        yield 'leading zeros are kept' => ['0012345', true];
        yield 'empty' => ['', false];
        yield 'eight digits' => ['12345678', false];
        yield 'letters' => ['12a', false];
        yield 'negative' => ['-1', false];
        yield 'decimal' => ['1.5', false];
        yield 'space' => ['123 45', false];
        yield 'trailing line break' => ["123\n", false];
    }

    #[DataProvider('provideNumRefs')]
    public function testNumRefs(string $value, bool $valid): void
    {
        self::assertSame($valid, NumRef::isValid($value));

        $violations = new Violations();
        NumRef::check($value, 'numRef', $violations);
        self::assertSame($valid ? [] : ['numRef' => ['must be 1 to 7 digits.']], $violations->all());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providePhoneNumbers(): iterable
    {
        yield 'ten digits' => ['5512345678', true];
        yield 'nine digits' => ['551234567', false];
        yield 'eleven digits' => ['55123456789', false];
        yield 'country code' => ['+525512345678', false];
        yield 'country code without plus' => ['525512345678', false];
        yield 'separators' => ['55-1234-5678', false];
        yield 'spaces' => ['55 1234 5678', false];
        yield 'empty' => ['', false];
        yield 'trailing line break' => ["5512345678\n", false];
    }

    #[DataProvider('providePhoneNumbers')]
    public function testPhoneNumbers(string $value, bool $valid): void
    {
        self::assertSame($valid, PhoneNumber::isValid($value));

        $violations = new Violations();
        PhoneNumber::check($value, 'phoneNumber', $violations);
        self::assertSame(
            $valid ? [] : ['phoneNumber' => ['must be exactly 10 digits, without country code or separators.']],
            $violations->all(),
        );
    }
}
