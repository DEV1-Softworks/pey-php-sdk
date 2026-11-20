<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Response;

use Dev1\Pey\Dto\Payload;

/**
 * One page of a transaction listing, newest first.
 */
final readonly class TransactionPage
{
    /**
     * @param list<Transaction> $items
     * @param string|null       $nextToken pass it back to get the next page; null on the last page
     */
    public function __construct(
        public array $items,
        public ?string $nextToken,
    ) {}

    /**
     * @internal
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = new Payload($data, 'TransactionPage');

        return new self(
            array_map(Transaction::fromPayload(...), $payload->objectList('items')),
            $payload->optionalString('nextToken'),
        );
    }

    public function hasMore(): bool
    {
        return $this->nextToken !== null && $this->nextToken !== '';
    }
}
