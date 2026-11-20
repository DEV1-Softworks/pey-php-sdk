<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    private int $timestamp;

    public function __construct(int $timestamp = 1_795_000_000)
    {
        $this->timestamp = $timestamp;
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->timestamp);
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
