<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Request;

use Dev1\Pey\Exception\ValidationException;
use Dev1\Pey\Money\Amount;
use Dev1\Pey\Validation\AmountRules;
use Dev1\Pey\Validation\Ulid;
use Dev1\Pey\Validation\Violations;

/**
 * Body of `POST /payment-link/update`. Only the amount can change, and only
 * while the payment link is `pending`; otherwise Pey answers 409.
 */
final readonly class UpdatePaymentLinkRequest implements RequestBody
{
    public Amount $amount;

    /**
     * @param string        $paymentLinkId ULID of the payment link
     * @param Amount|string $amount        the new amount, greater than 0
     *
     * @throws ValidationException listing every invalid field
     */
    public function __construct(
        public string $paymentLinkId,
        Amount|string $amount,
    ) {
        $this->amount = $amount instanceof Amount ? $amount : Amount::fromString($amount);

        $violations = new Violations();
        Ulid::check($paymentLinkId, 'paymentLinkId', $violations);
        AmountRules::checkPaymentLink($this->amount, 'amount', $violations);
        $violations->throwIfAny();
    }

    public function toBody(): array
    {
        return [
            'paymentLinkId' => $this->paymentLinkId,
            'amount' => $this->amount->toJsonNumber(),
        ];
    }
}
