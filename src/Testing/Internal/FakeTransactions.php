<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing\Internal;

use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Pagination\Paginator;
use Dev1\Pey\Resources\TransactionsInterface;
use Dev1\Pey\Validation\OpaqueId;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;

/**
 * @internal use FakePeyClient::transactions()
 */
final class FakeTransactions implements TransactionsInterface
{
    private const API_KEY_PAGE_SIZE = 80;

    private const BANK_ACCOUNT_PAGE_SIZE = 80;

    private const CODI_QR_PAGE_SIZE = 20;

    public function __construct(
        private readonly FakeState $state,
    ) {}

    public function get(string $id): Transaction
    {
        return $this->state->call('transactions.get', [$id], function () use ($id): Transaction {
            self::validate(static fn(Violations $v) => Ulid::check($id, 'id', $v));

            return $this->state->transactions[$id] ?? throw FakeState::notFound('GET', '/transaction/{id}', 'NOT_FOUND');
        });
    }

    public function listForApiKey(?string $nextToken = null): TransactionPage
    {
        return $this->state->call('transactions.listForApiKey', [$nextToken], function () use ($nextToken): TransactionPage {
            self::validate(static fn(Violations $v) => self::checkCursor($nextToken, $v));

            return $this->state->page(array_values($this->state->transactions), $nextToken, self::API_KEY_PAGE_SIZE, '/transaction/api-key');
        });
    }

    public function listForBankAccount(string $bankAccountId, ?string $nextToken = null): TransactionPage
    {
        return $this->state->call('transactions.listForBankAccount', [$bankAccountId, $nextToken], function () use ($bankAccountId, $nextToken): TransactionPage {
            self::validate(static function (Violations $v) use ($bankAccountId, $nextToken): void {
                Ulid::check($bankAccountId, 'bankAccountId', $v);
                self::checkCursor($nextToken, $v);
            });

            if (!isset($this->state->bankAccounts[$bankAccountId])) {
                throw FakeState::notFound('GET', '/transaction/bank-account/{id}', 'BANK_ACCOUNT_NOT_FOUND');
            }

            // Pey lists only PAID CoDi transactions for a bank account.
            $items = array_values(array_filter(
                $this->state->transactions,
                fn(Transaction $t): bool => ($this->state->transactionBankAccounts[$t->id] ?? null) === $bankAccountId
                    && $t->status === TransactionStatus::Paid
                    && $t->paymentMethod === PaymentMethod::Codi,
            ));

            return $this->state->page($items, $nextToken, self::BANK_ACCOUNT_PAGE_SIZE, '/transaction/bank-account/{id}');
        });
    }

    public function listForCodiQr(string $codiQrId, ?string $nextToken = null): TransactionPage
    {
        return $this->state->call('transactions.listForCodiQr', [$codiQrId, $nextToken], function () use ($codiQrId, $nextToken): TransactionPage {
            self::validate(static function (Violations $v) use ($codiQrId, $nextToken): void {
                OpaqueId::check($codiQrId, 'codiQrId', $v, 255);
                self::checkCursor($nextToken, $v);
            });

            if (!isset($this->state->qrs[$codiQrId])) {
                throw FakeState::notFound('GET', '/transaction/codi/qr/{id}', 'NOT_FOUND');
            }

            // Pey's QR listing documents no payableType or payableId on its items.
            $items = [];
            foreach ($this->state->transactions as $t) {
                if (($this->state->transactionQrs[$t->id] ?? null) === $codiQrId) {
                    $items[] = new Transaction($t->id, $t->status, $t->amount, $t->amountBreakdown, $t->currency, $t->paymentMethod, null, null, $t->description, $t->applicationFee, $t->dateCreated, $t->datePaid, $t->metadata);
                }
            }

            return $this->state->page($items, $nextToken, self::CODI_QR_PAGE_SIZE, '/transaction/codi/qr/{id}');
        });
    }

    public function allForApiKey(): \Generator
    {
        return Paginator::items(fn(?string $token): TransactionPage => $this->listForApiKey($token));
    }

    public function allForBankAccount(string $bankAccountId): \Generator
    {
        self::validate(static fn(Violations $v) => Ulid::check($bankAccountId, 'bankAccountId', $v));

        return Paginator::items(fn(?string $token): TransactionPage => $this->listForBankAccount($bankAccountId, $token));
    }

    public function allForCodiQr(string $codiQrId): \Generator
    {
        self::validate(static fn(Violations $v) => OpaqueId::check($codiQrId, 'codiQrId', $v, 255));

        return Paginator::items(fn(?string $token): TransactionPage => $this->listForCodiQr($codiQrId, $token));
    }

    /**
     * @param \Closure(Violations): void $checks
     */
    private static function validate(\Closure $checks): void
    {
        $violations = new Violations();
        $checks($violations);
        $violations->throwIfAny();
    }

    private static function checkCursor(?string $nextToken, Violations $violations): void
    {
        if ($nextToken !== null) {
            OpaqueId::check($nextToken, 'nextToken', $violations);
        }
    }
}
