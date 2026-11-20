<?php

declare(strict_types=1);

namespace Dev1\Pey\Validation;

/**
 * Identifiers whose format Pey does not document: CoDi QR ids (Banxico IDCs
 * such as `AB12CD34EF`) and pagination cursors (`nextToken`, base64-like).
 *
 * Only basic sanity is checked: non-empty, printable ASCII without spaces, and
 * a generous maximum length. Path and query values are URL-encoded anyway.
 *
 * TODO(pey-docs): the formats of the CoDi QR id (IDC) and of nextToken are undocumented.
 */
final class OpaqueId
{
    public const DEFAULT_MAX_LENGTH = 4096;

    public static function isValid(string $value, int $maxLength = self::DEFAULT_MAX_LENGTH): bool
    {
        return preg_match('/^[\x21-\x7E]+$/D', $value) === 1 && \strlen($value) <= $maxLength;
    }

    public static function check(string $value, string $field, Violations $violations, int $maxLength = self::DEFAULT_MAX_LENGTH): void
    {
        if (!self::isValid($value, $maxLength)) {
            $violations->add($field, \sprintf('must be 1 to %d printable characters without spaces.', $maxLength));
        }
    }
}
