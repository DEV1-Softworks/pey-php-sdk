<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

/**
 * Waits between retries. Tests inject a recording implementation so they
 * never actually sleep.
 *
 * @internal
 */
interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
