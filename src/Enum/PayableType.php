<?php

declare(strict_types=1);

namespace Dev1\Pey\Enum;

/**
 * Kind of resource a transaction paid for.
 *
 * Unknown stands for any value Pey adds after this SDK was released, so a new
 * value never breaks response parsing. Code that acts on a specific case (for
 * example "fulfil only when PAID") therefore treats new values safely.
 */
enum PayableType: string
{
    case PaymentLink = 'PAYMENT_LINK';
    case CodiQr = 'API_CODI_QR';
    case CodiPushNotification = 'API_CODI_PN';

    case Unknown = '';

    /**
     * Maps a value received from Pey, falling back to Unknown.
     */
    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
