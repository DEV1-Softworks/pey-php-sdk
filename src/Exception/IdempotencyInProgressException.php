<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Idempotency\Operation;

/**
 * Another attempt with the same idempotency key is in progress right now (for
 * example a double click, or two workers handling the same job). Nothing was
 * sent. Try again shortly: once the other attempt completes, the same call
 * returns its result.
 */
final class IdempotencyInProgressException extends IdempotencyException
{
    public static function for(Operation $operation): self
    {
        return new self(
            \sprintf('Another attempt with this idempotency key (%s) is in progress. Nothing was sent to Pey; try again shortly to get its result.', $operation->value),
            $operation,
        );
    }
}
