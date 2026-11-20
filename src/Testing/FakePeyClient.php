<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing;

use Dev1\Pey\Clock\SystemClock;
use Dev1\Pey\Dto\Response\AmountBreakdown;
use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Dto\Response\TransactionSummary;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Resources\BankAccountsInterface;
use Dev1\Pey\Resources\CodiInterface;
use Dev1\Pey\Resources\PaymentLinksInterface;
use Dev1\Pey\Resources\TransactionsInterface;
use Dev1\Pey\Security\BankAccountGuard;
use Dev1\Pey\Testing\Internal\FakeBankAccounts;
use Dev1\Pey\Testing\Internal\FakeCodi;
use Dev1\Pey\Testing\Internal\FakePaymentLinks;
use Dev1\Pey\Testing\Internal\FakeState;
use Dev1\Pey\Testing\Internal\FakeTransactions;
use Psr\Clock\ClockInterface;

/**
 * In-memory PeyClientInterface for your application's tests. It never talks
 * to the network.
 *
 * It behaves like the real client where it matters for tests:
 * - the same request objects and validators reject the same input;
 * - bank account registration goes through the same BankAccountGuard
 *   (pass the CLABEs you allowlist in production);
 * - unknown ids raise NotFoundException, updating a paid link raises
 *   ConflictException, and so on.
 *
 * Simulations drive the flows your code waits for, and each returns the
 * webhook Pey would send (sign it with WebhookSigner):
 *
 * ```php
 * $pey = new FakePeyClient();
 * $qr = (new YourCheckout($pey))->start($order);            // calls createQr()
 * $payment = $pey->simulateQrPayment($qr->id);
 * $this->assertTrue($pey->transactions()->get($payment->transaction->id)->isPaid());
 * ```
 *
 * Amounts are not split into fees: base equals the amount, and tip and fee
 * are zero unless simulated.
 */
final class FakePeyClient implements PeyClientInterface
{
    /**
     * The API key id reported in fake webhooks and bank accounts.
     */
    public const API_KEY_ID = FakeState::API_KEY_ID;

    private readonly FakeState $state;

    private readonly FakeCodi $codi;

    private readonly FakeBankAccounts $bankAccounts;

    private readonly FakePaymentLinks $paymentLinks;

    private readonly FakeTransactions $transactions;

    /**
     * @param list<string> $allowedClabes the CLABEs that may be registered, as in Config::$allowedClabes
     */
    public function __construct(array $allowedClabes = [], ClockInterface $clock = new SystemClock())
    {
        $this->state = new FakeState(new BankAccountGuard($allowedClabes), $clock);
        $this->codi = new FakeCodi($this->state);
        $this->bankAccounts = new FakeBankAccounts($this->state);
        $this->paymentLinks = new FakePaymentLinks($this->state);
        $this->transactions = new FakeTransactions($this->state);
    }

    public function codi(): CodiInterface
    {
        return $this->codi;
    }

    public function bankAccounts(): BankAccountsInterface
    {
        return $this->bankAccounts;
    }

    public function paymentLinks(): PaymentLinksInterface
    {
        return $this->paymentLinks;
    }

    public function transactions(): TransactionsInterface
    {
        return $this->transactions;
    }

    // ------------------------------------------------------------ simulations

