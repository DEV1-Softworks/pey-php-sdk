<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;

/**
 * Response of `POST /codi/qr` (HTTP 201).
 */
final readonly class QrCode
{
    /**
     * @param string      $id       IDC assigned by Banxico (not a ULID). Use it with `GET /transaction/codi/qr/{id}`.
     * @param string      $qrString payload to encode into a QR image
     * @param string|null $qrImage  base64 PNG, only when generateImage was true; null if Pey could not render it
     */
    public function __construct(
        public string $id,
        public string $qrString,
        public ?string $qrImage = null,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'QrCode');

        return new self($payload->string('id'), $payload->string('qrString'), $payload->optionalString('qrImage'));
    }

    /**
     * The response fields, in the API's shape. `fromArray(toArray())` round-trips.
     *
     * @return array{id: string, qrString: string, qrImage: string|null}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'qrString' => $this->qrString, 'qrImage' => $this->qrImage];
    }
}
