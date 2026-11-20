<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Idempotency;

use Dev1\Pey\Config;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\CreatedPaymentLink;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\AuthenticationException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\ConflictException;
use Dev1\Pey\Exception\ForbiddenException;
use Dev1\Pey\Exception\IdempotencyException;
use Dev1\Pey\Exception\IdempotencyInProgressException;
use Dev1\Pey\Exception\IdempotencyKeyReusedException;
use Dev1\Pey\Exception\IdempotencyOutcomeUnknownException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\RateLimitedException;
use Dev1\Pey\Exception\ServerException;
use Dev1\Pey\Exception\ServiceUnavailableException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Http\HeaderRedactor;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonNumber;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Idempotency\IdempotencyRecord;
use Dev1\Pey\Idempotency\IdempotencyStore;
use Dev1\Pey\Idempotency\IdempotentRequests;
use Dev1\Pey\Idempotency\InMemoryIdempotencyStore;
use Dev1\Pey\Idempotency\Operation;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\PeyClient;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Resources\BankAccounts;
use Dev1\Pey\Resources\Codi;
use Dev1\Pey\Resources\PaymentLinks;
use Dev1\Pey\Resources\Transactions;
use Dev1\Pey\Security\BankAccountGuard;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Tests\Support\FailingIdempotencyStore;
use Dev1\Pey\Tests\Support\FakePsr18Exceptions;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Tests\Support\MutableClock;
use Dev1\Pey\Tests\Support\RecordingLogger;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Expiration;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\NumRef;
use Dev1\Pey\Validation\PhoneNumber;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Url;
use Dev1\Pey\Validation\Violations;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(IdempotentRequests::class)]
#[CoversClass(IdempotencyException::class)]
#[CoversClass(IdempotencyInProgressException::class)]
#[CoversClass(IdempotencyOutcomeUnknownException::class)]
#[CoversClass(IdempotencyKeyReusedException::class)]
#[UsesClass(IdempotencyRecord::class)]
#[UsesClass(InMemoryIdempotencyStore::class)]
#[UsesClass(Operation::class)]
#[UsesClass(PeyClient::class)]
#[UsesClass(Codi::class)]
#[UsesClass(PaymentLinks::class)]
#[UsesClass(BankAccounts::class)]
#[UsesClass(Transactions::class)]
#[UsesClass(BankAccountGuard::class)]
#[UsesClass(Config::class)]
#[UsesClass(RetryPolicy::class)]
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
#[UsesClass(CreatePaymentLinkRequest::class)]
#[UsesClass(QrCode::class)]
#[UsesClass(PushNotification::class)]
#[UsesClass(CreatedPaymentLink::class)]
#[UsesClass(Violations::class)]
#[UsesClass(AmountRules::class)]
#[UsesClass(Expiration::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(NumRef::class)]
#[UsesClass(PhoneNumber::class)]
#[UsesClass(Text::class)]
#[UsesClass(Ulid::class)]
#[UsesClass(Url::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ConflictException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(NetworkException::class)]
#[UsesClass(UnexpectedResponseException::class)]
#[UsesClass(ConfigurationException::class)]
final class IdempotentRequestsTest extends TestCase
{
    private const QR_RESPONSE = '{"id":"AB12CD34EF","qrString":"{\"TYP\":1,\"IDC\":\"AB12CD34EF\"}","qrImage":null}';

    private MockHttpClient $http;

    private MutableClock $clock;

    private InMemoryIdempotencyStore $store;

    private RecordingLogger $logger;

    private IdempotentRequests $idempotent;

    protected function setUp(): void
    {
        $this->http = new MockHttpClient();
        $this->clock = new MutableClock();
        $this->store = new InMemoryIdempotencyStore($this->clock);
        $this->logger = new RecordingLogger();
        $this->idempotent = $this->idempotent($this->store);
    }

    private function idempotent(IdempotencyStore $store, ?PeyClientInterface $pey = null): IdempotentRequests
    {
        $factory = new Psr17Factory();
        $pey ??= PeyClient::create(new Config('pey_test_not_a_real_value'), $this->http, null, $factory, $factory);

        return new IdempotentRequests($pey, $store, clock: $this->clock, logger: $this->logger);
    }

