<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Security;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\SecretExposureException;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\Dumps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Secret::class)]
#[CoversClass(SecretExposureException::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ConfigurationException::class)]
final class SecretTest extends TestCase
{
    private const VALUE = 'pey_test_not_a_real_value';

    public function testItRevealsTheOriginalValue(): void
    {
        self::assertSame(self::VALUE, (new Secret(self::VALUE))->reveal());
    }

    public function testEachInstanceKeepsItsOwnValue(): void
    {
        $first = new Secret('first-value');
        $second = new Secret('second-value');

        self::assertSame('first-value', $first->reveal());
        self::assertSame('second-value', $second->reveal());
    }

    public function testItRejectsAnEmptyValue(): void
    {
        $this->expectException(ConfigurationException::class);

        new Secret('');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideDumpMechanisms(): iterable
    {
        return Dumps::mechanisms();
    }

    #[DataProvider('provideDumpMechanisms')]
    public function testTheValueNeverAppearsInDumps(string $mechanism): void
    {
        $output = Dumps::via($mechanism, new Secret(self::VALUE));

        self::assertStringNotContainsString(self::VALUE, $output);
    }

    public function testDebugOutputShowsARedactionMarker(): void
    {
        self::assertSame(['value' => '[REDACTED]'], (new Secret(self::VALUE))->__debugInfo());
        self::assertStringContainsString('[REDACTED]', print_r(new Secret(self::VALUE), true));
    }

    public function testStringConversionIsRedacted(): void
    {
        self::assertSame('[REDACTED]', (string) new Secret(self::VALUE));
        self::assertSame('"[REDACTED]"', json_encode(new Secret(self::VALUE)));
    }

    public function testItCannotBeSerialized(): void
    {
        $this->expectException(SecretExposureException::class);

        serialize(new Secret(self::VALUE));
    }

    public function testACraftedPayloadCannotBeUnserialized(): void
    {
        $this->expectException(SecretExposureException::class);

        unserialize('O:' . \strlen(Secret::class) . ':"' . Secret::class . '":1:{s:5:"value";s:3:"abc";}');
    }

    public function testItCannotBeCloned(): void
    {
        $secret = new Secret(self::VALUE);

        $this->expectException(SecretExposureException::class);

        self::assertNotSame($secret, clone $secret);
    }

    public function testTheConstructorParameterIsMarkedSensitive(): void
    {
        $parameter = new \ReflectionParameter([Secret::class, '__construct'], 'value');

        self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testTheValueDoesNotAppearInTracesOfExceptionsThrownWhileBuildingIt(): void
    {
        try {
            new Secret('');
            self::fail('Expected an exception.');
        } catch (ConfigurationException $e) {
            // The empty value is not interesting; what matters is that the
            // argument is replaced by SensitiveParameterValue in the trace.
            self::assertStringContainsString('SensitiveParameterValue', print_r($e->getTrace(), true));
        }
    }
}
