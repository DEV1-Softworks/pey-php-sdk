---
title: Webhooks
description: Verify Pey's signed webhooks, deduplicate them and confirm payments before fulfilling orders.
---

# Webhooks

Pey notifies your application with webhooks signed according to [Standard Webhooks](https://www.standardwebhooks.com). Each API key has one webhook URL and one secret (`whsec_…`), both configured in the Pey portal.

## Verify every request

```php
use Dev1\Pey\Exception\WebhookVerificationException;
use Dev1\Pey\Webhooks\WebhookVerifier;

$verifier = new WebhookVerifier($_ENV['PEY_WEBHOOK_SECRET']);

try {
    // Plain PHP. With a PSR-7 request use $verifier->verifyRequest($request) instead.
    $webhook = $verifier->verify(file_get_contents('php://input'), getallheaders());
} catch (WebhookVerificationException $e) {
    http_response_code(400);   // not provably from Pey: do not process it
    exit;
}
```

`verify()` checks, in this order:

1. the `webhook-id`, `webhook-timestamp` and `webhook-signature` headers (each exactly once);
2. that the timestamp is within **5 minutes** of now, in either direction (configurable, for example `new WebhookVerifier($secret, toleranceSeconds: 120)`). This rejects replays;
3. the signature, computed with the official Standard Webhooks PHP library;
4. only then does it parse the body into a typed event.

Pass the **raw body exactly as received**. Re-encoding parsed JSON changes the bytes and breaks the signature.

To rotate the secret without downtime, pass both secrets while Pey switches over: `new WebhookVerifier([$oldSecret, $newSecret])`.

## Deduplicate deliveries

Pey can deliver the same webhook more than once. `$webhook->id` (the `webhook-id` header) is the deduplication key. Record it with a unique constraint and skip ids you have already processed.

## Handle the events

```php
use Dev1\Pey\Webhooks\Event\BankAccountUpdated;
use Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated;
use Dev1\Pey\Webhooks\Event\UnknownEvent;

match (true) {
    $webhook->event instanceof CodiQrPaymentReceived,
    $webhook->event instanceof CodiPushNotificationPaymentReceived => $orders->confirmPayment($webhook->event->transactionId),
    $webhook->event instanceof PaymentLinkStatusUpdated => $orders->refreshPaymentLink($webhook->event->paymentLinkId),
    $webhook->event instanceof BankAccountUpdated => $accounts->markActive($webhook->event->bankAccountId),
    $webhook->event instanceof UnknownEvent => null,   // authentic, but a type this SDK version does not know
};
```

| Event (`type`) | Class | Highlights |
|---|---|---|
| `codi.qr.payment_received` | `CodiQrPaymentReceived` | `transactionId`, `codiQrId`, `amount`, `metadata->externalMetadata` |
| `codi.pn.payment_received` | `CodiPushNotificationPaymentReceived` | `transactionId` (the one `createPushNotification()` returned) |
| `payment_link.status_updated` | `PaymentLinkStatusUpdated` | `paymentLinkId`, `status`, `clientReferenceId`, `transactions` |
| `bank.account.updated` | `BankAccountUpdated` | sent only when an account becomes `ACTIVE` |

In payment events, `metadata` is Pey's transaction metadata. The metadata you attached at creation is in `metadata->externalMetadata`, with every value as a string.

## Confirm before you fulfil

A webhook tells you something happened. The API tells you what is true. Before shipping goods or crediting an account, confirm the payment:

```php
use Dev1\Pey\Enum\PayableType;

$transaction = $pey->transactions()->get($event->transactionId);

$paid = $transaction->isPaid()
    && $transaction->payableType === PayableType::CodiQr       // or CodiPushNotification / PaymentLink
    && $transaction->payableId === $order->codiQrId
    && $transaction->amountBreakdown?->base->equals($order->total) === true;
```

- Compare `amountBreakdown->base`: the total `amount` includes any tip and the fee charged to the payer.
- A QR without expiration can be paid more than once, so fulfil **per transaction**, not per QR.

## Responding

- Return **2xx** once you have durably recorded the delivery, ideally queueing the work.
- Return **4xx** when verification fails.
- If a verified body cannot be read (`UnexpectedResponseException`), Pey changed something this SDK version does not understand. Alert, and upgrade the SDK.

Pey shows delivery attempts and responses in the portal (API key → gear icon → "Webhook Logs"). Pey does not document its retry schedule (see [Known gaps](known-gaps.md)).

## Testing your endpoint

Sign bodies with a test secret and post them to your endpoint, verified by the real `WebhookVerifier`:

```php
use Dev1\Pey\Testing\WebhookSigner;

$payment = $fakePey->simulateQrPayment($qr->id);
$signed = WebhookSigner::sign($testSecret, $payment->webhookBody);
// post $signed->body with $signed->headers to your endpoint
```

See [Testing](testing.md).
