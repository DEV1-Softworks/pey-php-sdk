<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Request;

use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Http\JsonObject;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Metadata;
use Dev1\Pey\Validation\Text;
use Dev1\Pey\Validation\Url;
use Dev1\Pey\Validation\Violations;

/**
 * Body of `POST /payment-link/create`.
 */
final readonly class CreatePaymentLinkRequest implements RequestBody
{
    public Amount $amount;

    /**
     * @param Amount|string           $amount            greater than 0
     * @param string                  $successUrl        HTTPS URL the customer returns to after paying
     * @param string                  $backUrl           HTTPS URL the customer returns to after cancelling
     * @param bool                    $hasTip            let the customer add a tip
     * @param string|null             $clientReferenceId your own reference, 1 to 255 characters. The idempotency helper uses it as the lock key.
     * @param array<array-key, mixed> $metadata          up to 50 scalar pairs
     *
     * @throws ValidationException listing every invalid field
     */
    public function __construct(
        Amount|string $amount,
        public string $successUrl,
        public string $backUrl,
        public bool $hasTip = false,
        public ?string $clientReferenceId = null,
        public array $metadata = [],
    ) {
        $this->amount = $amount instanceof Amount ? $amount : Amount::fromString($amount);

        $violations = new Violations();
        AmountRules::checkPaymentLink($this->amount, 'amount', $violations);
        Url::checkHttps($successUrl, 'successUrl', $violations);
        Url::checkHttps($backUrl, 'backUrl', $violations);
        if ($clientReferenceId !== null) {
            Text::checkLength($clientReferenceId, 1, 255, 'clientReferenceId', $violations);
        }
        Metadata::check($metadata, 'metadata', $violations);
        $violations->throwIfAny();
    }

    public function toBody(): array
    {
        return array_filter([
            'amount' => $this->amount->toJsonNumber(),
            'successUrl' => $this->successUrl,
            'backUrl' => $this->backUrl,
            'hasTip' => $this->hasTip,
            'clientReferenceId' => $this->clientReferenceId,
            'metadata' => $this->metadata === [] ? null : new JsonObject($this->metadata),
        ], static fn(mixed $value): bool => $value !== null);
    }
}
