<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks\Event;

/**
 * A verified event of a type this SDK version does not model, for example
 * one Pey added later. The body is authentic; only its shape is unknown.
 * Acknowledge it (2xx) so Pey stops retrying, and upgrade the SDK to handle it.
 */
final readonly class UnknownEvent implements WebhookEvent
{
    /**
     * @param array<array-key, mixed> $payload the verified body; JSON numbers are decimal strings
     */
    public function __construct(
        public string $eventType,
        public array $payload,
    ) {}

    public function type(): string
    {
        return $this->eventType;
    }
}
