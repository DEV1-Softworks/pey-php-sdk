<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    private readonly \DateTimeImmutable $now;

    public function __construct(string $now = '2026-11-20T12:00:00+00:00')
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
