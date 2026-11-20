<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Clock;

use Dev1\Pey\Clock\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testItReturnsTheCurrentTimeInUtc(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $after = time();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual($after, $now->getTimestamp());
    }
}
