<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Resources;

use Dev1\Pey\Config;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\UpdatePaymentLinkRequest;
use Dev1\Pey\Dto\Response\AmountBreakdown;
use Dev1\Pey\Dto\Response\CreatedPaymentLink;
use Dev1\Pey\Dto\Response\PaymentLink;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Dto\Response\TransactionSummary;
use Dev1\Pey\Dto\Response\UpdatedPaymentLink;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\ConflictException;
use Dev1\Pey\Exception\ForbiddenException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Resources\PaymentLinks;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\FakePsr18Exceptions;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Url;
use Dev1\Pey\Validation\Violations;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaymentLinks::class)]
#[UsesClass(Config::class)]
#[UsesClass(Secret::class)]
#[UsesClass(Transport::class)]
#[UsesClass(Json::class)]
#[UsesClass(JsonNumber::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(HeaderRedactor::class)]
#[UsesClass(ApiError::class)]
#[UsesClass(Payload::class)]
#[UsesClass(Amount::class)]
#[UsesClass(CreatePaymentLinkRequest::class)]
#[UsesClass(UpdatePaymentLinkRequest::class)]
#[UsesClass(CreatedPaymentLink::class)]
#[UsesClass(UpdatedPaymentLink::class)]
#[UsesClass(PaymentLink::class)]
#[UsesClass(TransactionSummary::class)]
#[UsesClass(TransactionMetadata::class)]
#[UsesClass(AmountBreakdown::class)]
#[UsesClass(PaymentLinkStatus::class)]
#[UsesClass(PaymentMethod::class)]
#[UsesClass(AmountRules::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(Text::class)]
#[UsesClass(Ulid::class)]
#[UsesClass(Url::class)]
#[UsesClass(Violations::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ConflictException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(NetworkException::class)]
final class PaymentLinksTest extends TestCase
{
    private const ID = '01KRFJJYPMP24MF3EFX1043RNC';

    private MockHttpClient $http;

    private PaymentLinks $paymentLinks;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $factory = new Psr17Factory();
        $this->paymentLinks = new PaymentLinks(new Transport(new Config('pey_test_not_a_real_value'), $this->http, $factory, $factory));
    }

    public function testCreatePostsTheLinkAndReturnsItsUrl(): void
    {
        $this->http->willRespond(200, '{"id":"01KRFJJYPMP24MF3EFX1043RNC","url":"https://app.mipey.mx/payment-link/01KRFJJYPMP24MF3EFX1043RNC"}');

        $created = $this->paymentLinks->create(new CreatePaymentLinkRequest(
            amount: '100.00',
            successUrl: 'https://example.com/success',
            backUrl: 'https://example.com/cancel',
            clientReferenceId: 'order-12345',
            metadata: ['orderId' => '12345'],
        ));

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.pey.mx/payment-link/create', (string) $request->getUri());
        self::assertSame(
            '{"amount":100.00,"successUrl":"https://example.com/success","backUrl":"https://example.com/cancel","hasTip":false,"clientReferenceId":"order-12345","metadata":{"orderId":"12345"}}',
            (string) $request->getBody(),
        );
        self::assertSame(self::ID, $created->id);
        self::assertSame('https://app.mipey.mx/payment-link/01KRFJJYPMP24MF3EFX1043RNC', $created->url);
    }

    public function testUpdateSendsOnlyTheIdAndTheAmount(): void
    {
        $this->http->willRespond(200, '{"id":"01KRFJJYPMP24MF3EFX1043RNC","amount":150.01}');

        $updated = $this->paymentLinks->update(new UpdatePaymentLinkRequest(self::ID, '150.005'));

        $request = $this->http->lastRequest();
        self::assertSame('https://api.pey.mx/payment-link/update', (string) $request->getUri());
        self::assertSame('{"paymentLinkId":"01KRFJJYPMP24MF3EFX1043RNC","amount":150.01}', (string) $request->getBody());
        self::assertSame('150.01', $updated->amount->toDecimalString());
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function provideConflicts(): iterable
    {
        yield 'not pending' => ['PAYMENT_LINK_NOT_PENDING', true, false];
        yield 'concurrent update' => ['CONCURRENT_UPDATE', false, true];
    }

    #[DataProvider('provideConflicts')]
    public function testUpdateConflictsExposeTheirReason(string $type, bool $notPending, bool $concurrent): void
    {
        $this->http->willRespond(409, \sprintf('{"error":{"message":"Payment link is not pending","type":"%s","requestId":"r"}}', $type));

        try {
            $this->paymentLinks->update(new UpdatePaymentLinkRequest(self::ID, '200'));
            self::fail('Expected a ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame($notPending, $e->isPaymentLinkNotPending());
            self::assertSame($concurrent, $e->isConcurrentUpdate());
        }

        self::assertCount(1, $this->http->requests, 'An update is never retried, not even after a concurrent update.');
    }

    public function testUpdatingAnotherKeysLinkIsForbidden(): void
    {
        $this->http->willRespond(403, '{"error":{"message":"Forbidden","type":"FORBIDDEN","requestId":"r"}}');

        $this->expectException(ForbiddenException::class);

        $this->paymentLinks->update(new UpdatePaymentLinkRequest(self::ID, '200'));
    }

    public function testATimeoutWhileCreatingMayHaveCreatedTheLink(): void
    {
        $this->http->willReturn(FakePsr18Exceptions::network('Operation timed out', new Request('POST', 'https://api.pey.mx/payment-link/create')));

        try {
            $this->paymentLinks->create(new CreatePaymentLinkRequest('1', 'https://a.example', 'https://b.example'));
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertTrue($e->requestMayHaveBeenProcessed());
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testGetRetrievesTheLinkWithItsTransactions(): void
    {
        $this->http->willRespond(200, '{"id":"01KRFJJYPMP24MF3EFX1043RNC","amount":100.0,"status":"paid","successUrl":"https://pey.mx/","backUrl":"https://docs.pey.mx/cancel",'
            . '"hasTip":true,"paymentMethods":["CODI","CARD"],"dateCreated":1778639272,"clientReferenceId":"order-12345","metadata":{"orderId":"12345"},'
            . '"transactions":[{"transactionId":"01KRFJKXPJ543PN80P9BJQKGFX","paymentMethod":"CARD","datePaid":1778639309,"amount":118.5,'
            . '"amountBreakdown":{"base":100,"tip":15,"fee":3.5},"applicationFee":9.25,"metadata":{"externalMetadata":{"orderId":"12345"}}}]}');

        $link = $this->paymentLinks->get(self::ID);

        self::assertSame('https://api.pey.mx/payment-link/01KRFJJYPMP24MF3EFX1043RNC', (string) $this->http->lastRequest()->getUri());
        self::assertSame(PaymentLinkStatus::Paid, $link->status);
        self::assertArrayHasKey(0, $link->transactions);
        self::assertSame('100.00', $link->transactions[0]->amountBreakdown?->base->toDecimalString());
    }

    public function testGetMapsUnknownLinksToNotFound(): void
    {
        $this->http->willRespond(404, '{"error":{"message":"Payment link not found","type":"NOT_FOUND","requestId":"r"}}');

        $this->expectException(NotFoundException::class);

        $this->paymentLinks->get(self::ID);
    }

    public function testGetRejectsIdsThatAreNotUlidsWithoutSendingAnything(): void
    {
        try {
            $this->paymentLinks->get('01HXYZ1234567890ABCDEFGH');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['id'], array_keys($e->errors()));
        }

        self::assertSame([], $this->http->requests);
    }
}
