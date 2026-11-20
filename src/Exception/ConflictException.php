<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

/**
 * HTTP 409. Pey documents it for payment-link updates only, with errorType()
 * PAYMENT_LINK_NOT_PENDING or CONCURRENT_UPDATE.
 */
final class ConflictException extends ApiException
{
    public const PAYMENT_LINK_NOT_PENDING = 'PAYMENT_LINK_NOT_PENDING';
    public const CONCURRENT_UPDATE = 'CONCURRENT_UPDATE';

    /**
     * The payment link has moved past `pending` and its amount can no longer change.
     */
    public function isPaymentLinkNotPending(): bool
    {
        return $this->errorType() === self::PAYMENT_LINK_NOT_PENDING;
    }

    /**
     * Another update changed the payment link at the same time.
     */
    public function isConcurrentUpdate(): bool
    {
        return $this->errorType() === self::CONCURRENT_UPDATE;
    }
}