    private static function qr(string $amount = '150.00'): CreateQrRequest
    {
        return new CreateQrRequest(amount: $amount, concept: 'Order #12345', metadata: ['orderId' => '12345', 'channel' => 'web']);
    }

    // ---------------------------------------------------------------- happy path

    public function testTheFirstCallSendsAndTheRepeatReplaysWithoutSending(): void
    {
        $this->http->willRespond(201, self::QR_RESPONSE);

        $first = $this->idempotent->createQr('order-12345:qr', self::qr());
        $second = $this->idempotent->createQr('order-12345:qr', self::qr());

        self::assertCount(1, $this->http->requests, 'The second call must not reach Pey.');
        self::assertEquals($first, $second);
        self::assertSame('AB12CD34EF', $second->id);
        self::assertCount(1, $this->logger->recordsAt('info'), 'The replay is logged.');
    }

    public function testReplayAlsoWorksForPushNotificationsAndPaymentLinks(): void
    {
        $this->http
            ->willRespond(200, '{"transactionId":"01M3QR5E7XHXMNZDEPFH3JAWS0","codiId":"12345678901234567890"}')
            ->willRespond(200, '{"id":"01KRFJJYPMP24MF3EFX1043RNC","url":"https://app.mipey.mx/payment-link/01KRFJJYPMP24MF3EFX1043RNC"}');

        $push = new CreatePushNotificationRequest('150', '5512345678');
        $link = new CreatePaymentLinkRequest('150', 'https://a.example', 'https://b.example', clientReferenceId: 'order-12345');

        self::assertEquals($this->idempotent->createPushNotification('order-12345:pn', $push), $this->idempotent->createPushNotification('order-12345:pn', $push));
        self::assertEquals($this->idempotent->createPaymentLink($link), $this->idempotent->createPaymentLink($link));
        self::assertCount(2, $this->http->requests);
    }

    public function testDifferentKeysAreDifferentCharges(): void
    {
        $this->http->willRespond(201, self::QR_RESPONSE)->willRespond(201, self::QR_RESPONSE);

        $this->idempotent->createQr('order-1', self::qr());
        $this->idempotent->createQr('order-2', self::qr());

        self::assertCount(2, $this->http->requests);
    }

    public function testEachOperationHasItsOwnKeyNamespace(): void
    {
        $this->http
            ->willRespond(201, self::QR_RESPONSE)
            ->willRespond(200, '{"transactionId":"01M3QR5E7XHXMNZDEPFH3JAWS0","codiId":"1"}');

        $this->idempotent->createQr('order-12345', self::qr());
        $this->idempotent->createPushNotification('order-12345', new CreatePushNotificationRequest('150', '5512345678'));

        self::assertCount(2, $this->http->requests);
    }

    public function testMetadataOrderDoesNotChangeTheFingerprint(): void
    {
        $this->http->willRespond(201, self::QR_RESPONSE);

        $this->idempotent->createQr('k', new CreateQrRequest('150', metadata: ['a' => '1', 'b' => '2']));
        $this->idempotent->createQr('k', new CreateQrRequest('150.00', metadata: ['b' => '2', 'a' => '1']));

        self::assertCount(1, $this->http->requests);
    }

    // ------------------------------------------------------------ blocked paths

    /**
     * @return iterable<string, array{CreateQrRequest}>
     */
    public static function provideDifferentRequests(): iterable
    {
        yield 'another amount' => [self::qr('150.01')];
        yield 'another concept' => [new CreateQrRequest(amount: '150.00', concept: 'Order #99999', metadata: ['orderId' => '12345', 'channel' => 'web'])];
        yield 'other metadata' => [new CreateQrRequest(amount: '150.00', concept: 'Order #12345', metadata: ['orderId' => '99999', 'channel' => 'web'])];
        yield 'with an expiration' => [new CreateQrRequest(amount: '150.00', concept: 'Order #12345', expiration: 600, metadata: ['orderId' => '12345', 'channel' => 'web'])];
    }

