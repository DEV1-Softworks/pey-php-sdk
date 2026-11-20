<?php

declare(strict_types=1);

namespace Dev1\Pey;

use Dev1\Pey\Resources\BankAccountsInterface;
use Dev1\Pey\Resources\CodiInterface;
use Dev1\Pey\Resources\PaymentLinksInterface;
use Dev1\Pey\Resources\TransactionsInterface;

/**
 * Entry point to the Pey API. Type-hint this interface in your code, so tests
 * can substitute the in-memory fake.
 */
interface PeyClientInterface
{
    public function codi(): CodiInterface;

    /**
     * Registration is blocked unless the CLABE is on Config::$allowedClabes.
     */
    public function bankAccounts(): BankAccountsInterface;

    public function paymentLinks(): PaymentLinksInterface;

    /**
     * Confirm payments here (get()) before fulfilling an order.
     */
    public function transactions(): TransactionsInterface;
}
