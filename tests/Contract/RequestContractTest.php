<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Contract;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Request\RequestBody;
use Dev1\Pey\Dto\Request\UpdatePaymentLinkRequest;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Tests\Support\JsonSchema;
use Dev1\Pey\Tests\Support\OpenApiSpec;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Request DTOs against the request schemas in tests/Fixtures/openapi.json.
 */
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
final class RequestContractTest extends TestCase
{
    private const ULID = '01JBAVWE4NS5TGXHWGMX3KH23Q';

    /**
     * Every documented field set, at valid boundary values.
     *
     * @return iterable<string, array{string, string, \Closure(): RequestBody}>
     */
    public static function provideFullRequests(): iterable
    {
        yield 'POST /codi/qr' => ['POST', '/codi/qr', static fn(): RequestBody => new CreateQrRequest(
            amount: '0',
            concept: 'Order #12345',
            expiration: 360,
            numRef: '1234567',
            generateImage: true,
            bankAccountId: self::ULID,
            metadata: self::metadataAtLimits(),
        )];
        yield 'POST /codi/pn' => ['POST', '/codi/pn', static fn(): RequestBody => new CreatePushNotificationRequest(
            amount: '1000000.00',
            phoneNumber: '5512345678',
            concept: 'Order #12345',
            expiration: 360,
            numRef: '0',
            bankAccountId: self::ULID,
            metadata: self::metadataAtLimits(),
        )];
        yield 'POST /bank-account' => ['POST', '/bank-account', static fn(): RequestBody => new RegisterBankAccountRequest(
            alias: str_repeat('a', 255),
            name: 'Pagos con Pey S.A. de C.V.',
            clabe: '012180001234567899',
            entity: Entity::Business,
        )];
        yield 'POST /payment-link/create' => ['POST', '/payment-link/create', static fn(): RequestBody => new CreatePaymentLinkRequest(
            amount: '0.01',
            successUrl: 'https://example.com/success',
            backUrl: 'https://example.com/cancel',
            hasTip: true,
            clientReferenceId: str_repeat('r', 255),
            metadata: self::metadataAtLimits(),
        )];
        yield 'POST /payment-link/update' => ['POST', '/payment-link/update', static fn(): RequestBody => new UpdatePaymentLinkRequest(
            paymentLinkId: self::ULID,
            amount: '150.005',
        )];
    }

    /**
     * Only the required fields.
     *
     * @return iterable<string, array{string, string, \Closure(): RequestBody}>
     */
    public static function provideMinimalRequests(): iterable
    {
        yield 'minimal POST /codi/qr' => ['POST', '/codi/qr', static fn(): RequestBody => new CreateQrRequest('150')];
        yield 'minimal POST /codi/pn' => ['POST', '/codi/pn', static fn(): RequestBody => new CreatePushNotificationRequest('150', '5512345678')];
        yield 'minimal POST /payment-link/create' => ['POST', '/payment-link/create', static fn(): RequestBody => new CreatePaymentLinkRequest(
            '100',
            'https://example.com/success',
            'https://example.com/cancel',
        )];
    }

    /**
     * @param \Closure(): RequestBody $build
     */
    #[DataProvider('provideFullRequests')]
    #[DataProvider('provideMinimalRequests')]
    public function testTheBodyMatchesTheSpecSchema(string $method, string $path, \Closure $build): void
    {
        $wire = json_decode(Json::encodeObject($build()->toBody()), false, 512, \JSON_THROW_ON_ERROR);

        self::assertSame([], JsonSchema::validate($wire, OpenApiSpec::requestSchema($method, $path)));
    }

    /**
     * @param \Closure(): RequestBody $build
     */
    #[DataProvider('provideFullRequests')]
    public function testEveryDocumentedFieldIsSupportedAndNothingElseIsSent(string $method, string $path, \Closure $build): void
    {
        $sent = array_keys($build()->toBody());
        $documented = OpenApiSpec::propertyNames(OpenApiSpec::requestSchema($method, $path));
        sort($sent);
        sort($documented);

        self::assertSame($documented, $sent);
    }

