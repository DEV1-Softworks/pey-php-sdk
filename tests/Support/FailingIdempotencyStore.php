<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Dev1\Pey\Idempotency\IdempotencyStore;

/**
 * Wraps a store and makes chosen operations throw, like an unreachable cache.
 */
final class FailingIdempotencyStore implements IdempotencyStore
{
    /**
     * @var list<string>
     */
    public array $failing = [];

    public function __construct(
        public readonly IdempotencyStore $inner,
    ) {}

    public function acquire(string $key, string $record, int $ttlSeconds): bool
    {
        $this->maybeFail('acquire');

        return $this->inner->acquire($key, $record, $ttlSeconds);
    }

    public function remember(string $key, string $record, int $ttlSeconds): void
    {
        $this->maybeFail('remember');
        $this->inner->remember($key, $record, $ttlSeconds);
    }

    public function release(string $key): void
    {
        $this->maybeFail('release');
        $this->inner->release($key);
    }

    public function recall(string $key): ?string
    {
        $this->maybeFail('recall');

        return $this->inner->recall($key);
    }

    private function maybeFail(string $operation): void
    {
        if (\in_array($operation, $this->failing, true)) {
            throw new \RuntimeException('Store unavailable during ' . $operation);
        }
    }
}
