<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing;

/**
 * A webhook request body and headers signed like Pey signs them.
 */
final readonly class SignedWebhook
{
    /**
     * @param array{'webhook-id': string, 'webhook-timestamp': string, 'webhook-signature': string} $headers
     */
    public function __construct(
        public string $id,
        public string $body,
        public array $headers,
    ) {}
}
