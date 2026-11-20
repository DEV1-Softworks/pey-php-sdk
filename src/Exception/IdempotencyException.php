<?php

declare(strict_types=1);

namespace Dev1\Pey\Exception;

use Dev1\Pey\Idempotency\Operation;

/**
 * An idempotent request was not sent, because of what the idempotency store
 * already knows about its key. Nothing was sent to Pey.
 */
abstract class IdempotencyException extends PeyException
{
    protected function __construct(
        string $message,
        private readonly Operation $operation,
    ) {
        parent::__construct($message);
    }

    public function operation(): Operation
    {
        return $this->operation;
    }
}
