<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * ULID (https://github.com/ulid/spec): 26 characters of Crockford base32
 * (no I, L, O or U). The first character is at most 7, because the 48-bit
 * timestamp cannot exceed `7ZZZZZZZZZ`. Pey uses ULIDs for bank accounts,
 * payment links and transactions.
 *
 * CoDi QR ids are Banxico IDCs, not ULIDs: do not validate them here.
 */
final class Ulid
{
    private const PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/Di';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    public static function check(string $value, string $field, Violations $violations): void
    {
        if (!self::isValid($value)) {
            $violations->add($field, 'must be a ULID (26 Crockford base32 characters).');
        }
    }
}
