<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\UpdatePaymentLinkRequest;
use Dev1\Pey\Dto\Response\CreatedPaymentLink;
use Dev1\Pey\Dto\Response\PaymentLink;
use Dev1\Pey\Dto\Response\UpdatedPaymentLink;
use Dev1\Pey\Exception\ConflictException;
use Dev1\Pey\Exception\PeyException;

/**
 * Payment links: hosted pages where the customer pays by CoDi or card.
 */
interface PaymentLinksInterface
{
    /**
     * Creates a payment link (`POST /payment-link/create`). It is never
     * retried automatically. Use clientReferenceId to recognize your own links.
     *
     * @throws PeyException
     */
    public function create(CreatePaymentLinkRequest $request): CreatedPaymentLink;

    /**
     * Changes the amount of a payment link (`POST /payment-link/update`).
     * Only the amount can change, and only while the link is `pending`.
     *
     * @throws ConflictException when the link is no longer pending (isPaymentLinkNotPending())
     *                           or was updated concurrently (isConcurrentUpdate())
     * @throws PeyException
     */
    public function update(UpdatePaymentLinkRequest $request): UpdatedPaymentLink;

    /**
     * Retrieves a payment link created with this API key (`GET /payment-link/{id}`).
     *
     * @param string $id ULID returned by create()
     *
     * @throws PeyException
     */
    public function get(string $id): PaymentLink;
}
