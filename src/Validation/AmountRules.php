<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

use Dev1\Pey\Money\Amount;

/**
 * Amount limits per endpoint, as documented by Pey.
 *
 * - CoDi QR: any non-negative amount. 0 lets the payer type the amount, and
 *   Amount itself can never be negative, so no rule is needed.
 * - Push notification: greater than 0 and at most 1,000,000.00.
 * - Payment link (create and update): greater than 0.
 *
 * TODO(pey-docs): no maximum is documented for QR or payment-link amounts.
 */
final class AmountRules
{
    public const PUSH_NOTIFICATION_MAXIMUM_CENTAVOS = 100_000_000;

    public static function checkPushNotification(Amount $amount, string $field, Violations $violations): void
    {
        if ($amount->isZero() || $amount->centavos() > self::PUSH_NOTIFICATION_MAXIMUM_CENTAVOS) {
            $violations->add($field, 'must be greater than 0 and at most 1000000.00.');
        }
    }

    public static function checkPaymentLink(Amount $amount, string $field, Violations $violations): void
    {
        if ($amount->isZero()) {
            $violations->add($field, 'must be greater than 0.');
        }
    }
}
