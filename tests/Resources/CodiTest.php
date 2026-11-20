<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Resources;

use Dev1\Pey\Config;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\ServerException;
use Dev1\Pey\Exception\ServiceUnavailableException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Resources\Codi;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\FakePsr18Exceptions;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Expiration;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\NumRef;
use Dev1\Pey\Validation\PhoneNumber;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Codi::class)]
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
#[UsesClass(CreateQrRequest::class)]
#[UsesClass(CreatePushNotificationRequest::class)]
#[UsesClass(QrCode::class)]
#[UsesClass(PushNotification::class)]
#[UsesClass(Violations::class)]
#[UsesClass(AmountRules::class)]
#[UsesClass(Expiration::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(NumRef::class)]
#[UsesClass(PhoneNumber::class)]
#[UsesClass(Text::class)]
#[UsesClass(Ulid::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(NetworkException::class)]
#[UsesClass(UnexpectedResponseException::class)]
final class CodiTest extends TestCase
{
    private MockHttpClient $http;

    private Codi $codi;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $factory = new Psr17Factory();
        $this->codi = new Codi(new Transport(new Config('pey_test_not_a_real_value'), $this->http, $factory, $factory));
    }

    public function testCreateQrPostsTheRequestAndReturnsTheQr(): void
    {
        $this->http->willRespond(201, '{"id":"AB12CD34EF","qrString":"{\"TYP\":1,\"v\":\"01\",\"ic\":0,\"IDC\":\"AB12CD34EF\"}","qrImage":"iVBORw0KGgo="}');

        $qr = $this->codi->createQr(new CreateQrRequest(amount: '150.00', concept: 'Order #12345', expiration: 600, numRef: '1234567', generateImage: true));

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.pey.mx/codi/qr', (string) $request->getUri());
        self::assertSame('{"amount":150.00,"concept":"Order #12345","expiration":600,"numRef":"1234567","generateImage":true}', (string) $request->getBody());
        self::assertSame('AB12CD34EF', $qr->id);
        self::assertSame('iVBORw0KGgo=', $qr->qrImage);
    }

    public function testCreatePushNotificationPostsTheRequestAndReturnsTheTransaction(): void
    {
        $this->http->willRespond(200, '{"transactionId":"01M3QR5E7XHXMNZDEPFH3JAWS0","codiId":"12345678901234567890"}');

        $push = $this->codi->createPushNotification(new CreatePushNotificationRequest(amount: '150.00', phoneNumber: '5512345678'));

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.pey.mx/codi/pn', (string) $request->getUri());
        self::assertSame('{"amount":150.00,"phoneNumber":"5512345678"}', (string) $request->getBody());
        self::assertSame('01M3QR5E7XHXMNZDEPFH3JAWS0', $push->transactionId);
        self::assertSame('12345678901234567890', $push->codiId);
    }

    public function testPeyValidationErrorsSurfaceAsValidationExceptions(): void
    {
        $this->http->willRespond(400, '{"error":{"message":"API key does not have CoDi enabled","type":"INVALID_STATE","requestId":"r"}}');

        try {
            $this->codi->createQr(new CreateQrRequest('1'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->wasRejectedByPey());
            self::assertSame('INVALID_STATE', $e->errorType());
        }
    }

    public function testAMaintenanceWindowIsNotRetried(): void
    {
        $this->http
            ->willRespond(503, '{"error":{"message":"This endpoint is under maintenance, please try again later","type":"SERVICE_UNAVAILABLE","requestId":"r"}}')
            ->willRespond(200, '{"transactionId":"x","codiId":"y"}');

        try {
            $this->codi->createPushNotification(new CreatePushNotificationRequest('1', '5512345678'));
            self::fail('Expected a ServiceUnavailableException.');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('SERVICE_UNAVAILABLE', $e->errorType());
        }

        self::assertCount(1, $this->http->requests, 'A CoDi charge must never be retried automatically.');
    }

    public function testATimeoutIsReportedAsPossiblyProcessed(): void
    {
        $this->http->willReturn(FakePsr18Exceptions::network('Operation timed out', new Request('POST', 'https://api.pey.mx/codi/qr')));

        try {
            $this->codi->createQr(new CreateQrRequest('1'));
            self::fail('Expected a NetworkException.');
        } catch (NetworkException $e) {
            self::assertTrue($e->requestMayHaveBeenProcessed());
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testAServerErrorIsNotRetried(): void
    {
        $this->http->willRespond(500, '{"error":{"message":"Error creating CoDi QR","type":"INTERNAL_SERVER_ERROR","requestId":"r"}}');

        try {
            $this->codi->createQr(new CreateQrRequest('1'));
            self::fail('Expected a ServerException.');
        } catch (ServerException) {
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testAMalformedResponseIsAnUnexpectedResponse(): void
    {
        $this->http->willRespond(201, '{"qrString":"x"}');

        $this->expectException(UnexpectedResponseException::class);
        $this->expectExceptionMessage('QrCode field "id" must be a string');

        $this->codi->createQr(new CreateQrRequest('1'));
    }
}
