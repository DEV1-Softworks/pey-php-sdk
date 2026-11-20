<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Webhooks;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Response\AmountBreakdown;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Dto\Response\TransactionSummary;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Webhooks\Event\BankAccountUpdated;
use Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated;
use Dev1\Pey\Webhooks\Event\UnknownEvent;
use Dev1\Pey\Webhooks\EventFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventFactory::class)]
#[CoversClass(CodiQrPaymentReceived::class)]
#[CoversClass(CodiPushNotificationPaymentReceived::class)]
#[CoversClass(PaymentLinkStatusUpdated::class)]
#[CoversClass(BankAccountUpdated::class)]
#[CoversClass(UnknownEvent::class)]
#[UsesClass(Payload::class)]
#[UsesClass(Json::class)]
#[UsesClass(Amount::class)]
#[UsesClass(TransactionMetadata::class)]
#[UsesClass(TransactionSummary::class)]
#[UsesClass(AmountBreakdown::class)]
#[UsesClass(PaymentLinkStatus::class)]
#[UsesClass(PaymentMethod::class)]
#[UsesClass(CodiStatus::class)]
#[UsesClass(Entity::class)]
#[UsesClass(UnexpectedResponseException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class EventFactoryTest extends TestCase
{
    private static function fixture(string $type): string
    {
        $contents = file_get_contents(\dirname(__DIR__) . '/Fixtures/webhooks/' . $type . '.json');
        self::assertIsString($contents);

        return $contents;
    }

    public function testAQrPaymentFromTheDocs(): void
    {
        $event = EventFactory::fromVerifiedBody(self::fixture('codi.qr.payment_received'));

        self::assertInstanceOf(CodiQrPaymentReceived::class, $event);
        self::assertSame('codi.qr.payment_received', $event->type());
        self::assertSame('150.00', $event->amount->toDecimalString());
        self::assertSame('01M3QMMR6X9CBVDY1SF3G8QWNW', $event->apiKeyId);
        self::assertSame('01JBAVWE4NS5TGXHWGMX3KH23Q', $event->bankAccountId);
        self::assertSame('35a13c183a', $event->codiQrId);
        self::assertSame('(dev) Order 345', $event->concept);
        self::assertSame(1790724818, $event->datePaid->getTimestamp());
        self::assertSame('34567', $event->numRef);
        self::assertSame('01M3QR8H0RH0XBCKYYD242YXNV', $event->transactionId);
        self::assertSame(40012, $event->metadata->bankCode);
        self::assertSame(['orderId' => '12345', 'customerEmail' => 'customer@example.com'], $event->metadata->externalMetadata);
    }

    public function testAPushNotificationPaymentFromTheDocs(): void
    {
        $event = EventFactory::fromVerifiedBody(self::fixture('codi.pn.payment_received'));

        self::assertInstanceOf(CodiPushNotificationPaymentReceived::class, $event);
        self::assertSame('codi.pn.payment_received', $event->type());
        self::assertSame('100.00', $event->amount->toDecimalString());
        self::assertSame('01M3QR5E7XHXMNZDEPFH3JAWS0', $event->transactionId);
        self::assertSame('4567', $event->numRef);
    }

    public function testAPaymentLinkUpdateFromTheDocs(): void
    {
        $event = EventFactory::fromVerifiedBody(self::fixture('payment_link.status_updated'));

        self::assertInstanceOf(PaymentLinkStatusUpdated::class, $event);
        self::assertSame('payment_link.status_updated', $event->type());
        self::assertSame(PaymentLinkStatus::Paid, $event->status);
        self::assertSame('order-12345', $event->clientReferenceId);
        self::assertCount(1, $event->transactions);
        self::assertArrayHasKey(0, $event->transactions);
        self::assertSame('153.50', $event->transactions[0]->amount->toDecimalString());
        self::assertSame('100.00', $event->transactions[0]->amountBreakdown?->base->toDecimalString());
        self::assertSame('11.00', $event->transactions[0]->applicationFee?->toDecimalString());
    }

    public function testABankAccountUpdateFromTheDocs(): void
    {
        $event = EventFactory::fromVerifiedBody(self::fixture('bank.account.updated'));

        self::assertInstanceOf(BankAccountUpdated::class, $event);
        self::assertSame('bank.account.updated', $event->type());
        self::assertSame(CodiStatus::Active, $event->codiStatus);
        self::assertSame(Entity::Business, $event->entity);
        self::assertSame('API_KEY', $event->ownerType);
        self::assertSame('XAXX010101000', $event->taxId);
    }

    public function testOptionalFieldsMayBeAbsent(): void
    {
        $event = EventFactory::fromVerifiedBody(
            '{"type":"codi.pn.payment_received","amount":1,"apiKeyId":"k","datePaid":1,"transactionId":"t"}',
        );

        self::assertInstanceOf(CodiPushNotificationPaymentReceived::class, $event);
        self::assertNull($event->bankAccountId);
        self::assertNull($event->concept);
        self::assertNull($event->numRef);
        self::assertEquals(new TransactionMetadata(), $event->metadata);
    }

    public function testAnUnknownTypeIsKeptAsAnUnknownEvent(): void
    {
        $event = EventFactory::fromVerifiedBody('{"type":"payment.refunded","amount":12.50,"transactionId":"t"}');

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame('payment.refunded', $event->type());
        self::assertSame(['type' => 'payment.refunded', 'amount' => '12.50', 'transactionId' => 't'], $event->payload);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMalformedBodies(): iterable
    {
        yield 'not JSON' => ['nope', 'WebhookEvent field "body" must be a JSON object'];
        yield 'JSON list' => ['[{"type":"x"}]', 'WebhookEvent field "body" must be a JSON object'];
        yield 'no type' => ['{"amount":1}', 'WebhookEvent field "type" must be a string'];
        yield 'type is not a string' => ['{"type":true}', 'WebhookEvent field "type" must be a string'];
        yield 'missing amount' => ['{"type":"codi.qr.payment_received","apiKeyId":"k","codiQrId":"q","datePaid":1,"transactionId":"t"}', 'CodiQrPaymentReceived field "amount"'];
        yield 'negative amount' => ['{"type":"codi.pn.payment_received","amount":-1,"apiKeyId":"k","datePaid":1,"transactionId":"t"}', 'CodiPushNotificationPaymentReceived field "amount"'];
        yield 'bad transaction in a link' => ['{"type":"payment_link.status_updated","apiKeyId":"k","paymentLinkId":"p","status":"paid","transactions":[{"paymentMethod":"CARD"}]}', 'PaymentLinkStatusUpdated field "transactions[0].transactionId"'];
        yield 'bank account without CLABE' => ['{"type":"bank.account.updated","bankAccountId":"b","name":"n","ownerType":"API_KEY","ownerId":"o","codiStatus":"ACTIVE","entity":"PERSON"}', 'BankAccountUpdated field "clabe"'];
    }

    #[DataProvider('provideMalformedBodies')]
    public function testMalformedBodiesAreRejectedWithTheirPath(string $body, string $message): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage($message);

        EventFactory::fromVerifiedBody($body);
    }

    public function testANumericTypeIsAnUnknownEvent(): void
    {
        // JSON numbers decode as decimal strings, so a numeric type is just an unknown one.
        $event = EventFactory::fromVerifiedBody('{"type":5}');

        self::assertInstanceOf(UnknownEvent::class, $event);
        self::assertSame('5', $event->type());
    }

    public function testUnknownEnumValuesDoNotBreakParsing(): void
    {
        $event = EventFactory::fromVerifiedBody('{"type":"bank.account.updated","bankAccountId":"b","name":"n","ownerType":"CUSTOMER","ownerId":"o","codiStatus":"SUSPENDED","clabe":"c","entity":"GOVERNMENT"}');

        self::assertInstanceOf(BankAccountUpdated::class, $event);
        self::assertSame(CodiStatus::Unknown, $event->codiStatus);
        self::assertSame(Entity::Unknown, $event->entity);
        self::assertSame('CUSTOMER', $event->ownerType);
    }
}
