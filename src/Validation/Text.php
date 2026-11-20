<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * Length rules for free text. Lengths count Unicode characters, not bytes, and
 * work without the mbstring extension.
 */
final class Text
{
    /**
     * Number of Unicode characters, or null when the string is not valid UTF-8.
     */
    public static function length(string $value): ?int
    {
        $count = preg_match_all('/./su', $value);

        return $count === false ? null : $count;
    }

    /**
     * For free text with no documented length limit (for example `concept`).
     */
    public static function checkUtf8(string $value, string $field, Violations $violations): void
    {
        if (self::length($value) === null) {
            $violations->add($field, 'must be valid UTF-8.');
        }
    }

    public static function checkLength(string $value, int $min, int $max, string $field, Violations $violations): void
    {
        $length = self::length($value);

        if ($length === null) {
            $violations->add($field, 'must be valid UTF-8.');

            return;
        }

        if ($length < $min || $length > $max) {
            $violations->add($field, $min === $max
                ? \sprintf('must be exactly %d characters.', $min)
                : \sprintf('must be between %d and %d characters.', $min, $max));
        }
    }
}
