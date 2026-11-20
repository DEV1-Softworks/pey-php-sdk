<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Http\Json;
use Dev1\Pey\Webhooks\Event\BankAccountUpdated;
use Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated;
use Dev1\Pey\Webhooks\Event\UnknownEvent;
use Dev1\Pey\Webhooks\Event\WebhookEvent;

/**
 * Builds typed events from a webhook body.
 *
 * Call it only with a body that WebhookVerifier has verified: an unverified
 * body is attacker-controlled. WebhookVerifier calls it for you.
 *
 * @internal
 */
final class EventFactory
{
    /**
     * @throws UnexpectedResponseException when the body is not a JSON object with a string type, or a known event has invalid fields
     */
    public static function fromVerifiedBody(string $rawBody): WebhookEvent
    {
        try {
            $data = Json::decode($rawBody);
        } catch (\JsonException) {
            throw UnexpectedResponseException::invalidField('WebhookEvent', 'body', 'a JSON object');
        }

        if ($data !== [] && array_is_list($data)) {
            throw UnexpectedResponseException::invalidField('WebhookEvent', 'body', 'a JSON object');
        }

        $type = (new Payload($data, 'WebhookEvent'))->string('type');

        return match ($type) {
            CodiQrPaymentReceived::TYPE => CodiQrPaymentReceived::fromPayload(new Payload($data, 'CodiQrPaymentReceived')),
            CodiPushNotificationPaymentReceived::TYPE => CodiPushNotificationPaymentReceived::fromPayload(new Payload($data, 'CodiPushNotificationPaymentReceived')),
            PaymentLinkStatusUpdated::TYPE => PaymentLinkStatusUpdated::fromPayload(new Payload($data, 'PaymentLinkStatusUpdated')),
            BankAccountUpdated::TYPE => BankAccountUpdated::fromPayload(new Payload($data, 'BankAccountUpdated')),
            default => new UnknownEvent($type, $data),
        };
    }
}
