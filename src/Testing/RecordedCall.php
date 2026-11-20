<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing;

/**
 * One call made through FakePeyClient, for assertions in tests.
 */
final readonly class RecordedCall
{
    /**
     * @param string      $method    for example "codi.createQr" or "transactions.get"
     * @param list<mixed> $arguments the arguments, as passed (request objects, ids, cursors)
     */
    public function __construct(
        public string $method,
        public array $arguments,
    ) {}
}
