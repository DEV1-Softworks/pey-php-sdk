<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing\Internal;

use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Response\BankAccount;
use Dev1\Pey\Dto\Response\BankAccountRegistration;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Resources\BankAccountsInterface;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;

/**
 * @internal use FakePeyClient::bankAccounts()
 */
final class FakeBankAccounts implements BankAccountsInterface
{
    public function __construct(
        private readonly FakeState $state,
    ) {}

    public function register(#[\SensitiveParameter] RegisterBankAccountRequest $request): BankAccountRegistration
    {
        return $this->state->call('bankAccounts.register', [$request], function () use ($request): BankAccountRegistration {
            // The same guard as the real client: nothing is registered unless allowlisted.
            $this->state->guard->assertAllowed($request->clabe);

            $id = $this->state->nextUlid();
            $this->state->bankAccounts[$id] = ['request' => $request, 'status' => CodiStatus::Pending, 'createdAt' => $this->state->now()];

            return new BankAccountRegistration($id, CodiStatus::Pending);
        });
    }

    public function get(string $id): BankAccount
    {
        return $this->state->call('bankAccounts.get', [$id], function () use ($id): BankAccount {
            $violations = new Violations();
            Ulid::check($id, 'id', $violations);
            $violations->throwIfAny();

            $account = $this->state->bankAccounts[$id] ?? throw FakeState::notFound('GET', '/bank-account/{id}', 'BANK_ACCOUNT_NOT_FOUND');
            $request = $account['request'];

            return new BankAccount($id, $request->alias, $request->name, $request->clabe, $request->entity, $account['status'], $account['createdAt']);
        });
    }
}
