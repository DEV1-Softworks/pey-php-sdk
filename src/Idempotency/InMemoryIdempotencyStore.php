<?php

declare(strict_types=1);

namespace Dev1\Pey\Idempotency;

use Dev1\Pey\Clock\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * In-process store, for tests and single-process scripts.
 *
 * It does NOT protect against duplicates across processes, workers or
 * servers. Use a shared store (provided by the framework bridges) in production.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /**
     * @var array<string, array{record: string, expiresAt: int}>
     */
    private array $entries = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function acquire(string $key, string $record, int $ttlSeconds): bool
    {
        if ($this->recall($key) !== null) {
            return false;
        }

        $this->entries[$key] = ['record' => $record, 'expiresAt' => $this->now() + $ttlSeconds];

        return true;
    }

    public function remember(string $key, string $record, int $ttlSeconds): void
    {
        $this->entries[$key] = ['record' => $record, 'expiresAt' => $this->now() + $ttlSeconds];
    }

    public function release(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function recall(string $key): ?string
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAt'] <= $this->now()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['record'];
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
