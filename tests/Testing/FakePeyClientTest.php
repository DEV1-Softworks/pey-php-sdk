<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Testing;

use Dev1\Pey\Clock\SystemClock;
use Dev1\Pey\Config;
use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Request\UpdatePaymentLinkRequest;
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
use Dev1\Pey\Exception\ApiException;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\ConflictException;
use Dev1\Pey\Exception\IdempotencyException;
use Dev1\Pey\Exception\IdempotencyOutcomeUnknownException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Exception\PeyException;
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
use Dev1\Pey\Idempotency\IdempotentRequests;
use Dev1\Pey\Idempotency\InMemoryIdempotencyStore;
use Dev1\Pey\Idempotency\Operation;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Pagination\Paginator;
use Dev1\Pey\PeyClient;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Resources\BankAccounts;
use Dev1\Pey\Resources\Codi;
use Dev1\Pey\Resources\PaymentLinks;
use Dev1\Pey\Resources\Transactions;
use Dev1\Pey\Security\BankAccountGuard;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Testing\FakePeyClient;
use Dev1\Pey\Testing\Internal\FakeBankAccounts;
use Dev1\Pey\Testing\Internal\FakeCodi;
use Dev1\Pey\Testing\Internal\FakePaymentLinks;
use Dev1\Pey\Testing\Internal\FakeState;
use Dev1\Pey\Testing\Internal\FakeTransactions;
use Dev1\Pey\Testing\RecordedCall;
use Dev1\Pey\Testing\SignedWebhook;
use Dev1\Pey\Testing\SimulatedPayment;
use Dev1\Pey\Testing\WebhookSigner;
use Dev1\Pey\Tests\Support\MockHttpClient;
use Dev1\Pey\Tests\Support\MutableClock;
use Dev1\Pey\Tests\Support\StandardWebhooksVectors;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Clabe;
use Dev1\Pey\Validation\Expiration;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\NumRef;
use Dev1\Pey\Validation\OpaqueId;
use Dev1\Pey\Validation\PhoneNumber;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Url;
use Dev1\Pey\Validation\Violations;
use Dev1\Pey\Webhooks\Event\BankAccountUpdated;
use Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated;
use Dev1\Pey\Webhooks\EventFactory;
use Dev1\Pey\Webhooks\VerifiedWebhook;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakePeyClient::class)]
#[CoversClass(FakeState::class)]
#[CoversClass(FakeCodi::class)]
#[CoversClass(FakeBankAccounts::class)]
#[CoversClass(FakePaymentLinks::class)]
#[CoversClass(FakeTransactions::class)]
#[CoversClass(RecordedCall::class)]
#[CoversClass(SimulatedPayment::class)]
#[UsesClass(WebhookSigner::class)]
#[UsesClass(SignedWebhook::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Json::class)]
#[UsesClass(JsonNumber::class)]
#[UsesClass(JsonObject::class)]
#[UsesClass(Payload::class)]
#[UsesClass(ApiError::class)]
#[UsesClass(BankAccountGuard::class)]
#[UsesClass(Paginator::class)]
#[UsesClass(CreateQrRequest::class)]
#[UsesClass(CreatePushNotificationRequest::class)]
#[UsesClass(CreatePaymentLinkRequest::class)]
#[UsesClass(UpdatePaymentLinkRequest::class)]
#[UsesClass(RegisterBankAccountRequest::class)]
#[UsesClass(QrCode::class)]
#[UsesClass(PushNotification::class)]
#[UsesClass(BankAccountRegistration::class)]
#[UsesClass(BankAccount::class)]
#[UsesClass(CreatedPaymentLink::class)]
#[UsesClass(UpdatedPaymentLink::class)]
#[UsesClass(PaymentLink::class)]
#[UsesClass(Transaction::class)]
#[UsesClass(TransactionPage::class)]
#[UsesClass(TransactionSummary::class)]
#[UsesClass(TransactionMetadata::class)]
#[UsesClass(AmountBreakdown::class)]
#[UsesClass(CodiStatus::class)]
#[UsesClass(Entity::class)]
#[UsesClass(PayableType::class)]
#[UsesClass(PaymentLinkStatus::class)]
#[UsesClass(PaymentMethod::class)]
#[UsesClass(TransactionStatus::class)]
#[UsesClass(Violations::class)]
#[UsesClass(AmountRules::class)]
#[UsesClass(Clabe::class)]
#[UsesClass(Expiration::class)]
#[UsesClass(Metadata::class)]
#[UsesClass(NumRef::class)]
#[UsesClass(OpaqueId::class)]
#[UsesClass(PhoneNumber::class)]
#[UsesClass(Text::class)]
#[UsesClass(Ulid::class)]
#[UsesClass(Url::class)]
#[UsesClass(PeyException::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(ValidationException::class)]
#[UsesClass(ConflictException::class)]
#[UsesClass(NetworkException::class)]
#[UsesClass(BankAccountNotAllowedException::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(UnexpectedResponseException::class)]
#[UsesClass(IdempotentRequests::class)]
#[UsesClass(IdempotencyRecord::class)]
#[UsesClass(InMemoryIdempotencyStore::class)]
#[UsesClass(Operation::class)]
#[UsesClass(IdempotencyException::class)]
#[UsesClass(IdempotencyOutcomeUnknownException::class)]
#[UsesClass(WebhookVerifier::class)]
#[UsesClass(VerifiedWebhook::class)]
#[UsesClass(EventFactory::class)]
#[UsesClass(CodiQrPaymentReceived::class)]
#[UsesClass(CodiPushNotificationPaymentReceived::class)]
#[UsesClass(PaymentLinkStatusUpdated::class)]
#[UsesClass(BankAccountUpdated::class)]
#[UsesClass(Secret::class)]
#[UsesClass(PeyClient::class)]
#[UsesClass(Codi::class)]
#[UsesClass(BankAccounts::class)]
#[UsesClass(PaymentLinks::class)]
#[UsesClass(Transactions::class)]
#[UsesClass(Transport::class)]
#[UsesClass(Config::class)]
#[UsesClass(RetryPolicy::class)]
#[UsesClass(HeaderRedactor::class)]
#[UsesClass(SystemClock::class)]
final class FakePeyClientTest extends TestCase
{
    private const CLABE = '032180000118359719';

