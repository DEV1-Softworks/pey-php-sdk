<?php

declare(strict_types=1);

namespace Dev1\Pey\Enum;

/**
 * Status of a transaction.
 *
 * Unknown stands for any value Pey adds after this SDK was released, so a new
 * value never breaks response parsing. Code that acts on a specific case (for
 * example "fulfil only when PAID") therefore treats new values safely.
 */
enum TransactionStatus: string
{
    case Pending = 'PENDING';
    case Paid = 'PAID';

    case Unknown = '';

    /**
     * Maps a value received from Pey, falling back to Unknown.
     */
    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
