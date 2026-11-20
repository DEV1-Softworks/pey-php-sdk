<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Contract;

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
use Dev1\Pey\Dto\Response\UpdatedPaymentLink;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Tests\Support\OpenApiSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Response DTOs against the response schemas and examples in tests/Fixtures/openapi.json.
 */
#[CoversClass(QrCode::class)]
#[CoversClass(PushNotification::class)]
#[CoversClass(BankAccountRegistration::class)]
#[CoversClass(BankAccount::class)]
#[CoversClass(CreatedPaymentLink::class)]
#[CoversClass(UpdatedPaymentLink::class)]
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
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class ResponseContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, class-string}>
     */
    public static function provideResponses(): iterable
    {
        yield 'POST /codi/qr' => ['POST', '/codi/qr', '201', QrCode::class];
        yield 'POST /codi/pn' => ['POST', '/codi/pn', '200', PushNotification::class];
        yield 'POST /bank-account' => ['POST', '/bank-account', '200', BankAccountRegistration::class];
        yield 'GET /bank-account/{id}' => ['GET', '/bank-account/{id}', '200', BankAccount::class];
        yield 'POST /payment-link/create' => ['POST', '/payment-link/create', '200', CreatedPaymentLink::class];
        yield 'POST /payment-link/update' => ['POST', '/payment-link/update', '200', UpdatedPaymentLink::class];
        yield 'GET /payment-link/{id}' => ['GET', '/payment-link/{id}', '200', PaymentLink::class];
        yield 'GET /transaction/{id}' => ['GET', '/transaction/{id}', '200', Transaction::class];
        yield 'GET /transaction/api-key' => ['GET', '/transaction/api-key', '200', TransactionPage::class];
        yield 'GET /transaction/bank-account/{id}' => ['GET', '/transaction/bank-account/{id}', '200', TransactionPage::class];
        yield 'GET /transaction/codi/qr/{id}' => ['GET', '/transaction/codi/qr/{id}', '200', TransactionPage::class];
    }

    /**
     * @param class-string $dto
     */
    #[DataProvider('provideResponses')]
    public function testTheSpecExampleHydrates(string $method, string $path, string $status, string $dto): void
    {
        $data = Json::decode(OpenApiSpec::responseExampleJson($method, $path, $status));

        $hydrate = [$dto, 'fromArray'];
        self::assertIsCallable($hydrate);
        self::assertInstanceOf($dto, $hydrate($data));
    }

    /**
     * DTO properties use the API's field names, so they must match the spec 1:1.
     *
     * @return iterable<string, array{class-string, string, string, string, list<string>}>
     */
    public static function provideObjectSchemas(): iterable
    {
        yield 'QrCode' => [QrCode::class, 'POST', '/codi/qr', '201', []];
        yield 'PushNotification' => [PushNotification::class, 'POST', '/codi/pn', '200', []];
        yield 'BankAccountRegistration' => [BankAccountRegistration::class, 'POST', '/bank-account', '200', []];
        yield 'BankAccount' => [BankAccount::class, 'GET', '/bank-account/{id}', '200', []];
        yield 'CreatedPaymentLink' => [CreatedPaymentLink::class, 'POST', '/payment-link/create', '200', []];
        yield 'UpdatedPaymentLink' => [UpdatedPaymentLink::class, 'POST', '/payment-link/update', '200', []];
        yield 'PaymentLink' => [PaymentLink::class, 'GET', '/payment-link/{id}', '200', []];
        yield 'TransactionSummary' => [TransactionSummary::class, 'GET', '/payment-link/{id}', '200', ['transactions', '[]']];
        yield 'Transaction' => [Transaction::class, 'GET', '/transaction/{id}', '200', []];
        yield 'AmountBreakdown' => [AmountBreakdown::class, 'GET', '/transaction/{id}', '200', ['amountBreakdown']];
        yield 'TransactionPage' => [TransactionPage::class, 'GET', '/transaction/api-key', '200', []];
    }

    /**
     * @param class-string $dto
     * @param list<string> $propertyPath
     */
    #[DataProvider('provideObjectSchemas')]
    public function testDtoPropertiesMatchTheSpec(string $dto, string $method, string $path, string $status, array $propertyPath): void
    {
        $schema = OpenApiSpec::property(OpenApiSpec::responseSchema($method, $path, $status), ...$propertyPath);
        $documented = OpenApiSpec::propertyNames($schema);
        $properties = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass($dto))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        sort($documented);
        sort($properties);

        self::assertSame($documented, $properties);
    }

    /**
     * Listing items are transactions; some listings document fewer fields.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideListings(): iterable
    {
        yield 'api-key' => ['/transaction/api-key'];
        yield 'bank-account' => ['/transaction/bank-account/{id}'];
        yield 'codi/qr' => ['/transaction/codi/qr/{id}'];
    }

    #[DataProvider('provideListings')]
    public function testListingItemsAreTransactions(string $path): void
    {
        $schema = OpenApiSpec::responseSchema('GET', $path, '200');
        $itemFields = OpenApiSpec::propertyNames(OpenApiSpec::property($schema, 'items', '[]'));
        $transactionFields = array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(Transaction::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        self::assertSame([], array_values(array_diff($itemFields, $transactionFields)));
        self::assertSame(['items', 'nextToken'], OpenApiSpec::propertyNames($schema));
    }

    public function testTheTransactionExampleMapsEveryField(): void
    {
        $transaction = Transaction::fromArray(Json::decode(OpenApiSpec::responseExampleJson('GET', '/transaction/{id}', '200')));

        self::assertSame('01HXYZTXN1234567890ABCDE00', $transaction->id);
        self::assertTrue($transaction->isPaid());
        self::assertSame('102.50', $transaction->amount->toDecimalString());
        self::assertNotNull($transaction->amountBreakdown);
        self::assertSame('100.00', $transaction->amountBreakdown->base->toDecimalString());
        self::assertSame('2.50', $transaction->amountBreakdown->fee->toDecimalString());
        self::assertTrue($transaction->amountBreakdown->total()->equals($transaction->amount));
        self::assertSame('MXN', $transaction->currency);
        self::assertSame(PaymentMethod::Codi, $transaction->paymentMethod);
        self::assertSame(PayableType::CodiPushNotification, $transaction->payableType);
        self::assertSame('7.50', $transaction->applicationFee?->toDecimalString());
        self::assertSame(1704067200, $transaction->dateCreated->getTimestamp());
        self::assertSame(1704067260, $transaction->datePaid?->getTimestamp());
        self::assertSame(40012, $transaction->metadata->bankCode);
        self::assertSame(40, $transaction->metadata->accountType);
        self::assertSame('0192191042', $transaction->metadata->trackingKey);
        self::assertSame(['orderId' => '12345', 'customerEmail' => 'customer@example.com'], $transaction->metadata->externalMetadata);
    }

    public function testThePaymentLinkExampleMapsEveryField(): void
    {
        $link = PaymentLink::fromArray(Json::decode(OpenApiSpec::responseExampleJson('GET', '/payment-link/{id}', '200')));

        self::assertSame(PaymentLinkStatus::Paid, $link->status);
        self::assertSame('100.00', $link->amount->toDecimalString());
        self::assertTrue($link->hasTip);
        self::assertSame([PaymentMethod::Codi, PaymentMethod::Card], $link->paymentMethods);
        self::assertSame('order-12345', $link->clientReferenceId);
        self::assertSame(['orderId' => '12345', 'customerEmail' => 'customer@example.com', 'isGift' => 'false'], $link->metadata);
        self::assertCount(1, $link->transactions);
        self::assertSame('118.50', $link->transactions[0]->amount->toDecimalString());
        self::assertSame('15.00', $link->transactions[0]->amountBreakdown?->tip->toDecimalString());
        self::assertSame('9.25', $link->transactions[0]->applicationFee?->toDecimalString());
        self::assertSame(PaymentMethod::Card, $link->transactions[0]->paymentMethod);
    }

    public function testTheQrTransactionsExampleHasNoPayable(): void
    {
        $page = TransactionPage::fromArray(Json::decode(OpenApiSpec::responseExampleJson('GET', '/transaction/codi/qr/{id}', '200')));

        self::assertCount(1, $page->items);
        self::assertNull($page->items[0]->payableType);
        self::assertNull($page->items[0]->payableId);
        self::assertTrue($page->hasMore());
    }

    public function testTheBankAccountExampleMapsEveryField(): void
    {
        $account = BankAccount::fromArray(Json::decode(OpenApiSpec::responseExampleJson('GET', '/bank-account/{id}', '200')));

        self::assertSame(Entity::Business, $account->entity);
        self::assertSame(CodiStatus::Active, $account->codiStatus);
        self::assertSame('XAXX010101000', $account->taxId);
        self::assertNull($account->errorMessage);
        self::assertSame(1760000000, $account->dateCreated->getTimestamp());
    }
}
