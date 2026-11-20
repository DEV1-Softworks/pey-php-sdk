<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

use Dev1\Pey\Security\Secret;

/**
 * Redacts credential-bearing headers before they are logged.
 *
 * `Authorization: Bearer <key>` becomes `Authorization: Bearer [REDACTED]`:
 * the scheme stays visible for debugging and the credential does not.
 *
 * @internal
 */
final class HeaderRedactor
{
    private const SENSITIVE_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];

    /**
     * @param array<array-key, array<array-key, string>> $headers as returned by PSR-7 getHeaders()
     *
     * @return array<string, list<string>>
     */
    public static function redact(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $values) {
            $name = (string) $name;
            $sensitive = \in_array(strtolower($name), self::SENSITIVE_HEADERS, true);
            $redacted[$name] = array_values(array_map(
                static fn(string $value): string => $sensitive ? self::redactValue($value) : $value,
                $values,
            ));
        }

        return $redacted;
    }

    private static function redactValue(string $value): string
    {
        // Keep an auth scheme such as "Bearer" (RFC 9110 token), drop the credentials.
        if (preg_match('/^([A-Za-z][A-Za-z0-9!#$%&\'*+.^_`|~-]*) +\S/', $value, $match) === 1) {
            return $match[1] . ' ' . Secret::REDACTED;
        }

        return Secret::REDACTED;
    }
}
