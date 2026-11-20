<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Money\Amount;

/**
 * Pey's "Transaction Object": the summary of a paid transaction that appears
 * in payment links and webhooks. Use `GET /transaction/{id}` for the full
 * resource, including its status.
 */
final readonly class TransactionSummary
{
    /**
     * @param Amount      $amount         total charged, including tip and fee
     * @param Amount|null $applicationFee retained by Pey; may be null
     */
    public function __construct(
        public string $transactionId,
        public PaymentMethod $paymentMethod,
        public \DateTimeImmutable $datePaid,
        public Amount $amount,
        public ?AmountBreakdown $amountBreakdown,
        public ?Amount $applicationFee,
        public TransactionMetadata $metadata,
    ) {}

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $breakdown = $payload->optionalObject('amountBreakdown');

        return new self(
            $payload->string('transactionId'),
            PaymentMethod::fromApi($payload->string('paymentMethod')),
            $payload->timestamp('datePaid'),
            $payload->amount('amount'),
            $breakdown === null ? null : AmountBreakdown::fromPayload($breakdown),
            $payload->optionalAmount('applicationFee'),
            TransactionMetadata::fromPayload($payload->optionalObject('metadata')),
        );
    }
}
