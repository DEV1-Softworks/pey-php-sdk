<?php

declare(strict_types=1);

namespace Dev1\Pey\Tests\Support;

use Dev1\Pey\Http\Sleeper;

/**
 * Records requested waits instead of sleeping.
 */
final class RecordingSleeper implements Sleeper
{
    /**
     * @var list<int>
     */
    public array $sleeps = [];

    public function sleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
    }
}
