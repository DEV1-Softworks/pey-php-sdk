<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Money\Amount;

/**
 * How a transaction amount splits. Pey includes only non-zero components, so
 * an absent component is zero.
 *
 * To check that an order was paid in full, compare `base` with the order total.
 * The transaction `amount` also includes the tip and the fee charged to the payer.
 */
final readonly class AmountBreakdown
{
    public function __construct(
        public Amount $base,
        public Amount $tip,
        public Amount $fee,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->optionalAmount('base') ?? Amount::zero(),
            $payload->optionalAmount('tip') ?? Amount::zero(),
            $payload->optionalAmount('fee') ?? Amount::zero(),
        );
    }

    public function total(): Amount
    {
        return $this->base->add($this->tip)->add($this->fee);
    }
}
