<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks;

use Dev1\Pey\Webhooks\Event\WebhookEvent;

/**
 * A webhook whose signature and timestamp were verified.
 *
 * Pey may deliver the same webhook more than once. Use `$id` (the
 * `webhook-id` header) to process each delivery only once, for example with
 * a unique database constraint.
 */
final readonly class VerifiedWebhook
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $timestamp,
        public WebhookEvent $event,
    ) {}
}
