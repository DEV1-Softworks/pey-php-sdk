<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;

/**
 * Response of `POST /codi/pn`.
 */
final readonly class PushNotification
{
    /**
     * @param string $transactionId ULID of the transaction. Confirm the payment with `GET /transaction/{id}`.
     * @param string $codiId        20-digit CoDi folio assigned by Banxico
     */
    public function __construct(
        public string $transactionId,
        public string $codiId,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'PushNotification');

        return new self($payload->string('transactionId'), $payload->string('codiId'));
    }
}
