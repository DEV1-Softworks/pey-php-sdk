---
title: Testing
description: Test your Pey integration without network access, using the in-memory FakePeyClient and WebhookSigner.
---

# Testing

Pey has no sandbox, so your automated tests should not call Pey at all. The SDK ships an in-memory fake that behaves like the real client where it matters.

## FakePeyClient

`Dev1\Pey\Testing\FakePeyClient` implements `PeyClientInterface`. If your code type-hints the interface, pass the fake in tests:

```php
use Dev1\Pey\Testing\FakePeyClient;

$pey = new FakePeyClient(allowedClabes: ['032180000118359719']);   // the same allowlist as production
$checkout = new Checkout($pey);

$qr = $checkout->start($order);                    // your code calls $pey->codi()->createQr()
$payment = $pey->simulateQrPayment($qr->id);       // the payer pays

self::assertTrue($pey->transactions()->get($payment->transaction->id)->isPaid());
```

The fake:
- **validates like the real client**: the same request objects, id checks and `BankAccountGuard`. A test that passes here does not fail in production on input validation;
- **raises the same exceptions**: `NotFoundException` for unknown ids, `ConflictException` when updating a paid payment link, and so on;
- keeps state: push notifications stay `PENDING` until approved, payment links go from pending to paid, and bank accounts from `PENDING` to `ACTIVE`;
- paginates listings like Pey (newest first, opaque cursors).

### Simulations

| Method | Simulates |
|---|---|
| `simulateQrPayment($qrId, ?$amount)` | a payer paying a QR (an amount is required only for QRs created with amount 0) |
| `simulatePushNotificationPayment($transactionId)` | a payer approving a push notification |
| `simulatePaymentLinkPayment($id, $method, $tip)` | a customer paying a payment link |
| `activateBankAccount($id)` | Banxico activating a registered account |

Each returns the webhook body Pey would send (`$payment->webhookBody`), in the documented shape.

### Failures

```php
use Dev1\Pey\Exception\NetworkException;

$pey->failNext(NetworkException::create('POST', '/codi/qr', false, 'Refused', 'connection refused'));
$pey->failNextAfterProcessing(NetworkException::create('POST', '/codi/pn', true, 'Timeout', 'timed out'));
```

- `failNext()`: the next call fails without doing anything.
- `failNextAfterProcessing()`: the change happens and the call still fails, like a timeout after Pey processed the request. Use it to test your handling of unknown outcomes with [`IdempotentRequests`](idempotency.md).

### Assertions on calls

```php
self::assertCount(1, $pey->callsTo('codi.createQr'));
$pey->recordedCalls();   // every call, in order, with its arguments
```

## WebhookSigner

`Dev1\Pey\Testing\WebhookSigner` signs a body with a **test** secret, so you can post it to your webhook endpoint and verify it with the real `WebhookVerifier`:

```php
use Dev1\Pey\Testing\WebhookSigner;

$signed = WebhookSigner::sign($testSecret, $payment->webhookBody);

$response = $client->post('/webhooks/pey', body: $signed->body, headers: $signed->headers);
```

Give `id` and `timestamp` to make the request deterministic, or to test duplicates and replays.

## Notes

- The fake does not model fees: `amountBreakdown->base` equals the amount, and `fee` is zero.
- Generated ids are deterministic and valid: ULIDs such as `01JFAKE0000000000000000001`, and QR ids such as `FAKE000001`.
- Never use your production webhook secret in tests.
