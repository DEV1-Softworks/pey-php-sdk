<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * Custom metadata attached to QR codes, push notifications and payment links.
 *
 * Pey's rules: at most 50 pairs, keys up to 40 characters, values scalar
 * (string, number, boolean or null) and up to 500 characters. Pey stores and
 * returns every value as a string (`"123"`, `"false"`, `"null"`), so the length
 * limit is checked on that string form.
 */
final class Metadata
{
    public const MAX_ENTRIES = 50;

    public const MAX_KEY_LENGTH = 40;

    public const MAX_VALUE_LENGTH = 500;

    /**
     * @param array<array-key, mixed> $metadata
     */
    public static function isValid(array $metadata): bool
    {
        $violations = new Violations();
        self::check($metadata, 'metadata', $violations);

        return $violations->isEmpty();
    }

    /**
     * Errors for a value are reported under `<field>.<key>`. Errors about the
     * map itself, or about a key, are reported under `<field>`.
     *
     * @param array<array-key, mixed> $metadata
     */
    public static function check(array $metadata, string $field, Violations $violations): void
    {
        if (\count($metadata) > self::MAX_ENTRIES) {
            $violations->add($field, \sprintf('must have at most %d entries.', self::MAX_ENTRIES));
        }

        foreach ($metadata as $key => $value) {
            $keyLength = Text::length((string) $key);
            if ($keyLength === null || $keyLength > self::MAX_KEY_LENGTH) {
                $violations->add($field, \sprintf('keys must be valid UTF-8 and at most %d characters.', self::MAX_KEY_LENGTH));

                continue;
            }

            self::checkValue($value, $field . '.' . $key, $violations);
        }
    }

    private static function checkValue(mixed $value, string $path, Violations $violations): void
    {
        if (\is_float($value) && !is_finite($value)) {
            $violations->add($path, 'must be a finite number.');

            return;
        }

        $asStored = match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_int($value), \is_float($value) => json_encode($value, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR),
            \is_string($value) => $value,
            default => null,
        };

        if ($asStored === null) {
            $violations->add($path, 'must be a string, number, boolean or null; nested arrays and objects are not allowed.');

            return;
        }

        $length = Text::length($asStored);
        if ($length === null) {
            $violations->add($path, 'must be valid UTF-8.');
        } elseif ($length > self::MAX_VALUE_LENGTH) {
            $violations->add($path, \sprintf('must be at most %d characters.', self::MAX_VALUE_LENGTH));
        }
    }
}
