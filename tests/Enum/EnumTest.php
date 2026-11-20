<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Enum;

use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodiStatus::class)]
#[CoversClass(Entity::class)]
#[CoversClass(PayableType::class)]
#[CoversClass(PaymentLinkStatus::class)]
#[CoversClass(PaymentMethod::class)]
#[CoversClass(TransactionStatus::class)]
final class EnumTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(string): \BackedEnum, string, \BackedEnum}>
     */
    public static function provideApiValues(): iterable
    {
        yield 'codi status' => [CodiStatus::fromApi(...), 'ACTIVE', CodiStatus::Active];
        yield 'entity' => [Entity::fromApi(...), 'PERSON', Entity::Person];
        yield 'payable type' => [PayableType::fromApi(...), 'API_CODI_QR', PayableType::CodiQr];
        yield 'payment link status' => [PaymentLinkStatus::fromApi(...), 'partial', PaymentLinkStatus::Partial];
        yield 'payment method' => [PaymentMethod::fromApi(...), 'CARD', PaymentMethod::Card];
        yield 'transaction status' => [TransactionStatus::fromApi(...), 'PAID', TransactionStatus::Paid];
        yield 'unknown codi status' => [CodiStatus::fromApi(...), 'SUSPENDED', CodiStatus::Unknown];
        yield 'unknown entity' => [Entity::fromApi(...), 'GOVERNMENT', Entity::Unknown];
        yield 'unknown payable type' => [PayableType::fromApi(...), 'SUBSCRIPTION', PayableType::Unknown];
        yield 'payment link status is case-sensitive' => [PaymentLinkStatus::fromApi(...), 'PAID', PaymentLinkStatus::Unknown];
        yield 'unknown payment method' => [PaymentMethod::fromApi(...), 'SPEI', PaymentMethod::Unknown];
        yield 'unknown transaction status' => [TransactionStatus::fromApi(...), 'REFUNDED', TransactionStatus::Unknown];
    }

    /**
     * @param \Closure(string): \BackedEnum $fromApi
     */
    #[DataProvider('provideApiValues')]
    public function testValuesFromPeyMapToCasesOrUnknown(\Closure $fromApi, string $value, \BackedEnum $expected): void
    {
        self::assertSame($expected, $fromApi($value));
    }
}
