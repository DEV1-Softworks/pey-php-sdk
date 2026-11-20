<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks\Event;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Dto\Response\TransactionMetadata;
use Dev1\Pey\Money\Amount;

/**
 * `codi.qr.payment_received`: a CoDi QR created through the API was paid.
 *
 * Do not fulfil the order on this event alone. Confirm the payment with
 * `$pey->transactions()->get($event->transactionId)`: status PAID, payableType
 * API_CODI_QR, payableId equal to the QR's id, and `amountBreakdown->base`
 * equal to the order total. A QR without expiration can be paid more than
 * once, so fulfil per transactionId, and deduplicate deliveries with the
 * webhook id.
 *
 * @see https://docs.pey.mx/en/docs/webhooks/codi/qr
 */
final readonly class CodiQrPaymentReceived implements WebhookEvent
{
    public const TYPE = 'codi.qr.payment_received';

    /**
     * @param string|null $bankAccountId present only when the QR was created with a bankAccountId
     * @param string      $codiQrId      the QR's IDC assigned by Banxico
     */
    public function __construct(
        public Amount $amount,
        public string $apiKeyId,
        public ?string $bankAccountId,
        public string $codiQrId,
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
            $payload->string('codiQrId'),
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