    private MutableClock $clock;

    private FakePeyClient $pey;

    protected function setUp(): void
    {
        $this->clock = new MutableClock(1_795_000_000);
        $this->pey = new FakePeyClient([self::CLABE], $this->clock);
    }

    private function verifier(): WebhookVerifier
    {
        return new WebhookVerifier(StandardWebhooksVectors::SECRET, clock: $this->clock);
    }

    private function deliver(string $body): VerifiedWebhook
    {
        $signed = WebhookSigner::sign(StandardWebhooksVectors::SECRET, $body, timestamp: $this->clock->now()->getTimestamp());

        return $this->verifier()->verify($signed->body, $signed->headers);
    }

    // ------------------------------------------------------------------ CoDi QR

    public function testAQrIsCreatedAndPaid(): void
    {
        $qr = $this->pey->codi()->createQr(new CreateQrRequest('150.00', concept: 'Order #1', metadata: ['orderId' => 1, 'gift' => false, 'note' => null, 'ratio' => 1.5]));

        self::assertSame('FAKE000001', $qr->id);
        self::assertStringContainsString('"IDC":"FAKE000001"', $qr->qrString);
        self::assertNull($qr->qrImage);

        $payment = $this->pey->simulateQrPayment($qr->id);
        $transaction = $this->pey->transactions()->get($payment->transaction->id);

        self::assertTrue($transaction->isPaid());
        self::assertSame(PayableType::CodiQr, $transaction->payableType);
        self::assertSame('FAKE000001', $transaction->payableId);
        self::assertSame('150.00', $transaction->amountBreakdown?->base->toDecimalString());
        self::assertSame(['orderId' => '1', 'gift' => 'false', 'note' => 'null', 'ratio' => '1.5'], $transaction->metadata->externalMetadata, 'Stored as strings, like Pey.');
        self::assertSame('Order #1', $transaction->description);
    }

    public function testAQrPaymentWebhookVerifiesAndParses(): void
    {
        $qr = $this->pey->codi()->createQr(new CreateQrRequest('150.00', concept: 'Order #1', numRef: '1234567', metadata: ['orderId' => '1']));
        $payment = $this->pey->simulateQrPayment($qr->id);

        $event = $this->deliver($payment->webhookBody)->event;

        self::assertInstanceOf(CodiQrPaymentReceived::class, $event);
        self::assertSame($payment->transaction->id, $event->transactionId);
        self::assertSame($qr->id, $event->codiQrId);
        self::assertSame('150.00', $event->amount->toDecimalString());
        self::assertSame('1234567', $event->numRef);
        self::assertSame(FakePeyClient::API_KEY_ID, $event->apiKeyId);
        self::assertSame(['orderId' => '1'], $event->metadata->externalMetadata);
        self::assertNull($event->bankAccountId);
    }

