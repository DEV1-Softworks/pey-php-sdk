<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing\Internal;

use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\AmountBreakdown;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Dto\Response\Transaction;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Resources\CodiInterface;

/**
 * @internal use FakePeyClient::codi()
 */
final class FakeCodi implements CodiInterface
{
    /**
     * A 1×1 transparent PNG, returned when generateImage is true.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function __construct(
        private readonly FakeState $state,
    ) {}

    public function createQr(CreateQrRequest $request): QrCode
    {
        return $this->state->call('codi.createQr', [$request], function () use ($request): QrCode {
            $this->assertActiveBankAccount($request->bankAccountId, '/codi/qr');

            $id = $this->state->nextQrId();
            $this->state->qrs[$id] = ['request' => $request, 'createdAt' => $this->state->now()];

            return new QrCode($id, \sprintf('{"TYP":1,"v":"01","ic":0,"IDC":"%s"}', $id), $request->generateImage ? self::PNG : null);
        });
    }

    public function createPushNotification(CreatePushNotificationRequest $request): PushNotification
    {
        return $this->state->call('codi.createPushNotification', [$request], function () use ($request): PushNotification {
            $this->assertActiveBankAccount($request->bankAccountId, '/codi/pn');

            $transactionId = $this->state->nextUlid();
            $this->state->pushNotifications[$transactionId] = $request;
            // The charge waits for the payer's approval: PENDING until simulatePushNotificationPayment().
            $this->state->transactions[$transactionId] = new Transaction(
                $transactionId,
                TransactionStatus::Pending,
                $request->amount,
                new AmountBreakdown($request->amount, Amount::zero(), Amount::zero()),
                'MXN',
                PaymentMethod::Codi,
                PayableType::CodiPushNotification,
                null,
                $request->concept,
                null,
                $this->state->now(),
                null,
                new TransactionMetadata(externalMetadata: FakeState::storedMetadata($request->metadata)),
            );
            if ($request->bankAccountId !== null) {
                $this->state->transactionBankAccounts[$transactionId] = $request->bankAccountId;
            }

            return new PushNotification($transactionId, $this->state->nextFolio());
        });
    }

    /**
     * Pey requires bankAccountId to be an ACTIVE account of this API key. The
     * exact error Pey returns is undocumented; the fake answers a 400 INVALID_STATE.
     */
    private function assertActiveBankAccount(?string $bankAccountId, string $path): void
    {
        if ($bankAccountId === null) {
            return;
        }

        $account = $this->state->bankAccounts[$bankAccountId] ?? null;
        if ($account === null || $account['status'] !== CodiStatus::Active) {
            throw FakeState::rejected('POST', $path, 'INVALID_STATE', 'The bank account is not ACTIVE (FakePeyClient).');
        }
    }
}
