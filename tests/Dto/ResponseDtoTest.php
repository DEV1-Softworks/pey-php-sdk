<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Dto;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Response\AmountBreakdown;
use Dev1\Pey\Dto\Response\BankAccount;
use Dev1\Pey\Dto\Response\BankAccountRegistration;
use Dev1\Pey\Dto\Response\CreatedPaymentLink;
use Dev1\Pey\Dto\Response\PaymentLink;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Dto\Response\TransactionSummary;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Money\Amount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QrCode::class)]
#[CoversClass(PushNotification::class)]
#[CoversClass(CreatedPaymentLink::class)]
#[CoversClass(BankAccount::class)]
#[CoversClass(BankAccountRegistration::class)]
#[CoversClass(PaymentLink::class)]
#[CoversClass(Transaction::class)]
#[CoversClass(TransactionSummary::class)]
#[CoversClass(TransactionPage::class)]
#[CoversClass(TransactionMetadata::class)]
#[CoversClass(AmountBreakdown::class)]
#[UsesClass(Payload::class)]
#[UsesClass(Json::class)]
#[UsesClass(Amount::class)]
#[UsesClass(CodiStatus::class)]
#[UsesClass(Entity::class)]
#[UsesClass(PayableType::class)]
#[UsesClass(PaymentLinkStatus::class)]
#[UsesClass(PaymentMethod::class)]
#[UsesClass(TransactionStatus::class)]
#[UsesClass(UnexpectedResponseException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class ResponseDtoTest extends TestCase
{
    public function testAQrWithoutImage(): void
    {
        $qr = QrCode::fromArray(Json::decode('{"id":"AB12CD34EF","qrString":"{\"TYP\":1}","qrImage":null}'));

        self::assertSame('AB12CD34EF', $qr->id);
        self::assertSame('{"TYP":1}', $qr->qrString);
        self::assertNull($qr->qrImage);
    }

    public function testAMissingRequiredFieldIsAnUnexpectedResponse(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('QrCode field "qrString" must be a string');

        QrCode::fromArray(Json::decode('{"id":"AB12CD34EF"}'));
    }

    public function testUnknownEnumValuesDoNotBreakParsing(): void
    {
        $registration = BankAccountRegistration::fromArray(Json::decode('{"id":"x","codiStatus":"SUSPENDED"}'));

        self::assertSame(CodiStatus::Unknown, $registration->codiStatus);
    }

    public function testABankAccountInError(): void
    {
        $account = BankAccount::fromArray(Json::decode(
            '{"id":"01JBAVWE4NS5TGXHWGMX3KH23Q","alias":"BBVA","name":"Persona","clabe":"012180001234567899","entity":"PERSON",'
            . '"codiStatus":"ERROR","errorMessage":"Bank does not support CoDi","dateCreated":1760000000}',
        ));

        self::assertSame(Entity::Person, $account->entity);
        self::assertSame(CodiStatus::Error, $account->codiStatus);
        self::assertSame('Bank does not support CoDi', $account->errorMessage);
        self::assertNull($account->taxId);
    }

    public function testAPendingTransactionWithoutOptionalFields(): void
    {
        $transaction = Transaction::fromArray(Json::decode(
            '{"id":"01M3QR8H0RH0XBCKYYD242YXNV","status":"PENDING","amount":150,"currency":"MXN","paymentMethod":"CODI","dateCreated":1704067200}',
        ));

        self::assertFalse($transaction->isPaid());
        self::assertSame(TransactionStatus::Pending, $transaction->status);
        self::assertNull($transaction->amountBreakdown);
        self::assertNull($transaction->payableType);
        self::assertNull($transaction->payableId);
        self::assertNull($transaction->description);
        self::assertNull($transaction->applicationFee);
        self::assertNull($transaction->datePaid);
        self::assertEquals(new TransactionMetadata(), $transaction->metadata);
    }

    public function testAnUnknownTransactionStatusIsNeverPaid(): void
    {
        $transaction = Transaction::fromArray(Json::decode(
            '{"id":"x","status":"REFUNDED","amount":1,"currency":"MXN","paymentMethod":"WIRE","payableType":"SUBSCRIPTION","dateCreated":1}',
        ));

        self::assertSame(TransactionStatus::Unknown, $transaction->status);
        self::assertFalse($transaction->isPaid());
        self::assertSame(PaymentMethod::Unknown, $transaction->paymentMethod);
        self::assertSame(PayableType::Unknown, $transaction->payableType);
    }

    public function testMissingBreakdownComponentsAreZero(): void
    {
        $breakdown = AmountBreakdown::fromPayload(new Payload(Json::decode('{"base":100}'), 'Transaction'));

        self::assertSame('100.00', $breakdown->base->toDecimalString());
        self::assertTrue($breakdown->tip->isZero());
        self::assertTrue($breakdown->fee->isZero());
        self::assertSame('100.00', $breakdown->total()->toDecimalString());
    }

    public function testInvalidNestedFieldsReportTheirPath(): void
    {
        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('TransactionPage field "items[1].amount" must be a decimal amount');

        TransactionPage::fromArray(Json::decode(
            '{"items":[{"id":"a","status":"PAID","amount":1,"currency":"MXN","paymentMethod":"CODI","dateCreated":1},'
            . '{"id":"b","status":"PAID","currency":"MXN","paymentMethod":"CODI","dateCreated":1}],"nextToken":null}',
        ));
    }

    public function testTheLastPageHasNoMore(): void
    {
        self::assertFalse(TransactionPage::fromArray(Json::decode('{"items":[],"nextToken":null}'))->hasMore());
        self::assertFalse(TransactionPage::fromArray(Json::decode('{"items":[],"nextToken":""}'))->hasMore());
        self::assertFalse(TransactionPage::fromArray(Json::decode('{}'))->hasMore());
        self::assertTrue(TransactionPage::fromArray(Json::decode('{"items":[],"nextToken":"abc"}'))->hasMore());
    }

    public function testAPaymentLinkWithoutOptionalFields(): void
    {
        $link = PaymentLink::fromArray(Json::decode(
            '{"id":"01KRFJJYPMP24MF3EFX1043RNC","amount":100,"status":"pending","successUrl":"https://a.example","backUrl":"https://b.example","hasTip":false,"dateCreated":1778639272}',
        ));

        self::assertSame(PaymentLinkStatus::Pending, $link->status);
        self::assertSame([], $link->paymentMethods);
        self::assertSame([], $link->transactions);
        self::assertSame([], $link->metadata);
        self::assertNull($link->clientReferenceId);
    }

    public function testATransactionSummaryWithANullApplicationFee(): void
    {
        $summary = TransactionSummary::fromPayload(new Payload(Json::decode(
            '{"transactionId":"01M3QR8H0RH0XBCKYYD242YXNV","paymentMethod":"CODI","datePaid":1715000000,"amount":153.50,"applicationFee":null}',
        ), 'PaymentLink'));

        self::assertNull($summary->applicationFee);
        self::assertNull($summary->amountBreakdown);
        self::assertSame('153.50', $summary->amount->toDecimalString());
        self::assertSame([], $summary->metadata->externalMetadata);
    }

    public function testReplayableResponsesRoundTripThroughToArray(): void
    {
        $qr = new QrCode('AB12CD34EF', '{"TYP":1}', 'iVBORw0KGgo=');
        $qrWithoutImage = new QrCode('AB12CD34EF', '{"TYP":1}');
        $push = new PushNotification('01M3QR5E7XHXMNZDEPFH3JAWS0', '12345678901234567890');
        $link = new CreatedPaymentLink('01KRFJJYPMP24MF3EFX1043RNC', 'https://app.mipey.mx/payment-link/01KRFJJYPMP24MF3EFX1043RNC');

        self::assertEquals($qr, QrCode::fromArray($qr->toArray()));
        self::assertEquals($qrWithoutImage, QrCode::fromArray($qrWithoutImage->toArray()));
        self::assertEquals($push, PushNotification::fromArray($push->toArray()));
        self::assertEquals($link, CreatedPaymentLink::fromArray($link->toArray()));
        self::assertSame(['id' => 'AB12CD34EF', 'qrString' => '{"TYP":1}', 'qrImage' => null], $qrWithoutImage->toArray());
    }

    public function testDtosCanBeBuiltDirectlyForTests(): void
    {
        $qr = new QrCode('AB12CD34EF', 'payload');

        self::assertNull($qr->qrImage);
    }
}
