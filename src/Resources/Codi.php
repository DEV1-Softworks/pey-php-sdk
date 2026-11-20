<?php

declare(strict_types=1);

namespace Dev1\Pey\Resources;

use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\Http\Transport;

final class Codi implements CodiInterface
{
    /**
     * @internal use PeyClient::codi()
     */
    public function __construct(
        private readonly Transport $transport,
    ) {}

    public function createQr(CreateQrRequest $request): QrCode
    {
        return QrCode::fromArray($this->transport->post('/codi/qr', $request->toBody()));
    }

    public function createPushNotification(CreatePushNotificationRequest $request): PushNotification
    {
        return PushNotification::fromArray($this->transport->post('/codi/pn', $request->toBody()));
    }
}
