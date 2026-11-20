<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;
use Dev1\Pey\Enum\PaymentLinkStatus;
use Dev1\Pey\Enum\PaymentMethod;
use Dev1\Pey\Money\Amount;

/**
 * Response of `GET /payment-link/{id}`.
 */
final readonly class PaymentLink
{
    /**
     * @param list<PaymentMethod>      $paymentMethods methods the customer can pay with
     * @param array<string, string>    $metadata       your metadata, with every value as a string
     * @param list<TransactionSummary> $transactions   paid transactions for this link
     */
    public function __construct(
        public string $id,
        public Amount $amount,
        public PaymentLinkStatus $status,
        public string $successUrl,
        public string $backUrl,
        public bool $hasTip,
        public array $paymentMethods,
        public \DateTimeImmutable $dateCreated,
        public ?string $clientReferenceId,
        public array $metadata,
        public array $transactions,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'PaymentLink');

        return new self(
            $payload->string('id'),
            $payload->amount('amount'),
            PaymentLinkStatus::fromApi($payload->string('status')),
            $payload->string('successUrl'),
            $payload->string('backUrl'),
            $payload->bool('hasTip'),
            array_map(PaymentMethod::fromApi(...), $payload->stringList('paymentMethods')),
            $payload->timestamp('dateCreated'),
            $payload->optionalString('clientReferenceId'),
            $payload->stringMap('metadata'),
            array_map(TransactionSummary::fromPayload(...), $payload->objectList('transactions')),
        );
    }
}