    /**
     * The payer scans and pays a QR. A QR without expiration can be paid more
     * than once; each call creates a new PAID transaction.
     *
     * @param Amount|string|null $amount required for a QR created with amount 0 (the payer types it); must be null otherwise
     */
    public function simulateQrPayment(string $codiQrId, Amount|string|null $amount = null): SimulatedPayment
    {
        $qr = $this->state->qrs[$codiQrId] ?? throw new \LogicException(\sprintf('FakePeyClient: no QR with id %s was created.', $codiQrId));
        $request = $qr['request'];

        if ($request->amount->isZero() === ($amount === null)) {
            throw new \LogicException('FakePeyClient: pass an amount only for QRs created with amount 0.');
        }
        $paid = $amount === null ? $request->amount : ($amount instanceof Amount ? $amount : Amount::fromString($amount));

        $transaction = $this->recordPaidTransaction($paid, Amount::zero(), PayableType::CodiQr, $codiQrId, $request->concept, $request->metadata);
        $this->state->transactionQrs[$transaction->id] = $codiQrId;
        if ($request->bankAccountId !== null) {
            $this->state->transactionBankAccounts[$transaction->id] = $request->bankAccountId;
        }

        return new SimulatedPayment($transaction, Json::encodeObject(array_filter([
            'amount' => $paid->toJsonNumber(),
            'apiKeyId' => self::API_KEY_ID,
            'bankAccountId' => $request->bankAccountId,
            'codiQrId' => $codiQrId,
            'concept' => $request->concept,
            'datePaid' => $transaction->dateCreated->getTimestamp(),
            'metadata' => self::webhookMetadata($transaction),
            'numRef' => $request->numRef ?? '0',
            'transactionId' => $transaction->id,
            'type' => 'codi.qr.payment_received',
        ], static fn(mixed $value): bool => $value !== null)));
    }

    /**
     * The payer approves a push notification in their bank app.
     */
    public function simulatePushNotificationPayment(string $transactionId): SimulatedPayment
    {
        $request = $this->state->pushNotifications[$transactionId]
            ?? throw new \LogicException(\sprintf('FakePeyClient: no push notification with transaction %s was sent.', $transactionId));
        $pending = $this->state->transactions[$transactionId] ?? throw new \LogicException('FakePeyClient: missing push notification transaction.');
        if ($pending->status !== TransactionStatus::Pending) {
            throw new \LogicException('FakePeyClient: this push notification was already paid.');
        }

        $paid = new Transaction(
            $pending->id,
            TransactionStatus::Paid,
            $pending->amount,
            $pending->amountBreakdown,
            $pending->currency,
            $pending->paymentMethod,
            $pending->payableType,
            $pending->payableId,
            $pending->description,
            $pending->applicationFee,
            $pending->dateCreated,
            $this->state->now(),
            $pending->metadata,
        );
        $this->state->transactions[$transactionId] = $paid;

        return new SimulatedPayment($paid, Json::encodeObject(array_filter([
            'amount' => $paid->amount->toJsonNumber(),
            'apiKeyId' => self::API_KEY_ID,
            'bankAccountId' => $request->bankAccountId,
            'concept' => $request->concept,
            'datePaid' => $this->state->now()->getTimestamp(),
            'metadata' => self::webhookMetadata($paid),
            'numRef' => $request->numRef ?? '0',
            'transactionId' => $transactionId,
            'type' => 'codi.pn.payment_received',
        ], static fn(mixed $value): bool => $value !== null)));
    }

    /**
     * The customer pays a pending payment link, optionally with a tip.
     */
    public function simulatePaymentLinkPayment(string $paymentLinkId, PaymentMethod $method = PaymentMethod::Codi, Amount|string $tip = '0'): SimulatedPayment
    {
        $link = $this->state->paymentLinks[$paymentLinkId]
            ?? throw new \LogicException(\sprintf('FakePeyClient: no payment link with id %s was created.', $paymentLinkId));
        if ($link['status'] !== PaymentLinkStatus::Pending) {
            throw new \LogicException('FakePeyClient: only pending payment links can be paid.');
        }

        $tip = $tip instanceof Amount ? $tip : Amount::fromString($tip, 'tip');
        $transaction = $this->recordPaidTransaction(
            $link['amount'],
            $tip,
            PayableType::PaymentLink,
            $paymentLinkId,
            null,
            $link['request']->metadata,
            $method,
        );
        $summary = new TransactionSummary(
            $transaction->id,
            $method,
            $transaction->dateCreated,
            $transaction->amount,
            $transaction->amountBreakdown,
            null,
            $transaction->metadata,
        );
        $this->state->paymentLinks[$paymentLinkId]['status'] = PaymentLinkStatus::Paid;
        $this->state->paymentLinks[$paymentLinkId]['transactions'][] = $summary;

        $transactions = [];
        foreach ($this->state->paymentLinks[$paymentLinkId]['transactions'] as $settled) {
            $transactions[] = [
                'transactionId' => $settled->transactionId,
                'paymentMethod' => $settled->paymentMethod->value,
                'datePaid' => $settled->datePaid->getTimestamp(),
                'amount' => $settled->amount->toJsonNumber(),
                'amountBreakdown' => [
                    'base' => $settled->amountBreakdown?->base->toJsonNumber(),
                    'tip' => $settled->amountBreakdown?->tip->toJsonNumber(),
                    'fee' => $settled->amountBreakdown?->fee->toJsonNumber(),
                ],
                'applicationFee' => null,
                'metadata' => ['externalMetadata' => new JsonObject($settled->metadata->externalMetadata)],
            ];
        }

        return new SimulatedPayment($transaction, Json::encodeObject(array_filter([
            'apiKeyId' => self::API_KEY_ID,
            'type' => 'payment_link.status_updated',
            'paymentLinkId' => $paymentLinkId,
            'status' => PaymentLinkStatus::Paid->value,
            'clientReferenceId' => $link['request']->clientReferenceId,
            'transactions' => $transactions,
        ], static fn(mixed $value): bool => $value !== null)));
    }

