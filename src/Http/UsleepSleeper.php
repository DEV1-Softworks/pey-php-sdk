<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

/**
 * @internal
 */
final class UsleepSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
