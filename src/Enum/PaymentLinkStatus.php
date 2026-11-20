<?php

declare(strict_types=1);

namespace Dev1\Pey\Enum;

/**
 * Status of a payment link. Values are lower-case in the API.
 *
 * TODO(pey-docs): the meaning of `partial` is undocumented, and the
 * payment-link webhook page lists only pending, processing and paid.
 *
 * Unknown stands for any value Pey adds after this SDK was released, so a new
 * value never breaks response parsing. Code that acts on a specific case (for
 * example "fulfil only when PAID") therefore treats new values safely.
 */
enum PaymentLinkStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Partial = 'partial';

    case Unknown = '';

    /**
     * Maps a value received from Pey, falling back to Unknown.
     */
    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
