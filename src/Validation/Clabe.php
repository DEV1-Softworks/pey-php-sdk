<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

use Dev1\Pey\Exception\ValidationException;

/**
 * CLABE (Clave Bancaria Estandarizada): 18 digits whose last digit is a check
 * digit over the first 17.
 *
 * Algorithm (Banco de México): multiply each of the first 17 digits by the
 * weights 3, 7, 1, 3, 7, 1, …, keep each product modulo 10, add them up, and
 * the check digit is (10 − (sum mod 10)) mod 10.
 *
 * Only the format and check digit are verified locally. Pey additionally
 * requires the bank to have CoDi enabled, and only Pey can verify that.
 */
final class Clabe
{
    private const WEIGHTS = [3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7];

    public static function isValid(string $clabe): bool
    {
        return preg_match('/^\d{18}$/D', $clabe) === 1
            && self::checkDigit(substr($clabe, 0, 17)) === (int) $clabe[17];
    }

    public static function check(string $clabe, string $field, Violations $violations): void
    {
        if (preg_match('/^\d{18}$/D', $clabe) !== 1) {
            $violations->add($field, 'must be exactly 18 digits.');

            return;
        }

        if (!self::isValid($clabe)) {
            $violations->add($field, 'has an invalid check digit.');
        }
    }

    /**
     * Masks all but the last 4 characters, for messages and logs:
     * `012180001234567899` becomes `**************7899`.
     */
    public static function mask(string $clabe): string
    {
        $length = \strlen($clabe);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4) . substr($clabe, -4);
    }

    /**
     * Computes the check digit for the first 17 digits of a CLABE.
     *
     * @throws ValidationException when $first17Digits is not exactly 17 digits
     */
    public static function checkDigit(string $first17Digits): int
    {
        if (preg_match('/^\d{17}$/D', $first17Digits) !== 1) {
            throw ValidationException::forField('clabe', 'the check digit is computed from exactly 17 digits.');
        }

        $sum = 0;
        foreach (self::WEIGHTS as $position => $weight) {
            $sum += ((int) $first17Digits[$position] * $weight) % 10;
        }

        return (10 - $sum % 10) % 10;
    }
}
