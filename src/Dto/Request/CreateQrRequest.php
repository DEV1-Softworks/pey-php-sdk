<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Request;

use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Validation\Expiration;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\NumRef;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;

/**
 * Body of `POST /codi/qr`.
 */
final readonly class CreateQrRequest implements RequestBody
{
    /**
     * The QR amount. Zero lets the payer type the amount.
     */
    public Amount $amount;

    /**
     * @param Amount|string           $amount        "0" lets the payer type the amount
     * @param string|null             $concept       shown to the payer
     * @param int|null                $expiration    seconds: 0 means never, otherwise at least 360. Pey's default is 0.
     * @param string|null             $numRef        1 to 7 digits. Pey's default is "0".
     * @param bool                    $generateImage include the QR as a base64 PNG (slower)
     * @param string|null             $bankAccountId ULID of an ACTIVE bank account; omit it to use the API key's account
     * @param array<array-key, mixed> $metadata      up to 50 scalar pairs
     *
     * @throws ValidationException listing every invalid field
     */
    public function __construct(
        Amount|string $amount,
        public ?string $concept = null,
        public ?int $expiration = null,
        public ?string $numRef = null,
        public bool $generateImage = false,
        public ?string $bankAccountId = null,
        public array $metadata = [],
    ) {
        $this->amount = $amount instanceof Amount ? $amount : Amount::fromString($amount);

        $violations = new Violations();
        if ($concept !== null) {
            Text::checkUtf8($concept, 'concept', $violations);
        }
        if ($expiration !== null) {
            Expiration::checkQr($expiration, 'expiration', $violations);
        }
        if ($numRef !== null) {
            NumRef::check($numRef, 'numRef', $violations);
        }
        if ($bankAccountId !== null) {
            Ulid::check($bankAccountId, 'bankAccountId', $violations);
        }
        Metadata::check($metadata, 'metadata', $violations);
        $violations->throwIfAny();
    }

    public function toBody(): array
    {
        return array_filter([
            'amount' => $this->amount->toJsonNumber(),
            'concept' => $this->concept,
            'expiration' => $this->expiration,
            'numRef' => $this->numRef,
            'generateImage' => $this->generateImage,
            'bankAccountId' => $this->bankAccountId,
            'metadata' => $this->metadata === [] ? null : new JsonObject($this->metadata),
        ], static fn(mixed $value): bool => $value !== null);
    }
}
