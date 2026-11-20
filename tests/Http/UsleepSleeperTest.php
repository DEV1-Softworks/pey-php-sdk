<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Http;

use Dev1\Pey\Http\UsleepSleeper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsleepSleeper::class)]
final class UsleepSleeperTest extends TestCase
{
    public function testItWaitsAtLeastTheRequestedTime(): void
    {
        $sleeper = new UsleepSleeper();

        $start = hrtime(true);
        $sleeper->sleep(0);
        $sleeper->sleep(-5);
        $sleeper->sleep(2);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        self::assertGreaterThanOrEqual(2, $elapsedMs);
    }
}
