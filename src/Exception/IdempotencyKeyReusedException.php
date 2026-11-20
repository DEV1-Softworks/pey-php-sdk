<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Idempotency\Operation;

/**
 * The idempotency key was already used with different request parameters
 * (for example another amount or phone number). Nothing was sent, and the
 * earlier result is not returned, because it belongs to a different charge.
 * Use a new key for a different charge.
 */
final class IdempotencyKeyReusedException extends IdempotencyException
{
    public static function for(Operation $operation): self
    {
        return new self(
            \sprintf('This idempotency key (%s) was already used with different request parameters. Nothing was sent; use a new key for a different charge.', $operation->value),
            $operation,
        );
    }
}
