<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * CoDi numeric reference (`numRef`): 1 to 7 digits, sent as a string, so
 * leading zeros are kept. Pey's default is "0".
 */
final class NumRef
{
    public static function isValid(string $value): bool
    {
        return preg_match('/^\d{1,7}$/D', $value) === 1;
    }

    public static function check(string $value, string $field, Violations $violations): void
    {
        if (!self::isValid($value)) {
            $violations->add($field, 'must be 1 to 7 digits.');
        }
    }
}
