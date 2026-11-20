<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Enum\PayableType;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Enum\TransactionStatus;
use Dev1\Pey\Money\Amount;

/**
 * A transaction, as returned by `GET /transaction/{id}` and the transaction
 * listings.
 *
 * Before fulfilling an order, confirm the payment with this resource: status
 * PAID, the expected payableType and payableId, and `amountBreakdown->base`
 * equal to the order total.
 */
final readonly class Transaction
{
    /**
     * @param Amount           $amount      total, including tip and fee
     * @param string           $currency    ISO 4217 code
     * @param PayableType|null $payableType absent in CoDi QR transaction listings
     * @param string|null      $payableId   ULID, or the Banxico IDC for CoDi QRs
     */
    public function __construct(
        public string $id,
        public TransactionStatus $status,
        public Amount $amount,
        public ?AmountBreakdown $amountBreakdown,
        public string $currency,
        public PaymentMethod $paymentMethod,
        public ?PayableType $payableType,
        public ?string $payableId,
        public ?string $description,
        public ?Amount $applicationFee,
        public \DateTimeImmutable $dateCreated,
        public ?\DateTimeImmutable $datePaid,
        public TransactionMetadata $metadata,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromPayload(new Payload($data, 'Transaction'));
    }

    /**
     * @internal
     */
    public static function fromPayload(Payload $payload): self
    {
        $breakdown = $payload->optionalObject('amountBreakdown');
        $payableType = $payload->optionalString('payableType');

        return new self(
            $payload->string('id'),
            TransactionStatus::fromApi($payload->string('status')),
            $payload->amount('amount'),
            $breakdown === null ? null : AmountBreakdown::fromPayload($breakdown),
            $payload->string('currency'),
            PaymentMethod::fromApi($payload->string('paymentMethod')),
            $payableType === null ? null : PayableType::fromApi($payableType),
            $payload->optionalString('payableId'),
            $payload->optionalString('description'),
            $payload->optionalAmount('applicationFee'),
            $payload->timestamp('dateCreated'),
            $payload->optionalTimestamp('datePaid'),
            TransactionMetadata::fromPayload($payload->optionalObject('metadata')),
        );
    }

    public function isPaid(): bool
    {
        return $this->status === TransactionStatus::Paid;
    }
}
