<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing\Internal;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\UpdatePaymentLinkRequest;
use Dev1\Pey\Dto\Response\CreatedPaymentLink;
use Dev1\Pey\Dto\Response\PaymentLink;
use Dev1\Pey\Dto\Response\UpdatedPaymentLink;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Exception\ConflictException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Resources\PaymentLinksInterface;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;

/**
 * @internal use FakePeyClient::paymentLinks()
 */
final class FakePaymentLinks implements PaymentLinksInterface
{
    public function __construct(
        private readonly FakeState $state,
    ) {}

    public function create(CreatePaymentLinkRequest $request): CreatedPaymentLink
    {
        return $this->state->call('paymentLinks.create', [$request], function () use ($request): CreatedPaymentLink {
            $id = $this->state->nextUlid();
            $this->state->paymentLinks[$id] = [
                'request' => $request,
                'amount' => $request->amount,
                'status' => PaymentLinkStatus::Pending,
                'createdAt' => $this->state->now(),
                'transactions' => [],
            ];

            return new CreatedPaymentLink($id, 'https://pay.example.invalid/payment-link/' . $id);
        });
    }

    public function update(UpdatePaymentLinkRequest $request): UpdatedPaymentLink
    {
        return $this->state->call('paymentLinks.update', [$request], function () use ($request): UpdatedPaymentLink {
            $link = $this->state->paymentLinks[$request->paymentLinkId]
                ?? throw FakeState::notFound('POST', '/payment-link/update', 'NOT_FOUND');

            if ($link['status'] !== PaymentLinkStatus::Pending) {
                throw ConflictException::fromResponse('POST', '/payment-link/update', 409, new ApiError(
                    ConflictException::PAYMENT_LINK_NOT_PENDING,
                    'Payment link is not pending and can no longer be updated (FakePeyClient).',
                    null,
                ));
            }

            $this->state->paymentLinks[$request->paymentLinkId]['amount'] = $request->amount;

            return new UpdatedPaymentLink($request->paymentLinkId, $request->amount);
        });
    }

    public function get(string $id): PaymentLink
    {
        return $this->state->call('paymentLinks.get', [$id], function () use ($id): PaymentLink {
            $violations = new Violations();
            Ulid::check($id, 'id', $violations);
            $violations->throwIfAny();

            $link = $this->state->paymentLinks[$id] ?? throw FakeState::notFound('GET', '/payment-link/{id}', 'NOT_FOUND');
            $request = $link['request'];

            return new PaymentLink(
                $id,
                $link['amount'],
                $link['status'],
                $request->successUrl,
                $request->backUrl,
                $request->hasTip,
                [PaymentMethod::Codi, PaymentMethod::Card],
                $link['createdAt'],
                $request->clientReferenceId,
                FakeState::storedMetadata($request->metadata),
                $link['transactions'],
            );
        });
    }
}
