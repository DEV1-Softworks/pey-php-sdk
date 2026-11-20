<?php

declare(strict_types=1);

namespace Dev1\Pey\Http;

/**
 * Members that Json::encode() always writes as a JSON object, even when the
 * PHP array is empty or looks like a list.
 *
 * PHP turns numeric string keys into integers, so `['0' => 'x']` becomes a
 * list and would otherwise be encoded as `["x"]`. Metadata must always be an
 * object.
 *
 * @internal
 */
final readonly class JsonObject
{
    /**
     * @param array<array-key, mixed> $members keys are written as strings
     */
    public function __construct(
        public array $members,
    ) {}
}
