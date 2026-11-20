<?php

declare(strict_types=1);

namespace Dev1\Pey\Enum;

/**
 * CoDi registration status of a bank account registered through the API.
 *
 * PENDING: received, not yet sent to Banxico; PROCESSING: registration in
 * progress; ACTIVE: can receive CoDi payments; ERROR: registration failed (see
 * the account's errorMessage).
 *
 * Unknown stands for any value Pey adds after this SDK was released, so a new
 * value never breaks response parsing. Code that acts on a specific case (for
 * example "fulfil only when PAID") therefore treats new values safely.
 */
enum CodiStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Active = 'ACTIVE';
    case Error = 'ERROR';

    case Unknown = '';

    /**
     * Maps a value received from Pey, falling back to Unknown.
     */
    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