    public function testAnOpenAmountQrIsPaidWithTheAmountThePayerTypes(): void
    {
        $qr = $this->pey->codi()->createQr(new CreateQrRequest('0', generateImage: true));

        self::assertNotNull($qr->qrImage);
        self::assertSame('37.50', $this->pey->simulateQrPayment($qr->id, '37.5')->transaction->amount->toDecimalString());
    }

    public function testAQrCanBePaidSeveralTimes(): void
    {
        $qr = $this->pey->codi()->createQr(new CreateQrRequest('10'));
        $first = $this->pey->simulateQrPayment($qr->id);
        $second = $this->pey->simulateQrPayment($qr->id);

        self::assertNotSame($first->transaction->id, $second->transaction->id);
        $listed = iterator_to_array($this->pey->transactions()->allForCodiQr($qr->id));
        self::assertCount(2, $listed);
        self::assertArrayHasKey(0, $listed);
        self::assertSame($second->transaction->id, $listed[0]->id, 'Newest first.');
        self::assertNull($listed[0]->payableType, 'Like Pey, the QR listing carries no payable fields.');
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function provideInvalidQrSimulations(): iterable
    {
        yield 'unknown QR' => ['NOPE', null];
        yield 'fixed-amount QR with an amount' => ['FAKE000001', '5'];
        yield 'open-amount QR without an amount' => ['FAKE000002', null];
    }

    #[DataProvider('provideInvalidQrSimulations')]
    public function testQrSimulationsAreChecked(string $qrId, ?string $amount): void
    {
        $this->pey->codi()->createQr(new CreateQrRequest('10'));
        $this->pey->codi()->createQr(new CreateQrRequest('0'));

        $this->expectException(\LogicException::class);

        $this->pey->simulateQrPayment($qrId, $amount);
    }

    // ------------------------------------------------------- push notifications

    public function testAPushNotificationIsPendingUntilApproved(): void
    {
        $push = $this->pey->codi()->createPushNotification(new CreatePushNotificationRequest('150', '5512345678', concept: 'Order #2'));

        self::assertSame(TransactionStatus::Pending, $this->pey->transactions()->get($push->transactionId)->status);
        self::assertMatchesRegularExpression('/^\d{20}$/D', $push->codiId);

        $this->clock->advance(30);
        $payment = $this->pey->simulatePushNotificationPayment($push->transactionId);
        $transaction = $this->pey->transactions()->get($push->transactionId);

        self::assertTrue($transaction->isPaid());
        self::assertSame(1_795_000_030, $transaction->datePaid?->getTimestamp());
        self::assertSame(PayableType::CodiPushNotification, $transaction->payableType);

        $event = $this->deliver($payment->webhookBody)->event;
        self::assertInstanceOf(CodiPushNotificationPaymentReceived::class, $event);
        self::assertSame($push->transactionId, $event->transactionId);
        self::assertSame('0', $event->numRef, "Pey's default numRef.");
    }

    public function testAPushNotificationCannotBePaidTwice(): void
    {
        $push = $this->pey->codi()->createPushNotification(new CreatePushNotificationRequest('1', '5512345678'));
        $this->pey->simulatePushNotificationPayment($push->transactionId);

        $this->expectException(\LogicException::class);

        $this->pey->simulatePushNotificationPayment($push->transactionId);
    }

    public function testAnUnknownPushNotificationCannotBePaid(): void
    {
        $this->expectException(\LogicException::class);

        $this->pey->simulatePushNotificationPayment('01JFAKE0000000000000000099');
    }

    // ----------------------------------------------------------- bank accounts

    public function testAllowlistedBankAccountsAreRegisteredAndActivated(): void
    {
        $registration = $this->pey->bankAccounts()->register(new RegisterBankAccountRequest('BBVA', 'Pagos con Pey S.A. de C.V.', self::CLABE, Entity::Business));

        self::assertSame(CodiStatus::Pending, $registration->codiStatus);
        self::assertSame(CodiStatus::Pending, $this->pey->bankAccounts()->get($registration->id)->codiStatus);

        $event = $this->deliver($this->pey->activateBankAccount($registration->id))->event;

        self::assertSame(CodiStatus::Active, $this->pey->bankAccounts()->get($registration->id)->codiStatus);
        self::assertInstanceOf(BankAccountUpdated::class, $event);
        self::assertSame($registration->id, $event->bankAccountId);
        self::assertSame(self::CLABE, $event->clabe);
    }

    public function testTheFakeAppliesTheSameGuardAsProduction(): void
    {
        $this->expectException(BankAccountNotAllowedException::class);

        (new FakePeyClient())->bankAccounts()->register(new RegisterBankAccountRequest('BBVA', 'n', self::CLABE, Entity::Business));
    }

    public function testChargesIntoAnActiveBankAccountAreListedForIt(): void
    {
        $account = $this->pey->bankAccounts()->register(new RegisterBankAccountRequest('BBVA', 'n', self::CLABE, Entity::Business))->id;

        try {
            $this->pey->codi()->createQr(new CreateQrRequest('5', bankAccountId: $account));
            self::fail('Expected a ValidationException for a PENDING account.');
        } catch (ValidationException $e) {
            self::assertTrue($e->wasRejectedByPey());
            self::assertSame('INVALID_STATE', $e->errorType());
        }

        $this->pey->activateBankAccount($account);
        $qr = $this->pey->codi()->createQr(new CreateQrRequest('5', bankAccountId: $account));
        $push = $this->pey->codi()->createPushNotification(new CreatePushNotificationRequest('7', '5512345678', bankAccountId: $account));
        $qrPayment = $this->pey->simulateQrPayment($qr->id);

        $listed = static fn(FakePeyClient $pey): array => array_map(
            static fn(Transaction $t): string => $t->id,
            iterator_to_array($pey->transactions()->allForBankAccount($account)),
        );
        self::assertSame([$qrPayment->transaction->id], $listed($this->pey), 'The pending push notification is not listed: only PAID CoDi transactions are.');

        // Newest first by creation (transaction id), like the cursors in Pey's
        // examples (TXN#PAID#<transaction ULID>): the push notification was
        // created before the QR payment, even though it was paid later.
        $pushPayment = $this->pey->simulatePushNotificationPayment($push->transactionId);
        self::assertSame([$qrPayment->transaction->id, $pushPayment->transaction->id], $listed($this->pey));

        $event = $this->deliver($qrPayment->webhookBody)->event;
        self::assertInstanceOf(CodiQrPaymentReceived::class, $event);
        self::assertSame($account, $event->bankAccountId);
    }

    // ------------------------------------------------------------ payment links

    public function testAPaymentLinkLifecycle(): void
    {
        $created = $this->pey->paymentLinks()->create(new CreatePaymentLinkRequest('100', 'https://shop.example/ok', 'https://shop.example/back', hasTip: true, clientReferenceId: 'order-3', metadata: ['orderId' => '3']));

        self::assertStringStartsWith('https://pay.example.invalid/payment-link/', $created->url);
        self::assertSame('120.00', $this->pey->paymentLinks()->update(new UpdatePaymentLinkRequest($created->id, '120'))->amount->toDecimalString());

        $payment = $this->pey->simulatePaymentLinkPayment($created->id, PaymentMethod::Card, '15');
        $link = $this->pey->paymentLinks()->get($created->id);

        self::assertSame(PaymentLinkStatus::Paid, $link->status);
        self::assertSame('120.00', $link->amount->toDecimalString());
        self::assertSame(['orderId' => '3'], $link->metadata);
        self::assertCount(1, $link->transactions);
        self::assertSame('135.00', $payment->transaction->amount->toDecimalString());
        self::assertSame('15.00', $payment->transaction->amountBreakdown?->tip->toDecimalString());
        self::assertSame(PaymentMethod::Card, $payment->transaction->paymentMethod);

        $event = $this->deliver($payment->webhookBody)->event;
        self::assertInstanceOf(PaymentLinkStatusUpdated::class, $event);
        self::assertSame(PaymentLinkStatus::Paid, $event->status);
        self::assertSame('order-3', $event->clientReferenceId);
        self::assertArrayHasKey(0, $event->transactions);
        self::assertSame('120.00', $event->transactions[0]->amountBreakdown?->base->toDecimalString());
    }

    public function testAPaidLinkCanNoLongerBeUpdatedOrPaid(): void
    {
        $id = $this->pey->paymentLinks()->create(new CreatePaymentLinkRequest('100', 'https://a.example', 'https://b.example'))->id;
        $this->pey->simulatePaymentLinkPayment($id);

        try {
            $this->pey->paymentLinks()->update(new UpdatePaymentLinkRequest($id, '1'));
            self::fail('Expected a ConflictException.');
        } catch (ConflictException $e) {
            self::assertTrue($e->isPaymentLinkNotPending());
        }

        $this->expectException(\LogicException::class);
        $this->pey->simulatePaymentLinkPayment($id);
    }

    public function testUnknownPaymentLinksCannotBeUpdatedOrPaid(): void
    {
        try {
            $this->pey->paymentLinks()->update(new UpdatePaymentLinkRequest('01JFAKE0000000000000000099', '1'));
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            self::assertSame('NOT_FOUND', $e->errorType());
        }

        $this->expectException(\LogicException::class);
        $this->pey->simulatePaymentLinkPayment('01JFAKE0000000000000000099');
    }

    public function testAnUnknownBankAccountCannotBeActivated(): void
    {
        $this->expectException(\LogicException::class);

        $this->pey->activateBankAccount('01JFAKE0000000000000000099');
    }

    // --------------------------------------------------------------- listings

    public function testTheApiKeyListingPagesNewestFirst(): void
    {
        $qr = $this->pey->codi()->createQr(new CreateQrRequest('1'));
        $ids = [];
        for ($i = 0; $i < 85; ++$i) {
            $ids[] = $this->pey->simulateQrPayment($qr->id)->transaction->id;
        }

        $first = $this->pey->transactions()->listForApiKey();
        self::assertCount(80, $first->items);
        self::assertSame('fake-cursor-80', $first->nextToken);
        self::assertArrayHasKey(0, $first->items);
        self::assertSame(end($ids), $first->items[0]->id);

        $second = $this->pey->transactions()->listForApiKey($first->nextToken);
        self::assertCount(5, $second->items);
        self::assertNull($second->nextToken);

        $all = array_map(static fn(Transaction $t): string => $t->id, iterator_to_array($this->pey->transactions()->allForApiKey()));
        self::assertSame(array_reverse($ids), $all);
    }

    public function testAnInvalidCursorIsRejectedLikePey(): void
    {
        try {
            $this->pey->transactions()->listForApiKey('cursor-from-somewhere-else');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('INVALID_PARAMETERS', $e->errorType());
        }
    }

    public function testListingsOfUnknownResourcesAreNotFound(): void
    {
        foreach ([
            fn(): mixed => $this->pey->transactions()->listForBankAccount('01JFAKE0000000000000000099'),
            fn(): mixed => $this->pey->transactions()->listForCodiQr('NOPE'),
            fn(): mixed => $this->pey->transactions()->get('01JFAKE0000000000000000099'),
            fn(): mixed => $this->pey->bankAccounts()->get('01JFAKE0000000000000000099'),
            fn(): mixed => $this->pey->paymentLinks()->get('01JFAKE0000000000000000099'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a NotFoundException.');
            } catch (NotFoundException $e) {
                self::assertSame(404, $e->httpStatus());
            }
        }
    }

    // ------------------------------------------------------- parity with Pey

    /**
     * The same invalid calls must fail the same way on the fake and on the
     * real client, so tests written against the fake hold in production.
     *
     * @return iterable<string, array{\Closure(PeyClientInterface): mixed, class-string<\Throwable>, list<string>}>
     */
    public static function provideInvalidCalls(): iterable
    {
        yield 'transaction id not a ULID' => [static fn(PeyClientInterface $p): mixed => $p->transactions()->get('AB12CD34EF'), ValidationException::class, ['id']];
        yield 'bank account id not a ULID' => [static fn(PeyClientInterface $p): mixed => $p->bankAccounts()->get('nope'), ValidationException::class, ['id']];
        yield 'payment link id not a ULID' => [static fn(PeyClientInterface $p): mixed => $p->paymentLinks()->get('01HXYZ1234567890ABCDEFGH'), ValidationException::class, ['id']];
        yield 'empty cursor' => [static fn(PeyClientInterface $p): mixed => $p->transactions()->listForApiKey(''), ValidationException::class, ['nextToken']];
        yield 'bad bank account listing' => [static fn(PeyClientInterface $p): mixed => $p->transactions()->listForBankAccount('x'), ValidationException::class, ['bankAccountId']];
        yield 'bad QR listing' => [static fn(PeyClientInterface $p): mixed => $p->transactions()->listForCodiQr(' '), ValidationException::class, ['codiQrId']];
        yield 'all for a bad bank account' => [static fn(PeyClientInterface $p): mixed => $p->transactions()->allForBankAccount('x'), ValidationException::class, ['bankAccountId']];
        yield 'all for a bad QR' => [static fn(PeyClientInterface $p): mixed => $p->transactions()->allForCodiQr(''), ValidationException::class, ['codiQrId']];
        yield 'CLABE not allowlisted' => [static fn(PeyClientInterface $p): mixed => $p->bankAccounts()->register(new RegisterBankAccountRequest('a', 'n', '002010077777777771', Entity::Person)), BankAccountNotAllowedException::class, []];
    }

    /**
     * @param \Closure(PeyClientInterface): mixed $call
     * @param class-string<\Throwable>            $expected
     * @param list<string>                        $fields
     */
    #[DataProvider('provideInvalidCalls')]
    public function testInvalidCallsFailTheSameWayAsTheRealClient(\Closure $call, string $expected, array $fields): void
    {
        $http = new MockHttpClient(); // No responses queued: any request would fail the test.
        $factory = new Psr17Factory();
        $real = PeyClient::create(new Config('pey_test_not_a_real_value', allowedClabes: [self::CLABE]), $http, null, $factory, $factory);

        foreach (['real client' => $real, 'fake' => $this->pey] as $name => $client) {
            try {
                $call($client);
                self::fail($name . ' accepted an invalid call.');
            } catch (\Throwable $e) {
                self::assertSame($expected, $e::class, $name);
                if ($e instanceof ValidationException) {
                    self::assertSame($fields, array_keys($e->errors()), $name);
                }
            }
        }

        self::assertSame([], $http->requests, 'The real client must reject these locally.');
    }

    // ------------------------------------------- failure injection and calls

    public function testFailNextThrowsWithoutDoingAnything(): void
    {
        $this->pey->failNext(NetworkException::create('POST', '/codi/qr', false, 'X', 'refused'));

        try {
            $this->pey->codi()->createQr(new CreateQrRequest('5'));
            self::fail('Expected a NetworkException.');
        } catch (NetworkException) {
        }

        self::assertSame('FAKE000001', $this->pey->codi()->createQr(new CreateQrRequest('5'))->id, 'Nothing was created by the failed call.');
    }

    public function testIdempotencyCanBeRehearsedWithFailuresAfterProcessing(): void
    {
        $idempotent = new IdempotentRequests($this->pey, new InMemoryIdempotencyStore($this->clock), clock: $this->clock);
        $this->pey->failNextAfterProcessing(NetworkException::create('POST', '/codi/pn', true, 'Timeout', 'Operation timed out'));
        $request = new CreatePushNotificationRequest('150', '5512345678');

        try {
            $idempotent->createPushNotification('order-9:pn', $request);
            self::fail('Expected a NetworkException.');
        } catch (NetworkException) {
        }

        // The charge exists, but its outcome is unknown to the application.
        self::assertCount(1, $this->pey->callsTo('codi.createPushNotification'));
        $pending = iterator_to_array($this->pey->transactions()->allForApiKey());
        self::assertCount(1, $pending);

        $this->expectException(IdempotencyOutcomeUnknownException::class);
        try {
            $idempotent->createPushNotification('order-9:pn', $request);
        } finally {
            self::assertCount(1, $this->pey->callsTo('codi.createPushNotification'), 'No second charge was sent.');
        }
    }

    public function testCallsAreRecordedInOrder(): void
    {
        $request = new CreateQrRequest('5');
        $qr = $this->pey->codi()->createQr($request);
        $this->pey->transactions()->listForCodiQr($qr->id);

        $calls = $this->pey->recordedCalls();
        self::assertSame(['codi.createQr', 'transactions.listForCodiQr'], array_map(static fn(RecordedCall $c): string => $c->method, $calls));
        self::assertArrayHasKey(0, $calls);
        self::assertSame([$request], $calls[0]->arguments);
        self::assertCount(1, $this->pey->callsTo('codi.createQr'));
        self::assertSame([], $this->pey->callsTo('paymentLinks.create'));
    }

    public function testItUsesTheSystemClockByDefault(): void
    {
        $pey = new FakePeyClient();
        $push = $pey->codi()->createPushNotification(new CreatePushNotificationRequest('1', '5512345678'));

        self::assertEqualsWithDelta(time(), $pey->transactions()->get($push->transactionId)->dateCreated->getTimestamp(), 5);
    }
}
