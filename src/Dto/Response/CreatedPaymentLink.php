<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;

/**
 * Response of `POST /payment-link/create`.
 */
final readonly class CreatedPaymentLink
{
    /**
     * @param string $id  ULID of the payment link
     * @param string $url where the customer pays
     */
    public function __construct(
        public string $id,
        public string $url,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'CreatedPaymentLink');

        return new self($payload->string('id'), $payload->string('url'));
    }
}
