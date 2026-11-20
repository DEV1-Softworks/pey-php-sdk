<?php

declare(strict_types=1);

namespace Dev1\Pey\Dto\Request;

/**
 * A validated request body. Request objects validate every field when they
 * are built, so an instance is always safe to send.
 */
interface RequestBody
{
    /**
     * The JSON body. Amounts are JsonNumber literals and metadata is a
     * JsonObject; absent optional fields are omitted.
     *
     * @return array<string, mixed>
     */
    public function toBody(): array;
}
