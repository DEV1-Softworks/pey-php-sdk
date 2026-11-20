<?php

declare(strict_types=1);

namespace Dev1\Pey\Testing;

use StandardWebhooks\Webhook;

/**
 * Signs webhook bodies with a test secret, so tests can exercise a webhook
 * endpoint end to end with the real WebhookVerifier.
 *
 * ```php
 * $payment = $fake->simulateQrPayment($qr->id);
 * $signed = WebhookSigner::sign('whsec_…test secret…', $payment->webhookBody);
 * $response = $this->post('/webhooks/pey', $signed->body, $signed->headers);
 * ```
 *
 * Use a test secret only, never your production one.
 */
final class WebhookSigner
{
    /**
     * @param string      $secret    a `whsec_…` test secret
     * @param string|null $id        the webhook-id (random when null)
     * @param int|null    $timestamp Unix seconds (now when null)
     */
    public static function sign(#[\SensitiveParameter] string $secret, string $body, ?string $id = null, ?int $timestamp = null): SignedWebhook
    {
        $id ??= 'msg_' . bin2hex(random_bytes(12));
        $timestamp ??= time();

        $signature = (new Webhook($secret))->sign($id, $timestamp, $body);
        if (!\is_string($signature)) {
            throw new \LogicException('The Standard Webhooks library returned an unexpected signature.');
        }

        return new SignedWebhook($id, $body, [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => $signature,
        ]);
    }
}
