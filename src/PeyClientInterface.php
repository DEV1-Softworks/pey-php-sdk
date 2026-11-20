<?php

declare(strict_types=1);

namespace Dev1\Pey;

use Dev1\Pey\Resources\CodiInterface;

/**
 * Entry point to the Pey API. Type-hint this interface in your code, so tests
 * can substitute the in-memory fake.
 */
interface PeyClientInterface
{
    public function codi(): CodiInterface;
}
