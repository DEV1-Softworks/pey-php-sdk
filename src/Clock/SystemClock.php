<?php

declare(strict_types=1);

namespace Dev1\Pey\Clock;

use Psr\Clock\ClockInterface;

/**
 * The real clock, in UTC. Inject another PSR-20 clock (for example a frozen
 * one in tests) wherever the SDK accepts a ClockInterface.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
