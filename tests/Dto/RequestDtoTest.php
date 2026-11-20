<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Dto;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Request\UpdatePaymentLinkRequest;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Clabe;
use Dev1\Pey\Validation\Expiration;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\NumRef;
use Dev1\Pey\Validation\PhoneNumber;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Url;
use Dev1\Pey\Validation\Violations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateQrRequest::class)]
#[CoversClass(CreatePushNotificationRequest::class)]
#[CoversClass(RegisterBankAccountRequest::class)]
#[CoversClass(CreatePaymentLinkRequest::class)]
#[CoversClass(UpdatePaymentLinkRequest::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Json::class)]
#[UsesClass(JsonNumber::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(Violations::class)]
#[UsesClass(AmountRules::class)]
#[UsesClass(Clabe::class)]
#[UsesClass(Expiration::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(NumRef::class)]
#[UsesClass(PhoneNumber::class)]
#[UsesClass(Text::class)]
#[UsesClass(Ulid::class)]
#[UsesClass(Url::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(PeyException::class)]
final class RequestDtoTest extends TestCase
{
    private const ULID = '01JBAVWE4NS5TGXHWGMX3KH23Q';

    public function testAQrBodyOmitsUnsetFieldsAndSendsAmountsExactly(): void
    {
        $request = new CreateQrRequest(Amount::fromString('150'));

        self::assertSame('{"amount":150.00,"generateImage":false}', Json::encodeObject($request->toBody()));
    }

    public function testAQrBodyCarriesEveryField(): void
    {
        $request = new CreateQrRequest(
            amount: '150.5',
            concept: 'Order #12345',
            expiration: 600,
            numRef: '0001234',
            generateImage: true,
            bankAccountId: self::ULID,
            metadata: ['orderId' => '12345', 'gift' => false],
        );

        self::assertSame(
            '{"amount":150.50,"concept":"Order #12345","expiration":600,"numRef":"0001234","generateImage":true,'
            . '"bankAccountId":"01JBAVWE4NS5TGXHWGMX3KH23Q","metadata":{"orderId":"12345","gift":false}}',
            Json::encodeObject($request->toBody()),
        );
        self::assertSame('150.50', $request->amount->toDecimalString());
    }

    public function testAZeroQrLetsThePayerTypeTheAmount(): void
    {
        self::assertTrue((new CreateQrRequest('0'))->amount->isZero());
    }

    public function testListLikeMetadataIsStillSentAsAnObject(): void
    {
        // PHP turns the numeric string key "0" into an integer, making this a list.
        $request = new CreateQrRequest('1', metadata: ['0' => 'first', '1' => 'second']);

        self::assertSame('{"amount":1.00,"generateImage":false,"metadata":{"0":"first","1":"second"}}', Json::encodeObject($request->toBody()));
    }

    public function testEveryInvalidQrFieldIsReportedAtOnce(): void
    {
        try {
            new CreateQrRequest('1', concept: "\xB1", expiration: 60, numRef: '12345678', bankAccountId: 'nope', metadata: ['k' => ['nested']]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['concept', 'expiration', 'numRef', 'bankAccountId', 'metadata.k'], array_keys($e->errors()));
            self::assertFalse($e->wasRejectedByPey());
        }
    }

    public function testAnUnparseableAmountIsRejectedImmediately(): void
    {
        $this->expectException(ValidationException::class);

        new CreateQrRequest('1,000.00');
    }

    public function testAPushNotificationBody(): void
    {
        $request = new CreatePushNotificationRequest('150', '5512345678', concept: 'Order #12345', expiration: 360, numRef: '1234567', bankAccountId: self::ULID, metadata: ['a' => 1]);

        self::assertSame(
            '{"amount":150.00,"phoneNumber":"5512345678","concept":"Order #12345","expiration":360,"numRef":"1234567",'
            . '"bankAccountId":"01JBAVWE4NS5TGXHWGMX3KH23Q","metadata":{"a":1}}',
            Json::encodeObject($request->toBody()),
        );
    }

