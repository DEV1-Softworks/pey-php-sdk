<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Security;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Security\BankAccountGuard;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\Dumps;
use Dev1\Pey\Validation\Clabe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BankAccountGuard::class)]
#[CoversClass(BankAccountNotAllowedException::class)]
#[UsesClass(Clabe::class)]
#[UsesClass(Config::class)]
#[UsesClass(\Dev1\Pey\Http\RetryPolicy::class)]
#[UsesClass(Secret::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(PeyException::class)]
final class BankAccountGuardTest extends TestCase
{
    private const OURS = '032180000118359719';

    private const OTHER = '002010077777777771';

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValidClabes(): iterable
    {
        yield 'ours' => [self::OURS];
        yield 'another valid CLABE' => [self::OTHER];
        yield 'Pey documentation example' => ['012180001234567899'];
    }

    #[DataProvider('provideValidClabes')]
    public function testAnEmptyAllowlistBlocksEveryRegistration(string $clabe): void
    {
        $guard = new BankAccountGuard([]);

        self::assertFalse($guard->allowsAnyRegistration());
        self::assertFalse($guard->allows($clabe));

        try {
            $guard->assertAllowed($clabe);
            self::fail('Expected a BankAccountNotAllowedException.');
        } catch (BankAccountNotAllowedException $e) {
            self::assertStringStartsWith('Bank account registration is blocked: no CLABE is allowlisted', $e->getMessage());
            self::assertSame(Clabe::mask($clabe), $e->maskedClabe());
        }
    }

    public function testTheDefaultConfigBlocksEveryRegistration(): void
    {
        $guard = BankAccountGuard::fromConfig(new Config('pey_test_not_a_real_value'));

        self::assertFalse($guard->allowsAnyRegistration());
        $this->expectException(BankAccountNotAllowedException::class);
        $guard->assertAllowed(self::OURS);
    }

    public function testAnAllowlistedClabeIsAllowed(): void
    {
        $guard = BankAccountGuard::fromConfig(new Config('pey_test_not_a_real_value', allowedClabes: [self::OURS]));

        self::assertTrue($guard->allowsAnyRegistration());
        self::assertTrue($guard->allows(self::OURS));
        $guard->assertAllowed(self::OURS);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideClabesThatAreNotAllowlisted(): iterable
    {
        yield 'another valid CLABE' => [self::OTHER];
        yield 'one digit off' => ['032180000118359729'];
        yield 'with a trailing line break' => [self::OURS . "\n"];
        yield 'with spaces' => ['032 180 00011835971 9'];
        yield 'prefix only' => ['03218000011835971'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideClabesThatAreNotAllowlisted')]
    public function testAnyOtherClabeIsBlocked(string $clabe): void
    {
        $guard = new BankAccountGuard([self::OURS]);

        self::assertFalse($guard->allows($clabe));

        try {
            $guard->assertAllowed($clabe);
            self::fail('Expected a BankAccountNotAllowedException.');
        } catch (BankAccountNotAllowedException $e) {
            self::assertStringContainsString('is not on the allowlist', $e->getMessage());
        }
    }

    public function testErrorsShowOnlyTheLastFourDigits(): void
    {
        try {
            (new BankAccountGuard([self::OURS]))->assertAllowed(self::OTHER);
            self::fail('Expected a BankAccountNotAllowedException.');
        } catch (BankAccountNotAllowedException $e) {
            foreach (Dumps::ofThrowable($e) as $where => $output) {
                self::assertStringNotContainsString(self::OTHER, $output, $where);
                self::assertStringNotContainsString(self::OURS, $output, $where);
            }
            self::assertStringContainsString('**************7771', $e->getMessage());
            self::assertStringContainsString('Config::$allowedClabes', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidAllowlistEntries(): iterable
    {
        yield 'wrong check digit' => ['032180000118359710'];
        yield 'too short' => ['0321800001183597'];
        yield 'letters' => ['03218000011835971X'];
    }

    #[DataProvider('provideInvalidAllowlistEntries')]
    public function testTheAllowlistMustContainValidClabes(string $entry): void
    {
        try {
            new BankAccountGuard([self::OURS, $entry]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('allowedClabes[1]', $e->getMessage());
            self::assertStringNotContainsString($entry, $e->getMessage());
        }
    }
}
