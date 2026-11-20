<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Money\Amount;

/**
 * Response of `POST /payment-link/update`.
 */
final readonly class UpdatedPaymentLink
{
    /**
     * @param Amount $amount the stored amount, after Pey's half-up rounding to two decimals
     */
    public function __construct(
        public string $id,
        public Amount $amount,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'UpdatedPaymentLink');

        return new self($payload->string('id'), $payload->amount('amount'));
    }
}
