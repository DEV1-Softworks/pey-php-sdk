<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Contract;

use Dev1\Pey\Webhooks\Event\BankAccountUpdated;
use Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Event classes against the documented webhook examples in
 * tests/Fixtures/webhooks/ (the spec's `webhooks` object is empty, so the
 * docs pages are the contract).
 */
#[CoversNothing]
final class WebhookContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function provideEvents(): iterable
    {
        yield 'codi.qr.payment_received' => ['codi.qr.payment_received', CodiQrPaymentReceived::class];
        yield 'codi.pn.payment_received' => ['codi.pn.payment_received', CodiPushNotificationPaymentReceived::class];
        yield 'payment_link.status_updated' => ['payment_link.status_updated', PaymentLinkStatusUpdated::class];
        yield 'bank.account.updated' => ['bank.account.updated', BankAccountUpdated::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideEvents')]
    public function testEventPropertiesMatchTheDocumentedPayload(string $type, string $class): void
    {
        $contents = file_get_contents(\dirname(__DIR__) . '/Fixtures/webhooks/' . $type . '.json');
        self::assertIsString($contents);
        $example = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($example);

        self::assertSame($type, $example['type'] ?? null);
        self::assertSame($type, \constant($class . '::TYPE'));

        $documented = array_values(array_diff(array_map('strval', array_keys($example)), ['type']));
        $properties = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        sort($documented);
        sort($properties);

        self::assertSame($documented, $properties);
    }
}
