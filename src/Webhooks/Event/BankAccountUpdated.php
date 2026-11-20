<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks\Event;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Enum\CodiStatus;
use Dev1\Pey\Enum\Entity;

/**
 * `bank.account.updated`: a bank account registered through the API became
 * ACTIVE and can receive CoDi payments. It is sent only for that transition.
 * The payload has no alias, errorMessage or dateCreated; use
 * `$pey->bankAccounts()->get()` for those.
 *
 * TODO(pey-docs): ownerType values other than API_KEY are undocumented.
 *
 * @see https://docs.pey.mx/en/docs/webhooks/bank-account/updated
 */
final readonly class BankAccountUpdated implements WebhookEvent
{
    public const TYPE = 'bank.account.updated';

    /**
     * @param string      $ownerType `API_KEY` for accounts registered through the API
     * @param string      $ownerId   the API key id when ownerType is API_KEY
     * @param string|null $taxId     RFC of the holder, only when Pey has it
     */
    public function __construct(
        public string $bankAccountId,
        public string $name,
        public string $ownerType,
        public string $ownerId,
        public ?string $taxId,
        public CodiStatus $codiStatus,
        public string $clabe,
        public Entity $entity,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->string('bankAccountId'),
            $payload->string('name'),
            $payload->string('ownerType'),
            $payload->string('ownerId'),
            $payload->optionalString('taxId'),
            CodiStatus::fromApi($payload->string('codiStatus')),
            $payload->string('clabe'),
            Entity::fromApi($payload->string('entity')),
        );
    }

    public function type(): string
    {
        return self::TYPE;
    }
}
