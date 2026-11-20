<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * Phone number for CoDi push notifications: exactly 10 digits, without the
 * country code or any separators.
 */
final class PhoneNumber
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^\d{10}$/D', $value) === 1;
    }

    public static function check(string $value, string $field, Violations $violations): void
    {
        if (!self::isValid($value)) {
            $violations->add($field, 'must be exactly 10 digits, without country code or separators.');
        }
    }
}
