<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;

/**
 * Response of `GET /bank-account/{id}`. Only ACTIVE accounts can receive payments.
 */
final readonly class BankAccount
{
    /**
     * @param string|null $taxId        RFC of the holder, only when Pey has it.
     *                                  TODO(pey-docs): registration has no field to provide it.
     * @param string|null $errorMessage why registration failed, only when codiStatus is ERROR
     */
    public function __construct(
        public string $id,
        public string $alias,
        public string $name,
        public string $clabe,
        public Entity $entity,
        public CodiStatus $codiStatus,
        public \DateTimeImmutable $dateCreated,
        public ?string $taxId = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'BankAccount');

        return new self(
            $payload->string('id'),
            $payload->string('alias'),
            $payload->string('name'),
            $payload->string('clabe'),
            Entity::fromApi($payload->string('entity')),
            CodiStatus::fromApi($payload->string('codiStatus')),
            $payload->timestamp('dateCreated'),
            $payload->optionalString('taxId'),
            $payload->optionalString('errorMessage'),
        );
    }
}
