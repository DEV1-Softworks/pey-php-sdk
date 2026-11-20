<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Idempotency;

use Dev1\Pey\Clock\SystemClock;
use Dev1\Pey\Idempotency\InMemoryIdempotencyStore;
use Dev1\Pey\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryIdempotencyStore::class)]
#[UsesClass(SystemClock::class)]
final class InMemoryIdempotencyStoreTest extends TestCase
{
    private MutableClock $clock;

    private InMemoryIdempotencyStore $store;

    protected function setUp(): void
    {
        $this->clock = new MutableClock();
        $this->store = new InMemoryIdempotencyStore($this->clock);
    }

    public function testAcquireStoresOnlyWhenAbsent(): void
    {
        self::assertTrue($this->store->acquire('k', 'first', 60));
        self::assertFalse($this->store->acquire('k', 'second', 60));
        self::assertSame('first', $this->store->recall('k'));
    }

    public function testRememberReplacesTheRecord(): void
    {
        $this->store->acquire('k', 'started', 60);
        $this->store->remember('k', 'completed', 60);

        self::assertSame('completed', $this->store->recall('k'));
    }

    public function testReleaseFreesTheKey(): void
    {
        $this->store->acquire('k', 'started', 60);
        $this->store->release('k');
        $this->store->release('missing');

        self::assertNull($this->store->recall('k'));
        self::assertTrue($this->store->acquire('k', 'again', 60));
    }

    public function testRecordsExpire(): void
    {
        $this->store->acquire('k', 'started', 60);

        $this->clock->advance(59);
        self::assertSame('started', $this->store->recall('k'));
        self::assertFalse($this->store->acquire('k', 'other', 60));

        $this->clock->advance(1);
        self::assertNull($this->store->recall('k'));
        self::assertTrue($this->store->acquire('k', 'other', 60));
    }

    public function testRememberRenewsTheTtl(): void
    {
        $this->store->acquire('k', 'started', 10);
        $this->clock->advance(5);
        $this->store->remember('k', 'completed', 100);
        $this->clock->advance(50);

        self::assertSame('completed', $this->store->recall('k'));
    }

    public function testKeysAreIndependent(): void
    {
        $this->store->acquire('a', 'A', 60);

        self::assertTrue($this->store->acquire('b', 'B', 60));
        self::assertSame('A', $this->store->recall('a'));
        self::assertNull($this->store->recall('c'));
    }

    public function testItUsesTheSystemClockByDefault(): void
    {
        $store = new InMemoryIdempotencyStore();

        self::assertTrue($store->acquire('k', 'v', 60));
        self::assertSame('v', $store->recall('k'));
    }
}
