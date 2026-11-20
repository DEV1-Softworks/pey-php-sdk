<?php

declare(strict_types=1);

namespace Dev1\Pey\Idempotency;

/**
 * Operations protected by IdempotentRequests. Each one has its own key
 * namespace, so the same key can be used for a QR and a push notification of
 * the same order without colliding.
 */
enum Operation: string
{
    case CodiQr = 'codi.qr';
    case CodiPushNotification = 'codi.pn';
    case PaymentLink = 'payment-link.create';
}