    public function testAMinimalPushNotificationBody(): void
    {
        self::assertSame(
            '{"amount":0.01,"phoneNumber":"5512345678"}',
            Json::encodeObject((new CreatePushNotificationRequest(Amount::fromCentavos(1), '5512345678'))->toBody()),
        );
    }

    public function testEveryInvalidPushNotificationFieldIsReportedAtOnce(): void
    {
        try {
            new CreatePushNotificationRequest('0', '+525512345678', concept: "\xB1", expiration: 0, numRef: 'x', bankAccountId: 'AB12CD34EF', metadata: array_fill(0, 51, 'v'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['amount', 'phoneNumber', 'concept', 'expiration', 'numRef', 'bankAccountId', 'metadata'], array_keys($e->errors()));
        }
    }

    public function testABankAccountRegistrationBody(): void
    {
        $request = new RegisterBankAccountRequest('BBVA', 'Pagos con Pey S.A. de C.V.', '012180001234567899', Entity::Business);

        self::assertSame(
            ['alias' => 'BBVA', 'name' => 'Pagos con Pey S.A. de C.V.', 'clabe' => '012180001234567899', 'entity' => 'BUSINESS'],
            $request->toBody(),
        );
    }

    public function testEveryInvalidBankAccountFieldIsReportedAtOnce(): void
    {
        try {
            new RegisterBankAccountRequest('', str_repeat('n', 256), '012180001234567890', Entity::Unknown);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame([
                'alias' => ['must be between 1 and 255 characters.'],
                'name' => ['must be between 1 and 255 characters.'],
                'clabe' => ['has an invalid check digit.'],
                'entity' => ['must be BUSINESS or PERSON.'],
            ], $e->errors());
            self::assertStringNotContainsString('012180001234567890', $e->getMessage());
        }
    }

    public function testAPaymentLinkBody(): void
    {
        $request = new CreatePaymentLinkRequest('100', 'https://example.com/success', 'https://example.com/cancel', hasTip: true, clientReferenceId: 'order-12345', metadata: ['orderId' => '12345']);

        self::assertSame(
            '{"amount":100.00,"successUrl":"https://example.com/success","backUrl":"https://example.com/cancel","hasTip":true,'
            . '"clientReferenceId":"order-12345","metadata":{"orderId":"12345"}}',
            Json::encodeObject($request->toBody()),
        );
    }

    public function testAMinimalPaymentLinkBody(): void
    {
        self::assertSame(
            '{"amount":100.00,"successUrl":"https://a.example","backUrl":"https://b.example","hasTip":false}',
            Json::encodeObject((new CreatePaymentLinkRequest('100', 'https://a.example', 'https://b.example'))->toBody()),
        );
    }

    public function testEveryInvalidPaymentLinkFieldIsReportedAtOnce(): void
    {
        try {
            new CreatePaymentLinkRequest('0', 'http://a.example', 'ftp://b.example', clientReferenceId: '', metadata: [str_repeat('k', 41) => 'v']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['amount', 'successUrl', 'backUrl', 'clientReferenceId', 'metadata'], array_keys($e->errors()));
        }
    }

    public function testAPaymentLinkUpdateSendsOnlyTheTwoAllowedFields(): void
    {
        $request = new UpdatePaymentLinkRequest(self::ULID, '150.005');

        self::assertSame('{"paymentLinkId":"01JBAVWE4NS5TGXHWGMX3KH23Q","amount":150.01}', Json::encodeObject($request->toBody()));
    }

    public function testAnInvalidPaymentLinkUpdateIsRejected(): void
    {
        try {
            new UpdatePaymentLinkRequest('01HXYZ1234567890ABCDEFGH', '0');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['paymentLinkId', 'amount'], array_keys($e->errors()));
        }
    }
}
