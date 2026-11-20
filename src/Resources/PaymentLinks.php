<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\UpdatePaymentLinkRequest;
use Dev1\Pey\Dto\Response\CreatedPaymentLink;
use Dev1\Pey\Dto\Response\PaymentLink;
use Dev1\Pey\Dto\Response\UpdatedPaymentLink;
use Dev1\Pey\Http\Transport;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;

final class PaymentLinks implements PaymentLinksInterface
{
    /**
     * @internal use PeyClient::paymentLinks()
     */
    public function __construct(
        private readonly Transport $transport,
    ) {}

    public function create(CreatePaymentLinkRequest $request): CreatedPaymentLink
    {
        return CreatedPaymentLink::fromArray($this->transport->post('/payment-link/create', $request->toBody()));
    }

    public function update(UpdatePaymentLinkRequest $request): UpdatedPaymentLink
    {
        return UpdatedPaymentLink::fromArray($this->transport->post('/payment-link/update', $request->toBody()));
    }

    public function get(string $id): PaymentLink
    {
        $violations = new Violations();
        Ulid::check($id, 'id', $violations);
        $violations->throwIfAny();

        return PaymentLink::fromArray($this->transport->get('/payment-link/{id}', ['id' => $id]));
    }
}
