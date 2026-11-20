<?php

declare(strict_types=1);

namespace Dev1\Pey\Webhooks\Event;

/**
 * An event delivered by a Pey webhook. Events come only from
 * WebhookVerifier, built from a body whose signature was verified.
 */
interface WebhookEvent
{
    /**
     * The event type, for example `codi.qr.payment_received`.
     */
    public function type(): string;
}
