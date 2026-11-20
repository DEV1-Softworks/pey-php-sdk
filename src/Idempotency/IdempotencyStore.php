<?php

declare(strict_types=1);

namespace Dev1\Pey\Idempotency;

/**
 * Storage for idempotency records, shared by every process that can create
 * charges for the same Pey account.
 *
 * Records are opaque strings (JSON written by the SDK). They are usually under
 * a kilobyte, but can reach tens of kilobytes when a QR was created with
 * `generateImage`. Keys are at most 120 ASCII characters.
 *
 * Contract for implementations (framework bridges provide real ones, for
 * example on Laravel's cache or Redis):
 *
 * - acquire() MUST be atomic across all processes: "store only if absent",
 *   like Redis `SET key value NX EX ttl` or Laravel `Cache::add()`. Without
 *   atomicity two processes can both send the same charge.
 * - Records must survive at least $ttlSeconds. Evicting a record early can
 *   allow a duplicate charge, so use a store that does not evict under memory
 *   pressure (for example a dedicated Redis database with `noeviction`, or a
 *   database table).
 * - release() and remember() on a missing key must not fail.
 * - Implementations should throw on storage errors rather than report
 *   success. The SDK treats a failing acquire() as "do not send".
 */
interface IdempotencyStore
{
    /**
     * Stores $record under $key only if the key is absent (or expired).
     *
     * @return bool true when the record was stored, false when the key already holds a record
     */
    public function acquire(string $key, string $record, int $ttlSeconds): bool;

    /**
     * Replaces the record of a key that was acquired.
     */
    public function remember(string $key, string $record, int $ttlSeconds): void;

    /**
     * Deletes the record, so the key can be acquired again.
     */
    public function release(string $key): void;

    /**
     * The current record, or null when the key is absent or expired.
     */
    public function recall(string $key): ?string;
}