    /**
     * Banxico confirms a registered bank account, which becomes ACTIVE.
     *
     * @return string the `bank.account.updated` webhook body
     */
    public function activateBankAccount(string $bankAccountId): string
    {
        $account = $this->state->bankAccounts[$bankAccountId]
            ?? throw new \LogicException(\sprintf('FakePeyClient: no bank account with id %s was registered.', $bankAccountId));
        $this->state->bankAccounts[$bankAccountId]['status'] = CodiStatus::Active;

        return Json::encodeObject([
            'type' => 'bank.account.updated',
            'bankAccountId' => $bankAccountId,
            'name' => $account['request']->name,
            'ownerType' => 'API_KEY',
            'ownerId' => self::API_KEY_ID,
            'codiStatus' => CodiStatus::Active->value,
            'clabe' => $account['request']->clabe,
            'entity' => $account['request']->entity->value,
        ]);
    }

    // ------------------------------------------------------- failure injection

    /**
     * The next call through any resource throws $error without doing
     * anything, like a request that never reached Pey.
     */
    public function failNext(\Throwable $error): void
    {
        $this->state->failures[] = ['error' => $error, 'afterProcessing' => false];
    }

    /**
     * The next call through any resource takes effect (the QR, charge or link
     * exists) and then throws $error, like a timeout after Pey processed the
     * request. Use it to rehearse reconciliation of unknown outcomes.
     */
    public function failNextAfterProcessing(\Throwable $error): void
    {
        $this->state->failures[] = ['error' => $error, 'afterProcessing' => true];
    }

    // ---------------------------------------------------------------- calls

    /**
     * Every call made through the resources, in order.
     *
     * @return list<RecordedCall>
     */
    public function recordedCalls(): array
    {
        return $this->state->calls;
    }

    /**
     * @param string $method for example "codi.createQr"
     *
     * @return list<RecordedCall>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->state->calls, static fn(RecordedCall $call): bool => $call->method === $method));
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private function recordPaidTransaction(
        Amount $base,
        Amount $tip,
        PayableType $payableType,
        string $payableId,
        ?string $description,
        array $metadata,
        PaymentMethod $method = PaymentMethod::Codi,
    ): Transaction {
        $now = $this->state->now();
        $transaction = new Transaction(
            $this->state->nextUlid(),
            TransactionStatus::Paid,
            $base->add($tip),
            new AmountBreakdown($base, $tip, Amount::zero()),
            'MXN',
            $method,
            $payableType,
            $payableId,
            $description,
            null,
            $now,
            $now,
            new TransactionMetadata(externalMetadata: FakeState::storedMetadata($metadata)),
        );
        $this->state->transactions[$transaction->id] = $transaction;

        return $transaction;
    }

    /**
     * @return array{externalMetadata: JsonObject}
     */
    private static function webhookMetadata(Transaction $transaction): array
    {
        return ['externalMetadata' => new JsonObject($transaction->metadata->externalMetadata)];
    }
}
