<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Validation;

use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Expiration;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Url;
use Dev1\Pey\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmountRules::class)]
#[CoversClass(Expiration::class)]
#[CoversClass(Url::class)]
#[CoversClass(Text::class)]
#[CoversClass(Violations::class)]
#[UsesClass(Amount::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class RequestRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providePushNotificationAmounts(): iterable
    {
        yield 'zero' => ['0', false];
        yield 'rounds to zero' => ['0.004', false];
        yield 'one centavo' => ['0.01', true];
        yield 'typical' => ['150.00', true];
        yield 'maximum' => ['1000000.00', true];
        yield 'maximum that rounds down' => ['1000000.004', true];
        yield 'one centavo over' => ['1000000.01', false];
        yield 'rounds over the maximum' => ['1000000.005', false];
    }

    #[DataProvider('providePushNotificationAmounts')]
    public function testPushNotificationAmounts(string $amount, bool $valid): void
    {
        $violations = new Violations();
        AmountRules::checkPushNotification(Amount::fromString($amount), 'amount', $violations);

        self::assertSame($valid ? [] : ['amount' => ['must be greater than 0 and at most 1000000.00.']], $violations->all());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function providePaymentLinkAmounts(): iterable
    {
        yield 'zero' => ['0.00', false];
        yield 'one centavo' => ['0.01', true];
        yield 'above the push notification maximum' => ['2500000.00', true];
    }

    #[DataProvider('providePaymentLinkAmounts')]
    public function testPaymentLinkAmounts(string $amount, bool $valid): void
    {
        $violations = new Violations();
        AmountRules::checkPaymentLink(Amount::fromString($amount), 'amount', $violations);

        self::assertSame($valid ? [] : ['amount' => ['must be greater than 0.']], $violations->all());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideQrExpirations(): iterable
    {
        yield 'never expires' => [0, true];
        yield 'one second' => [1, false];
        yield 'just below the minimum' => [359, false];
        yield 'minimum' => [360, true];
        yield 'one day' => [86400, true];
        yield 'negative' => [-1, false];
    }

    #[DataProvider('provideQrExpirations')]
    public function testQrExpirations(int $seconds, bool $valid): void
    {
        $violations = new Violations();
        Expiration::checkQr($seconds, 'expiration', $violations);

        self::assertSame($valid ? [] : ['expiration' => ['must be 0 (no expiration) or at least 360 seconds.']], $violations->all());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function providePushNotificationExpirations(): iterable
    {
        yield 'zero is not allowed' => [0, false];
        yield 'just below the minimum' => [359, false];
        yield 'minimum and default' => [360, true];
        yield 'one hour' => [3600, true];
        yield 'negative' => [-360, false];
    }

    #[DataProvider('providePushNotificationExpirations')]
    public function testPushNotificationExpirations(int $seconds, bool $valid): void
    {
        $violations = new Violations();
        Expiration::checkPushNotification($seconds, 'expiration', $violations);

        self::assertSame($valid ? [] : ['expiration' => ['must be at least 360 seconds.']], $violations->all());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideRedirectUrls(): iterable
    {
        yield 'https' => ['https://example.com/success', true];
        yield 'https with query and fragment' => ['https://shop.example.com/orders/123?status=paid#top', true];
        yield 'upper-case scheme' => ['HTTPS://example.com', true];
        yield 'https with port' => ['https://example.com:8443/ok', true];
        yield 'http' => ['http://example.com/success', false];
        yield 'no scheme' => ['example.com/success', false];
        yield 'relative' => ['/success', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,hi', false];
        yield 'no host' => ['https:///path', false];
        yield 'empty' => ['', false];
        yield 'space' => ['https://example.com/a b', false];
        yield 'trailing line break' => ["https://example.com\n", false];
        yield 'protocol-relative' => ['//example.com', false];
    }

    #[DataProvider('provideRedirectUrls')]
    public function testRedirectUrlsMustBeHttps(string $url, bool $valid): void
    {
        self::assertSame($valid, Url::isHttps($url));

        $violations = new Violations();
        Url::checkHttps($url, 'successUrl', $violations);
        self::assertSame($valid ? [] : ['successUrl' => ['must be an absolute https:// URL without spaces.']], $violations->all());
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function provideTextLengths(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'ascii' => ['BBVA', 4];
        yield 'accents count as one character' => ['Pagos con Pey S.A. de C.V. — Ñandú', 34];
        yield 'emoji counts as one character' => ['💸', 1];
        yield 'line breaks count' => ["a\nb", 3];
        yield 'invalid UTF-8' => ["\xB1\x31", null];
    }

    #[DataProvider('provideTextLengths')]
    public function testTextLengthCountsCharactersNotBytes(string $value, ?int $length): void
    {
        self::assertSame($length, Text::length($value));
    }

    /**
     * @return iterable<string, array{string, array<string, list<string>>}>
     */
    public static function provideLengthChecks(): iterable
    {
        yield 'minimum' => ['B', []];
        yield 'maximum' => [str_repeat('é', 255), []];
        yield 'empty' => ['', ['alias' => ['must be between 1 and 255 characters.']]];
        yield 'too long' => [str_repeat('a', 256), ['alias' => ['must be between 1 and 255 characters.']]];
        yield 'invalid UTF-8' => ["\xB1\x31", ['alias' => ['must be valid UTF-8.']]];
    }

    /**
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('provideLengthChecks')]
    public function testTextLengthChecks(string $value, array $expected): void
    {
        $violations = new Violations();
        Text::checkLength($value, 1, 255, 'alias', $violations);

        self::assertSame($expected, $violations->all());
    }

    public function testFreeTextMustBeValidUtf8(): void
    {
        $violations = new Violations();
        Text::checkUtf8('Pago en Tacos Pey — ñ 🌮', 'concept', $violations);
        self::assertTrue($violations->isEmpty());

        Text::checkUtf8("\xB1\x31", 'concept', $violations);
        self::assertSame(['concept' => ['must be valid UTF-8.']], $violations->all());
    }

    public function testExactLengthMessage(): void
    {
        $violations = new Violations();
        Text::checkLength('abc', 2, 2, 'code', $violations);

        self::assertSame(['code' => ['must be exactly 2 characters.']], $violations->all());
    }

    public function testViolationsCollectEveryErrorAndThrowOnce(): void
    {
        $violations = (new Violations())
            ->add('amount', 'must be greater than 0.')
            ->add('numRef', 'must be 1 to 7 digits.')
            ->add('numRef', 'must be a string.');

        self::assertFalse($violations->isEmpty());

        try {
            $violations->throwIfAny();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame([
                'amount' => ['must be greater than 0.'],
                'numRef' => ['must be 1 to 7 digits.', 'must be a string.'],
            ], $e->errors());
        }
    }

    public function testEmptyViolationsDoNotThrow(): void
    {
        $violations = new Violations();

        $violations->throwIfAny();

        self::assertTrue($violations->isEmpty());
        self::assertSame([], $violations->all());
    }
}
