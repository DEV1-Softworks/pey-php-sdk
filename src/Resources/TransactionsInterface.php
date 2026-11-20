<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionPage;
use Dev1\Pey\Exception\PeyException;

/**
 * Transactions received through this API key.
 *
 * Before fulfilling an order, confirm the payment with get(): status PAID, the
 * expected payableType and payableId, and `amountBreakdown->base` equal to the
 * order total.
 *
 * Listings are newest first. Pass the previous page's nextToken to get the next
 * page, or iterate an all*() generator, which fetches pages lazily.
 */
interface TransactionsInterface
{
    /**
     * `GET /transaction/{id}`.
     *
     * @param string $id ULID of the transaction
     *
     * @throws PeyException
     */
    public function get(string $id): Transaction;

    /**
     * `GET /transaction/api-key`: every transaction of this API key, 80 per page.
     *
     * @throws PeyException
     */
    public function listForApiKey(?string $nextToken = null): TransactionPage;

    /**
     * `GET /transaction/bank-account/{id}`: PAID CoDi transactions received by
     * a registered bank account, 80 per page.
     *
     * @throws PeyException
     */
    public function listForBankAccount(string $bankAccountId, ?string $nextToken = null): TransactionPage;

    /**
     * `GET /transaction/codi/qr/{id}`: transactions of a CoDi QR, 20 per page.
     * A QR without expiration can be paid many times.
     *
     * @param string $codiQrId the QR's IDC, as returned by Codi::createQr()
     *
     * @throws PeyException
     */
    public function listForCodiQr(string $codiQrId, ?string $nextToken = null): TransactionPage;

    /**
     * @return \Generator<int, Transaction>
     *
     * @throws PeyException
     */
    public function allForApiKey(): \Generator;

    /**
     * @return \Generator<int, Transaction>
     *
     * @throws PeyException
     */
    public function allForBankAccount(string $bankAccountId): \Generator;

    /**
     * @return \Generator<int, Transaction>
     *
     * @throws PeyException
     */
    public function allForCodiQr(string $codiQrId): \Generator;
}
