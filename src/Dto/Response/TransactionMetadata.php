<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;

/**
 * Pey's "Metadata Response" attached to transactions and payment webhooks.
 *
 * The CoDi fields describe the payer's bank account. `externalMetadata` holds
 * the metadata you attached when creating the QR, push notification or
 * payment link, with every value as a string.
 */
final readonly class TransactionMetadata
{
    /**
     * @param string|null           $clabe            the payer's CLABE (CoDi only)
     * @param int|null              $bankCode         Banxico code of the payer's bank (CoDi only)
     * @param int|null              $accountType      Banxico code of the payer's account type (CoDi only)
     * @param string|null           $description      CoDi only
     * @param string|null           $trackingKey      Banxico tracking key (CoDi only)
     * @param array<string, string> $externalMetadata your metadata
     */
    public function __construct(
        public ?string $clabe = null,
        public ?int $bankCode = null,
        public ?int $accountType = null,
        public ?string $description = null,
        public ?string $trackingKey = null,
        public array $externalMetadata = [],
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(?Payload $payload): self
    {
        if ($payload === null) {
            return new self();
        }

        return new self(
            $payload->optionalString('clabe'),
            $payload->optionalInt('bankCode'),
            $payload->optionalInt('accountType'),
            $payload->optionalString('description'),
            $payload->optionalString('trackingKey'),
            $payload->stringMap('externalMetadata'),
        );
    }
}
