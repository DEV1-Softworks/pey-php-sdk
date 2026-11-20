---
title: Getting started
description: Install the unofficial Pey PHP SDK, configure it, create your first CoDi charge and confirm the payment.
---

# Getting started

> **Unofficial SDK.** `dev1/pey-php-sdk` is built and maintained by DEV1 Softworks Labs. It is not affiliated with, endorsed by, or supported by Pey or Banco de México. The official API reference is at [docs.pey.mx](https://docs.pey.mx).

## Before you start

- **Pey has no sandbox.** Every request goes to production and moves real money. Pey recommends testing with very small amounts, such as $0.01 MXN.
- **Your API key can create charges and register the bank accounts that receive money.** Keep it in an environment variable or a secret manager. Never commit it, log it or send it to an error tracker.
- You need PHP 8.2 or later and a PSR-18 HTTP client. With `guzzlehttp/guzzle` or `symfony/http-client` installed, the SDK configures timeouts for you.

## Install

```bash
composer require dev1/pey-php-sdk guzzlehttp/guzzle
```

Any PSR-18 client and PSR-17 factories work, and they are discovered automatically. Guzzle is preferred because it supports a separate connect timeout.

## Configure

```php
use Dev1\Pey\Config;
use Dev1\Pey\PeyClient;

$pey = PeyClient::create(
    new Config(apiKey: $_ENV['PEY_API_KEY']),
    logger: $psrLogger,   // optional PSR-3 logger; the Authorization header is always redacted
);
```

`Config` options:

| Option | Default | Notes |
|---|---|---|
| `apiKey` | required | Held in a `Secret`: never shown by `var_dump`, `print_r`, `var_export`, logs or exceptions |
| `baseUrl` | `https://api.pey.mx` | HTTPS only. Change it only for a Pey environment other than production, if Pey releases one |
| `connectTimeout` / `timeout` | 5 s / 15 s | Applied to Guzzle and Symfony clients the SDK builds. A client you pass in yourself keeps its own settings |
| `allowedClabes` | `[]` | CLABEs this application may register. Empty blocks every registration (see [Security](security.md)) |
| `retryPolicy` | 2 retries | GET requests only. **POST requests are never retried** |

Type-hint `Dev1\Pey\PeyClientInterface` in your code. In tests you can then pass `Dev1\Pey\Testing\FakePeyClient` instead (see [Testing](testing.md)).

## Money

Amounts are `Dev1\Pey\Money\Amount` values: integer centavos, built from a decimal string or from centavos, and **never from a float**. Extra decimals round half-up, as Pey does.

```php
use Dev1\Pey\Money\Amount;

$total = Amount::fromString('150.00');   // or Amount::fromCentavos(15000)
$total->toDecimalString();               // "150.00"
```

Request objects also accept a decimal string, such as `amount: '150.00'`.

## Create a CoDi QR charge

```php
use Dev1\Pey\Dto\Request\CreateQrRequest;

$qr = $pey->codi()->createQr(new CreateQrRequest(
    amount: '150.00',
    concept: 'Order #12345',
    expiration: 600,                    // seconds; 0 = never, otherwise at least 360
    metadata: ['orderId' => '12345'],   // up to 50 scalar pairs
));

$qr->id;        // Banxico's id (IDC) for this QR; store it with the order
$qr->qrString;  // render this as a QR image for the payer
```

Request objects validate every field when you build them and report all problems at once in a `ValidationException`. Nothing invalid is sent.

To charge a phone number instead, use `$pey->codi()->createPushNotification(new CreatePushNotificationRequest(amount: '150.00', phoneNumber: '5512345678'))`. For a hosted checkout page, use `$pey->paymentLinks()->create(...)`.

To make sure a double click or a queue retry never creates a second charge, send these through [`IdempotentRequests`](idempotency.md).

## Get notified, then confirm the payment

Pey sends a signed webhook when the QR is paid (see [Webhooks](webhooks.md)). **Do not fulfil the order on the webhook alone.** Confirm the payment through the API:

```php
use Dev1\Pey\Enum\PayableType;

$transaction = $pey->transactions()->get($transactionId);   // from the webhook

$paid = $transaction->isPaid()
    && $transaction->payableType === PayableType::CodiQr
    && $transaction->payableId === $order->codiQrId
    && $transaction->amountBreakdown?->base->equals($order->total) === true;
```

Compare `amountBreakdown->base`, not `amount`: the total `amount` also includes any tip and the fee charged to the payer. A QR without expiration can be paid more than once, so fulfil per `transactionId`.

## Errors

Every exception extends `Dev1\Pey\Exception\PeyException`, which offers `httpStatus()`, `errorType()` (Pey's `error.type`) and `requestId()`.

| Exception | When |
|---|---|
| `ValidationException` | Invalid input. `errors()` lists every field. `wasRejectedByPey()` tells a local rejection from a 400 |
| `AuthenticationException` | 401: the API key was rejected |
| `ForbiddenException` | 403: the resource belongs to another API key |
| `NotFoundException` | 404 |
| `ConflictException` | 409, for payment-link updates: `isPaymentLinkNotPending()`, `isConcurrentUpdate()` |
| `RateLimitedException` | 429. `retryAfter()` holds Pey's `Retry-After` header |
| `ServerException`, `ServiceUnavailableException` | 5xx; 503 is maintenance |
| `NetworkException` | No response. **For a POST, check `requestMayHaveBeenProcessed()`**: the charge may exist |
| `UnexpectedResponseException` | Pey answered with something the SDK cannot read |
| `BankAccountNotAllowedException` | The CLABE is not on `allowedClabes`; nothing was sent |

## What next

- [Security](security.md): what the SDK protects, and what remains your responsibility.
- [Webhooks](webhooks.md): verify and process Pey's notifications.
- [Idempotency](idempotency.md): create each charge at most once.
- [Testing](testing.md): test your integration without touching Pey.
- [Known gaps](known-gaps.md): what Pey does not document yet, and how the SDK handles it.
