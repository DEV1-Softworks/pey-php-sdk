<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Idempotency\Operation;

/**
 * A previous attempt with the same idempotency key may have reached Pey, and
 * its outcome is unknown: it timed out, Pey answered 5xx, the response could
 * not be read, or the process stopped mid-request. Nothing was sent this time.
 *
 * The SDK will never resend on its own, because the first attempt may have
 * created a charge. Check Pey first (for example the API key's transactions,
 * or the payment link). Only if nothing was created, call
 * IdempotentRequests::release() for this key and try again.
 */
final class IdempotencyOutcomeUnknownException extends IdempotencyException
{
    public static function for(Operation $operation, string $reason): self
    {
        return new self(
            \sprintf(
                'A previous attempt with this idempotency key (%s) may have reached Pey and its outcome is unknown (%s). Nothing was sent. '
                . 'Check Pey, and only if nothing was created call IdempotentRequests::release() for this key.',
                $operation->value,
                $reason,
            ),
            $operation,
        );
    }
}
