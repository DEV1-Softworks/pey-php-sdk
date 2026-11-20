<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks\Event;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Money\Amount;

/**
 * `codi.pn.payment_received`: a CoDi push notification was paid.
 *
 * Do not fulfil the order on this event alone. Confirm the payment with
 * `$pey->transactions()->get($event->transactionId)`: status PAID, payableType
 * API_CODI_PN, and `amountBreakdown->base` equal to the order total. The
 * transactionId is the one createPushNotification() returned. Deduplicate
 * deliveries with the webhook id.
 *
 * @see https://docs.pey.mx/en/docs/webhooks/codi/pn
 */
final readonly class CodiPushNotificationPaymentReceived implements WebhookEvent
{
    public const TYPE = 'codi.pn.payment_received';

    /**
     * @param string|null $bankAccountId present only when the push notification was created with a bankAccountId
     */
    public function __construct(
        public Amount $amount,
        public string $apiKeyId,
        public ?string $bankAccountId,
        public ?string $concept,
        public \DateTimeImmutable $datePaid,
        public TransactionMetadata $metadata,
        public ?string $numRef,
        public string $transactionId,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->amount('amount'),
            $payload->string('apiKeyId'),
            $payload->optionalString('bankAccountId'),
            $payload->optionalString('concept'),
            $payload->timestamp('datePaid'),
            TransactionMetadata::fromPayload($payload->optionalObject('metadata')),
            $payload->optionalString('numRef'),
            $payload->string('transactionId'),
        );
    }

    public function type(): string
    {
        return self::TYPE;
    }
}
