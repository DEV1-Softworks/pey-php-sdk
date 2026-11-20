<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing;

use Dev1\Pey\Dto\Response\Transaction;

/**
 * The outcome of a payment simulated by FakePeyClient.
 */
final readonly class SimulatedPayment
{
    /**
     * @param Transaction $transaction what `transactions()->get()` now returns for it
     * @param string      $webhookBody the webhook Pey would send, as JSON in the documented shape.
     *                                 Sign it with WebhookSigner to test your webhook endpoint.
     */
    public function __construct(
        public Transaction $transaction,
        public string $webhookBody,
    ) {}
}