    /**
     * Inputs the spec forbids are rejected locally, before anything is sent.
     *
     * @return iterable<string, array{string, string, \Closure(): RequestBody, array<string, mixed>}>
     */
    public static function provideInputsTheSpecForbids(): iterable
    {
        yield 'QR numRef with 8 digits' => ['POST', '/codi/qr', static fn(): RequestBody => new CreateQrRequest('1', numRef: '12345678'), ['amount' => 1, 'numRef' => '12345678']];
        yield 'QR metadata with 51 pairs' => ['POST', '/codi/qr', static fn(): RequestBody => new CreateQrRequest('1', metadata: array_fill_keys(range(1, 51), 'v')), ['amount' => 1, 'metadata' => array_fill_keys(array_map(static fn(int $i): string => 'k' . $i, range(1, 51)), 'v')]];
        yield 'push amount of zero' => ['POST', '/codi/pn', static fn(): RequestBody => new CreatePushNotificationRequest('0', '5512345678'), ['amount' => 0, 'phoneNumber' => '5512345678']];
        yield 'push amount over 1,000,000' => ['POST', '/codi/pn', static fn(): RequestBody => new CreatePushNotificationRequest('1000000.01', '5512345678'), ['amount' => 1000000.01, 'phoneNumber' => '5512345678']];
        yield 'push phone with 9 digits' => ['POST', '/codi/pn', static fn(): RequestBody => new CreatePushNotificationRequest('1', '551234567'), ['amount' => 1, 'phoneNumber' => '551234567']];
        yield 'push expiration below 360' => ['POST', '/codi/pn', static fn(): RequestBody => new CreatePushNotificationRequest('1', '5512345678', expiration: 0), ['amount' => 1, 'phoneNumber' => '5512345678', 'expiration' => 0]];
        yield 'CLABE with 17 digits' => ['POST', '/bank-account', static fn(): RequestBody => new RegisterBankAccountRequest('a', 'n', '01218000123456789', Entity::Person), ['alias' => 'a', 'name' => 'n', 'clabe' => '01218000123456789', 'entity' => 'PERSON']];
        yield 'empty alias' => ['POST', '/bank-account', static fn(): RequestBody => new RegisterBankAccountRequest('', 'n', '012180001234567899', Entity::Person), ['alias' => '', 'name' => 'n', 'clabe' => '012180001234567899', 'entity' => 'PERSON']];
        yield 'unknown entity' => ['POST', '/bank-account', static fn(): RequestBody => new RegisterBankAccountRequest('a', 'n', '012180001234567899', Entity::Unknown), ['alias' => 'a', 'name' => 'n', 'clabe' => '012180001234567899', 'entity' => '']];
        yield 'payment link amount of zero' => ['POST', '/payment-link/create', static fn(): RequestBody => new CreatePaymentLinkRequest('0', 'https://a.example', 'https://b.example'), ['amount' => 0, 'successUrl' => 'https://a.example', 'backUrl' => 'https://b.example']];
        yield 'reference over 255 characters' => ['POST', '/payment-link/create', static fn(): RequestBody => new CreatePaymentLinkRequest('1', 'https://a.example', 'https://b.example', clientReferenceId: str_repeat('r', 256)), ['amount' => 1, 'successUrl' => 'https://a.example', 'backUrl' => 'https://b.example', 'clientReferenceId' => str_repeat('r', 256)]];
        yield 'update amount of zero' => ['POST', '/payment-link/update', static fn(): RequestBody => new UpdatePaymentLinkRequest(self::ULID, '0'), ['paymentLinkId' => self::ULID, 'amount' => 0]];
    }

    /**
     * @param \Closure(): RequestBody $build
     * @param array<string, mixed>    $equivalentBody
     */
    #[DataProvider('provideInputsTheSpecForbids')]
    public function testInputsTheSpecForbidsAreRejectedLocally(string $method, string $path, \Closure $build, array $equivalentBody): void
    {
        $wire = json_decode(json_encode($equivalentBody, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        self::assertNotSame([], JsonSchema::validate($wire, OpenApiSpec::requestSchema($method, $path)), 'The spec should reject this body.');

        $this->expectException(ValidationException::class);
        $build();
    }

    /**
     * Rules Pey states only in field descriptions, not in the schema. The
     * schema accepts these bodies; the SDK rejects them locally because Pey
     * documents that it does.
     *
     * @return iterable<string, array{string, string, \Closure(): RequestBody, array<string, mixed>}>
     */
    public static function provideRulesStatedOnlyInDescriptions(): iterable
    {
        yield 'QR expiration between 0 and 360' => ['POST', '/codi/qr', static fn(): RequestBody => new CreateQrRequest('1', expiration: 359), ['amount' => 1, 'expiration' => 359]];
        yield 'CLABE with a wrong check digit' => ['POST', '/bank-account', static fn(): RequestBody => new RegisterBankAccountRequest('a', 'n', '012180001234567890', Entity::Person), ['alias' => 'a', 'name' => 'n', 'clabe' => '012180001234567890', 'entity' => 'PERSON']];
        yield 'plain http success URL' => ['POST', '/payment-link/create', static fn(): RequestBody => new CreatePaymentLinkRequest('1', 'http://a.example', 'https://b.example'), ['amount' => 1, 'successUrl' => 'http://a.example', 'backUrl' => 'https://b.example']];
        yield 'bankAccountId that is not a ULID' => ['POST', '/codi/qr', static fn(): RequestBody => new CreateQrRequest('1', bankAccountId: 'not-a-ulid'), ['amount' => 1, 'bankAccountId' => 'not-a-ulid']];
    }

    /**
     * @param \Closure(): RequestBody $build
     * @param array<string, mixed>    $equivalentBody
     */
    #[DataProvider('provideRulesStatedOnlyInDescriptions')]
    public function testRulesStatedOnlyInDescriptionsAreStillEnforced(string $method, string $path, \Closure $build, array $equivalentBody): void
    {
        $wire = json_decode(json_encode($equivalentBody, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], JsonSchema::validate($wire, OpenApiSpec::requestSchema($method, $path)), 'The schema itself accepts this body.');

        $this->expectException(ValidationException::class);
        $build();
    }

    /**
     * @return array<string, string>
     */
    private static function metadataAtLimits(): array
    {
        $metadata = [];
        for ($i = 1; $i < 50; ++$i) {
            $metadata['key' . $i] = 'value';
        }
        $metadata[str_repeat('k', 40)] = str_repeat('v', 500);

        return $metadata;
    }
}
