<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Dto\Response\BankAccount;
use Dev1\Pey\Dto\Response\BankAccountRegistration;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Security\BankAccountGuard;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BankAccounts implements BankAccountsInterface
{
    /**
     * @internal use PeyClient::bankAccounts()
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly BankAccountGuard $guard,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function register(#[\SensitiveParameter] RegisterBankAccountRequest $request): BankAccountRegistration
    {
        try {
            $this->guard->assertAllowed($request->clabe);
        } catch (BankAccountNotAllowedException $e) {
            $this->logger->warning('Pey SDK blocked a bank account registration for CLABE {clabe}: it is not on the allowlist.', [
                'clabe' => $e->maskedClabe(),
            ]);

            throw $e;
        }

        return BankAccountRegistration::fromArray($this->transport->post('/bank-account', $request->toBody()));
    }

    public function get(string $id): BankAccount
    {
        $violations = new Violations();
        Ulid::check($id, 'id', $violations);
        $violations->throwIfAny();

        return BankAccount::fromArray($this->transport->get('/bank-account/{id}', ['id' => $id]));
    }
}