    #[DataProvider('provideDifferentRequests')]
    public function testReusingAKeyForADifferentChargeIsRefused(CreateQrRequest $different): void
    {
        $this->http->willRespond(201, self::QR_RESPONSE);
        $this->idempotent->createQr('order-12345:qr', self::qr());

        $this->expectException(IdempotencyKeyReusedException::class);

        try {
            $this->idempotent->createQr('order-12345:qr', $different);
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testAConcurrentAttemptIsRefusedWithoutSending(): void
    {
        // Another process acquired the key a moment ago and is still waiting for Pey.
        $this->simulateStartedElsewhere(Operation::CodiQr, 'order-12345:qr', self::qr());

        try {
            $this->idempotent->createQr('order-12345:qr', self::qr());
            self::fail('Expected an IdempotencyInProgressException.');
        } catch (IdempotencyInProgressException $e) {
            self::assertSame(Operation::CodiQr, $e->operation());
        }

        self::assertSame([], $this->http->requests);
    }

    public function testAnAttemptThatNeverFinishedIsTreatedAsUnknownOnceStale(): void
    {
        // The other process crashed after sending, before recording the result.
        $this->simulateStartedElsewhere(Operation::CodiQr, 'order-12345:qr', self::qr());
        $this->clock->advance(IdempotentRequests::DEFAULT_STALE_AFTER_SECONDS);

        $this->expectException(IdempotencyOutcomeUnknownException::class);
        $this->expectExceptionMessage('the earlier attempt never finished');

        try {
            $this->idempotent->createQr('order-12345:qr', self::qr());
        } finally {
            self::assertSame([], $this->http->requests, 'A crashed attempt may have charged: never resend automatically.');
        }
    }

    public function testACorruptRecordFailsClosed(): void
    {
        $this->store->acquire(self::storeKey(Operation::CodiQr, 'k'), '{"not":"a record"}', 3600);

        $this->expectException(IdempotencyOutcomeUnknownException::class);

        try {
            $this->idempotent->createQr('k', self::qr());
        } finally {
            self::assertSame([], $this->http->requests);
            self::assertCount(1, $this->logger->recordsAt('error'));
        }
    }

    public function testAnUnreadableStoredResultFailsClosed(): void
    {
        $started = IdempotencyRecord::started(self::fingerprintOf(Operation::CodiQr, self::qr()), $this->clock->now()->getTimestamp());
        $this->store->acquire(self::storeKey(Operation::CodiQr, 'k'), $started->completed(['qrString' => 'x'])->toJson(), 3600);

        $this->expectException(IdempotencyOutcomeUnknownException::class);
        $this->expectExceptionMessage('the stored result is unreadable');

        try {
            $this->idempotent->createQr('k', self::qr());
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    // ------------------------------------------------------- failure outcomes

    /**
     * Failures after which Pey certainly did not create anything: the key is
     * released and the same call may be sent again.
     *
     * @return iterable<string, array{ResponseInterface|\Throwable, class-string<\Throwable>}>
     */
    public static function provideFailuresPeyDidNotActOn(): iterable
    {
        $error = static fn(int $status, string $type): Response => new Response($status, [], \sprintf('{"error":{"message":"m","type":"%s","requestId":"r"}}', $type));

        yield 'HTTP 400' => [$error(400, 'INVALID_STATE'), ValidationException::class];
        yield 'HTTP 401' => [$error(401, 'UNKNOWN'), AuthenticationException::class];
        yield 'HTTP 403' => [$error(403, 'FORBIDDEN'), ForbiddenException::class];
        yield 'HTTP 404' => [$error(404, 'NOT_FOUND'), NotFoundException::class];
        yield 'HTTP 409' => [$error(409, 'CONCURRENT_UPDATE'), ConflictException::class];
        yield 'HTTP 429' => [$error(429, 'UNKNOWN'), RateLimitedException::class];
        yield 'undocumented HTTP 405' => [new Response(405), UnexpectedResponseException::class];
        yield 'request rejected by the HTTP client' => [FakePsr18Exceptions::request('Invalid URI', new Request('POST', '/')), NetworkException::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('provideFailuresPeyDidNotActOn')]
    public function testFailuresPeyDidNotActOnReleaseTheKey(ResponseInterface|\Throwable $failure, string $expected): void
    {
        $this->http->willReturn($failure)->willRespond(201, self::QR_RESPONSE);

        try {
            $this->idempotent->createQr('order-12345:qr', self::qr());
            self::fail('Expected an exception.');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e, 'The original error reaches the caller.');
        }

        $qr = $this->idempotent->createQr('order-12345:qr', self::qr());

        self::assertSame('AB12CD34EF', $qr->id);
        self::assertCount(2, $this->http->requests, 'After a failure Pey did not act on, the same key may be sent again.');
    }

    /**
     * Failures after which a charge may exist: the key stays blocked.
     *
     * @return iterable<string, array{ResponseInterface|\Throwable, class-string<\Throwable>}>
     */
    public static function provideFailuresWithUnknownOutcome(): iterable
    {
        yield 'timeout' => [FakePsr18Exceptions::network('Operation timed out', new Request('POST', '/')), NetworkException::class];
        yield 'connection reset' => [FakePsr18Exceptions::network('Connection reset by peer', new Request('POST', '/')), NetworkException::class];
        yield 'generic client failure' => [FakePsr18Exceptions::generic('boom'), NetworkException::class];
        yield 'HTTP 500' => [new Response(500), ServerException::class];
        yield 'HTTP 502' => [new Response(502), ServerException::class];
        yield 'HTTP 503' => [new Response(503, [], '{"error":{"message":"maintenance","type":"SERVICE_UNAVAILABLE","requestId":"r"}}'), ServiceUnavailableException::class];
        yield 'HTTP 504' => [new Response(504), ServerException::class];
        yield 'success with an unreadable body' => [new Response(201, [], '<html>'), UnexpectedResponseException::class];
        yield 'success with a missing field' => [new Response(201, [], '{"qrString":"x"}'), UnexpectedResponseException::class];
        yield 'undocumented redirect' => [new Response(302), UnexpectedResponseException::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('provideFailuresWithUnknownOutcome')]
    public function testFailuresWithAnUnknownOutcomeKeepTheKeyBlocked(ResponseInterface|\Throwable $failure, string $expected): void
    {
        $this->http->willReturn($failure)->willRespond(201, self::QR_RESPONSE);

        try {
            $this->idempotent->createQr('order-12345:qr', self::qr());
            self::fail('Expected an exception.');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e, 'The original error reaches the caller.');
        }

        foreach ([0, IdempotentRequests::DEFAULT_STALE_AFTER_SECONDS, 86_400] as $later) {
            $this->clock->advance($later);
            try {
                $this->idempotent->createQr('order-12345:qr', self::qr());
                self::fail('Expected an IdempotencyOutcomeUnknownException.');
            } catch (IdempotencyOutcomeUnknownException $e) {
                self::assertStringContainsString('(the earlier attempt failed after it may have reached Pey)', $e->getMessage());
            }
        }

        self::assertCount(1, $this->http->requests, 'A possibly created charge must never be sent again automatically.');
        self::assertCount(1, $this->logger->recordsAt('warning'));
    }

    public function testAReleasedUnknownKeyCanBeSentAgain(): void
    {
        $this->http
            ->willReturn(FakePsr18Exceptions::network('Operation timed out', new Request('POST', '/')))
            ->willRespond(201, self::QR_RESPONSE);

        try {
            $this->idempotent->createQr('order-12345:qr', self::qr());
        } catch (NetworkException) {
        }

        // The application checked Pey and found no charge.
        $this->idempotent->release(Operation::CodiQr, 'order-12345:qr');

        self::assertSame('AB12CD34EF', $this->idempotent->createQr('order-12345:qr', self::qr())->id);
        self::assertCount(2, $this->http->requests);
        self::assertCount(1, $this->logger->recordsAt('notice'), 'Manual releases are logged.');
    }

    /**
     * @return iterable<string, array{\Throwable, bool}>
     */
    public static function provideErrorsRaisedByTheClient(): iterable
    {
        yield 'local validation error' => [ValidationException::forField('amount', 'must be greater than 0.'), true];
        yield 'configuration error' => [ConfigurationException::because('bad'), true];
        yield 'unexpected runtime error' => [new \RuntimeException('bug after the request was sent'), false];
        yield 'type error' => [new \TypeError('bug while reading the response'), false];
    }

    #[DataProvider('provideErrorsRaisedByTheClient')]
    public function testErrorsRaisedOutsideTheTransportAreClassifiedSafely(\Throwable $error, bool $released): void
    {
        $idempotent = $this->idempotent($this->store, self::clientThatThrows($error));

        try {
            $idempotent->createQr('k', self::qr());
            self::fail('Expected an exception.');
        } catch (\Throwable $e) {
            self::assertSame($error, $e);
        }

        $record = $this->store->recall(self::storeKey(Operation::CodiQr, 'k'));
        if ($released) {
            self::assertNull($record);
        } else {
            self::assertNotNull($record);
            self::assertSame(IdempotencyRecord::UNKNOWN, IdempotencyRecord::fromJson($record)?->state);
        }
    }

    // ---------------------------------------------------------- store failures

    public function testAnUnreachableStoreMeansNothingIsSent(): void
    {
        $store = new FailingIdempotencyStore($this->store);
        $store->failing = ['acquire'];

        $this->expectException(\RuntimeException::class);

        try {
            $this->idempotent($store)->createQr('k', self::qr());
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    public function testAResultThatCannotBeStoredIsReturnedAndTheKeyNeverReopens(): void
    {
        $store = new FailingIdempotencyStore($this->store);
        $store->failing = ['remember'];
        $idempotent = $this->idempotent($store);
        $this->http->willRespond(201, self::QR_RESPONSE)->willRespond(201, self::QR_RESPONSE);

        self::assertSame('AB12CD34EF', $idempotent->createQr('k', self::qr())->id, 'The charge exists: return it.');
        self::assertCount(1, $this->logger->recordsAt('critical'));

        $store->failing = [];
        foreach ([IdempotencyInProgressException::class => 0, IdempotencyOutcomeUnknownException::class => IdempotentRequests::DEFAULT_STALE_AFTER_SECONDS] as $expected => $later) {
            $this->clock->advance($later);
            try {
                $idempotent->createQr('k', self::qr());
                self::fail('Expected ' . $expected);
            } catch (IdempotencyException $e) {
                self::assertInstanceOf($expected, $e);
            }
        }

        self::assertCount(1, $this->http->requests);
    }

    public function testAnUnknownOutcomeThatCannotBeStoredStillBlocks(): void
    {
        $store = new FailingIdempotencyStore($this->store);
        $idempotent = $this->idempotent($store);
        $this->http->willReturn(FakePsr18Exceptions::network('timeout', new Request('POST', '/')));

        $store->failing = ['remember'];
        try {
            $idempotent->createQr('k', self::qr());
        } catch (NetworkException) {
        }
        $store->failing = [];

        self::assertCount(1, $this->logger->recordsAt('critical'));
        $this->expectException(IdempotencyInProgressException::class);
        $idempotent->createQr('k', self::qr());
    }

    public function testAKeyThatCannotBeReleasedStaysBlocked(): void
    {
        $store = new FailingIdempotencyStore($this->store);
        $idempotent = $this->idempotent($store);
        $this->http->willRespond(400, '{"error":{"message":"bad","type":"INVALID_AMOUNT","requestId":"r"}}');

        $store->failing = ['release'];
        try {
            $idempotent->createQr('k', self::qr());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertTrue($e->wasRejectedByPey());
        }
        $store->failing = [];

        self::assertCount(1, $this->logger->recordsAt('error'));
        $this->expectException(IdempotencyInProgressException::class);
        $idempotent->createQr('k', self::qr());
    }

    public function testARecordThatVanishesBetweenAcquireAndRecallFailsClosed(): void
    {
        // acquire() says the key is taken, but by recall() the record is gone
        // (released or expired in between).
        $store = new class implements IdempotencyStore {
            public function acquire(string $key, string $record, int $ttlSeconds): bool
            {
                return false;
            }

            public function remember(string $key, string $record, int $ttlSeconds): void {}

            public function release(string $key): void {}

            public function recall(string $key): ?string
            {
                return null;
            }
        };

        $this->expectException(IdempotencyInProgressException::class);

        try {
            $this->idempotent($store)->createQr('k', self::qr());
        } finally {
            self::assertSame([], $this->http->requests);
        }
    }

    // ------------------------------------------------------------- inputs

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('k', 256)];
        yield 'invalid UTF-8' => ["\xB1"];
    }

    #[DataProvider('provideInvalidKeys')]
    public function testInvalidKeysAreRejectedBeforeAnything(string $key): void
    {
        try {
            $this->idempotent->createQr($key, self::qr());
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['idempotencyKey'], array_keys($e->errors()));
        }

        self::assertSame([], $this->http->requests);
    }

    public function testPaymentLinksRequireAClientReferenceId(): void
    {
        try {
            $this->idempotent->createPaymentLink(new CreatePaymentLinkRequest('150', 'https://a.example', 'https://b.example'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['clientReferenceId'], array_keys($e->errors()));
        }

        self::assertSame([], $this->http->requests);
    }

    public function testReleaseValidatesTheKey(): void
    {
        $this->expectException(ValidationException::class);

        $this->idempotent->release(Operation::CodiQr, '');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function provideInvalidTimings(): iterable
    {
        yield 'stale threshold under a minute' => [604_800, 59];
        yield 'retention shorter than the stale threshold' => [299, 300];
    }

    #[DataProvider('provideInvalidTimings')]
    public function testUnsafeTimingsAreRejected(int $retention, int $staleAfter): void
    {
        $this->expectException(ConfigurationException::class);

        new IdempotentRequests(PeyClient::create(new Config('pey_test_not_a_real_value'), $this->http), $this->store, $retention, $staleAfter);
    }

    public function testRawKeysNeverReachTheStoreOrTheLogs(): void
    {
        $this->http->willRespond(201, self::QR_RESPONSE);

        $this->idempotent->createQr('customer@example.com:order-12345', self::qr());
        $this->idempotent->createQr('customer@example.com:order-12345', self::qr());

        $everything = print_r($this->logger->records, true) . print_r($this->store, true);
        self::assertStringNotContainsString('customer@example.com', $everything);
    }

    // ------------------------------------------------------------- helpers

    private static function storeKey(Operation $operation, string $key): string
    {
        return 'pey:idempotency:' . $operation->value . ':' . hash('sha256', $key);
    }

    private static function fingerprintOf(Operation $operation, CreateQrRequest $request): string
    {
        $canonicalize = static function (array $members) use (&$canonicalize): array {
            ksort($members, \SORT_STRING);
            foreach ($members as $name => $value) {
                if ($value instanceof JsonObject) {
                    $members[$name] = new JsonObject($canonicalize($value->members));
                }
            }

            return $members;
        };

        return hash('sha256', $operation->value . "\n" . Json::encodeObject($canonicalize($request->toBody())));
    }

    private function simulateStartedElsewhere(Operation $operation, string $key, CreateQrRequest $request): void
    {
        $record = IdempotencyRecord::started(self::fingerprintOf($operation, $request), $this->clock->now()->getTimestamp());
        self::assertTrue($this->store->acquire(self::storeKey($operation, $key), $record->toJson(), 3600));
    }

    private static function clientThatThrows(\Throwable $error): PeyClientInterface
    {
        return new class ($error) implements PeyClientInterface {
            public function __construct(private readonly \Throwable $error) {}

            public function codi(): \Dev1\Pey\Resources\CodiInterface
            {
                return new class ($this->error) implements \Dev1\Pey\Resources\CodiInterface {
                    public function __construct(private readonly \Throwable $error) {}

                    public function createQr(CreateQrRequest $request): QrCode
                    {
                        throw $this->error;
                    }

                    public function createPushNotification(CreatePushNotificationRequest $request): PushNotification
                    {
                        throw $this->error;
                    }
                };
            }

            public function bankAccounts(): \Dev1\Pey\Resources\BankAccountsInterface
            {
                throw new \LogicException('Not used.');
            }

            public function paymentLinks(): \Dev1\Pey\Resources\PaymentLinksInterface
            {
                throw new \LogicException('Not used.');
            }

            public function transactions(): \Dev1\Pey\Resources\TransactionsInterface
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
