<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Pagination\Paginator;
use Dev1\Pey\Validation\OpaqueId;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;

final class Transactions implements TransactionsInterface
{
    /**
     * IDCs in Pey's examples are 10 characters; this bound only rejects absurd input.
     */
    private const CODI_QR_ID_MAX_LENGTH = 255;

    /**
     * @internal use PeyClient::transactions()
     */
    public function __construct(
        private readonly Transport $transport,
    ) {}

    public function get(string $id): Transaction
    {
        self::validate(static fn(Violations $v) => Ulid::check($id, 'id', $v));

        return Transaction::fromArray($this->transport->get('/transaction/{id}', ['id' => $id]));
    }

    public function listForApiKey(?string $nextToken = null): TransactionPage
    {
        self::validate(static fn(Violations $v) => self::checkCursor($nextToken, $v));

        return TransactionPage::fromArray($this->transport->get('/transaction/api-key', [], self::query($nextToken)));
    }

    public function listForBankAccount(string $bankAccountId, ?string $nextToken = null): TransactionPage
    {
        self::validate(static function (Violations $v) use ($bankAccountId, $nextToken): void {
            Ulid::check($bankAccountId, 'bankAccountId', $v);
            self::checkCursor($nextToken, $v);
        });

        return TransactionPage::fromArray(
            $this->transport->get('/transaction/bank-account/{id}', ['id' => $bankAccountId], self::query($nextToken)),
        );
    }

    public function listForCodiQr(string $codiQrId, ?string $nextToken = null): TransactionPage
    {
        self::validate(static function (Violations $v) use ($codiQrId, $nextToken): void {
            OpaqueId::check($codiQrId, 'codiQrId', $v, self::CODI_QR_ID_MAX_LENGTH);
            self::checkCursor($nextToken, $v);
        });

        return TransactionPage::fromArray(
            $this->transport->get('/transaction/codi/qr/{id}', ['id' => $codiQrId], self::query($nextToken)),
        );
    }

    public function allForApiKey(): \Generator
    {
        return Paginator::items(fn(?string $token): TransactionPage => $this->listForApiKey($token));
    }

    public function allForBankAccount(string $bankAccountId): \Generator
    {
        // Validate now, not when the caller starts iterating.
        self::validate(static fn(Violations $v) => Ulid::check($bankAccountId, 'bankAccountId', $v));

        return Paginator::items(fn(?string $token): TransactionPage => $this->listForBankAccount($bankAccountId, $token));
    }

    public function allForCodiQr(string $codiQrId): \Generator
    {
        self::validate(static fn(Violations $v) => OpaqueId::check($codiQrId, 'codiQrId', $v, self::CODI_QR_ID_MAX_LENGTH));

        return Paginator::items(fn(?string $token): TransactionPage => $this->listForCodiQr($codiQrId, $token));
    }

    /**
     * @param \Closure(Violations): void $checks
     *
     * @throws ValidationException
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

    /**
     * @return array<string, string>
     */
    private static function query(?string $nextToken): array
    {
        return $nextToken === null ? [] : ['nextToken' => $nextToken];
    }
}
