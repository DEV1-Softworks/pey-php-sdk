<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Enum\CodiStatus;

/**
 * Response of `POST /bank-account`. New accounts start as PENDING. Activation
 * with Banxico takes up to 5 business days.
 */
final readonly class BankAccountRegistration
{
    public function __construct(
        public string $id,
        public CodiStatus $codiStatus,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'BankAccountRegistration');

        return new self($payload->string('id'), CodiStatus::fromApi($payload->string('codiStatus')));
    }
}
