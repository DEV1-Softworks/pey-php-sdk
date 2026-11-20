<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * Redirect URLs sent to Pey (payment-link `successUrl` and `backUrl`) must be
 * absolute HTTPS URLs.
 */
final class Url
{
    public static function isHttps(string $value): bool
    {
        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            return false;
        }

        $parts = parse_url($value);

        return \is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && strtolower($parts['scheme']) === 'https'
            && $parts['host'] !== '';
    }

    public static function checkHttps(string $value, string $field, Violations $violations): void
    {
        if (!self::isHttps($value)) {
            $violations->add($field, 'must be an absolute https:// URL without spaces.');
        }
    }
}
