<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Response\BankAccount;
use Dev1\Pey\Dto\Response\BankAccountRegistration;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\PeyException;

/**
 * Bank accounts (CLABEs) that receive CoDi payments.
 */
interface BankAccountsInterface
{
    /**
     * Registers a CLABE with Pey (`POST /bank-account`). The account starts as
     * PENDING and becomes ACTIVE once Banxico confirms it (up to 5 business
     * days). Pey sends the `bank.account.updated` webhook when it does.
     *
     * The CLABE must be on Config::$allowedClabes. Otherwise nothing is sent.
     * The call is never retried automatically.
     *
     * @throws BankAccountNotAllowedException when the CLABE is not allowlisted
     * @throws PeyException
     */
    public function register(RegisterBankAccountRequest $request): BankAccountRegistration;

    /**
     * Retrieves a bank account registered with this API key (`GET /bank-account/{id}`).
     *
     * @param string $id ULID returned by register()
     *
     * @throws PeyException
     */
    public function get(string $id): BankAccount;
}
