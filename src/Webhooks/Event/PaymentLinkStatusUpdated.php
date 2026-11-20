<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks\Event;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Response\TransactionSummary;
use Dev1\Pey\Enum\PaymentLinkStatus;

/**
 * `payment_link.status_updated`: a payment link changed status.
 *
 * Do not fulfil the order on this event alone. Confirm with
 * `$pey->paymentLinks()->get($event->paymentLinkId)`, or with each
 * transaction through `$pey->transactions()->get()`. Deduplicate deliveries
 * with the webhook id.
 *
 * TODO(pey-docs): the webhook page lists the statuses paid, pending and
 * processing; `partial` (seen in GET /payment-link) is undocumented here.
 *
 * @see https://docs.pey.mx/en/docs/webhooks/payment-link/status-update
 */
final readonly class PaymentLinkStatusUpdated implements WebhookEvent
{
    public const TYPE = 'payment_link.status_updated';

    /**
     * @param list<TransactionSummary> $transactions transactions settled against the link
     */
    public function __construct(
        public string $apiKeyId,
        public string $paymentLinkId,
        public PaymentLinkStatus $status,
        public ?string $clientReferenceId,
        public array $transactions,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        return new self(
            $payload->string('apiKeyId'),
            $payload->string('paymentLinkId'),
            PaymentLinkStatus::fromApi($payload->string('status')),
            $payload->optionalString('clientReferenceId'),
            array_map(TransactionSummary::fromPayload(...), $payload->objectList('transactions')),
        );
    }

    public function type(): string
    {
        return self::TYPE;
    }
}
