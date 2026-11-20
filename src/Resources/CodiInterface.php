<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Exception\PeyException;

/**
 * CoDi charges: QR codes and push notifications.
 *
 * Both calls are POSTs and are never retried automatically: Pey has no
 * idempotency keys, so a retry could create a second charge. On a
 * NetworkException (for a POST, requestMayHaveBeenProcessed() is always
 * true), check the outcome (for example with the transaction listings)
 * before trying again.
 */
interface CodiInterface
{
    /**
     * Creates a CoDi QR code (`POST /codi/qr`).
     *
     * @throws PeyException
     */
    public function createQr(CreateQrRequest $request): QrCode;

    /**
     * Sends a CoDi charge request to the payer's phone (`POST /codi/pn`).
     *
     * @throws PeyException
     */
    public function createPushNotification(CreatePushNotificationRequest $request): PushNotification;
}
