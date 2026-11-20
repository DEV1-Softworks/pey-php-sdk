<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Contract;

use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Tests\Support\OpenApiSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Enum values against every enum the spec declares for the same field.
 */
#[CoversClass(CodiStatus::class)]
#[CoversClass(Entity::class)]
#[CoversClass(PayableType::class)]
#[CoversClass(PaymentLinkStatus::class)]
#[CoversClass(PaymentMethod::class)]
#[CoversClass(TransactionStatus::class)]
final class EnumContractTest extends TestCase
{
    /**
     * The field whose spec enum must equal the SDK enum exactly.
     *
     * @return iterable<string, array{class-string<\BackedEnum>, array<array-key, mixed>}>
     */
    public static function provideCanonicalEnums(): iterable
    {
        yield 'CodiStatus' => [CodiStatus::class, OpenApiSpec::property(OpenApiSpec::responseSchema('GET', '/bank-account/{id}', '200'), 'codiStatus')];
        yield 'Entity' => [Entity::class, OpenApiSpec::property(OpenApiSpec::requestSchema('POST', '/bank-account'), 'entity')];
        yield 'PaymentLinkStatus' => [PaymentLinkStatus::class, OpenApiSpec::property(OpenApiSpec::responseSchema('GET', '/payment-link/{id}', '200'), 'status')];
        yield 'TransactionStatus' => [TransactionStatus::class, OpenApiSpec::property(OpenApiSpec::responseSchema('GET', '/transaction/{id}', '200'), 'status')];
        yield 'PaymentMethod' => [PaymentMethod::class, OpenApiSpec::property(OpenApiSpec::responseSchema('GET', '/transaction/{id}', '200'), 'paymentMethod')];
        yield 'PayableType' => [PayableType::class, OpenApiSpec::property(OpenApiSpec::responseSchema('GET', '/transaction/{id}', '200'), 'payableType')];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param array<array-key, mixed>   $schema
     */
    #[DataProvider('provideCanonicalEnums')]
    public function testEnumCasesMatchTheSpec(string $enum, array $schema): void
    {
        self::assertSame(self::sorted(OpenApiSpec::enumValues($schema)), self::sorted(self::knownValues($enum)));
    }

    /**
     * Other places where the same field appears may document a subset.
     *
     * @return iterable<string, array{class-string<\BackedEnum>, array<array-key, mixed>}>
     */
    public static function provideOtherOccurrences(): iterable
    {
        yield 'codiStatus on registration' => [CodiStatus::class, OpenApiSpec::property(OpenApiSpec::responseSchema('POST', '/bank-account', '200'), 'codiStatus')];
        yield 'entity on bank account' => [Entity::class, OpenApiSpec::property(OpenApiSpec::responseSchema('GET', '/bank-account/{id}', '200'), 'entity')];

        foreach (['/transaction/api-key', '/transaction/bank-account/{id}', '/transaction/codi/qr/{id}'] as $path) {
            $item = OpenApiSpec::property(OpenApiSpec::responseSchema('GET', $path, '200'), 'items', '[]');
            yield 'status in ' . $path => [TransactionStatus::class, OpenApiSpec::property($item, 'status')];
            yield 'paymentMethod in ' . $path => [PaymentMethod::class, OpenApiSpec::property($item, 'paymentMethod')];
        }

        foreach (['/transaction/api-key', '/transaction/bank-account/{id}'] as $path) {
            $item = OpenApiSpec::property(OpenApiSpec::responseSchema('GET', $path, '200'), 'items', '[]');
            yield 'payableType in ' . $path => [PayableType::class, OpenApiSpec::property($item, 'payableType')];
        }
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param array<array-key, mixed>   $schema
     */
    #[DataProvider('provideOtherOccurrences')]
    public function testOtherOccurrencesAreCovered(string $enum, array $schema): void
    {
        self::assertSame([], array_values(array_diff(OpenApiSpec::enumValues($schema), self::knownValues($enum))));
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return list<string>
     */
    private static function knownValues(string $enum): array
    {
        $values = [];
        foreach ($enum::cases() as $case) {
            if ($case->value !== '') {
                $values[] = (string) $case->value;
            }
        }

        return $values;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
