# Pey PHP SDK (unofficial)

[![CI](https://github.com/DEV1-Softworks/pey-php-sdk/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/DEV1-Softworks/pey-php-sdk/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**Unofficial PHP SDK for the Pey API.** It covers CoDi® payments in Mexico: QR charges, push-notification charges, payment links, bank accounts, transactions and signed webhooks. It is framework-agnostic: any PSR-18 HTTP client works.

> [!IMPORTANT]
> This SDK is built and maintained by DEV1 Softworks Labs. It is **not affiliated with, endorsed by, or supported by Pey or Banco de México**. CoDi® is a registered trademark of Banco de México. The official API reference is at [docs.pey.mx](https://docs.pey.mx).

> [!WARNING]
> **Pey has no sandbox.** Every request goes to production and moves real money, so test with small amounts. Your API key can create charges and register the bank accounts that receive money: treat it like a password.

## Installation

Requires PHP 8.2+ and a PSR-18 HTTP client. With Guzzle or Symfony HttpClient installed, timeouts are configured for you.

```bash
composer require dev1/pey-php-sdk guzzlehttp/guzzle
```

## Quick start

### 1. Create a CoDi QR charge

```php
use Dev1\Pey\Config;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\PeyClient;

$pey = PeyClient::create(new Config(apiKey: $_ENV['PEY_API_KEY']));

$qr = $pey->codi()->createQr(new CreateQrRequest(
    amount: '150.00',                    // money is never a float
    concept: 'Order #12345',
    expiration: 600,
    metadata: ['orderId' => '12345'],
));

$order->codiQrId = $qr->id;              // keep Banxico's QR id with the order
// Render $qr->qrString as a QR image for the payer.
```

Wrap charge creation in [`IdempotentRequests`](docs/idempotency.md) so a double click or a retried job never charges twice.

### 2. Verify the webhook

```php
use Dev1\Pey\Exception\WebhookVerificationException;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\WebhookVerifier;

$verifier = new WebhookVerifier($_ENV['PEY_WEBHOOK_SECRET']);

try {
    $webhook = $verifier->verify(file_get_contents('php://input'), getallheaders());
} catch (WebhookVerificationException) {
    http_response_code(400);
    exit;
}

// Pey may deliver the same webhook twice: process each $webhook->id only once.
if ($webhook->event instanceof CodiQrPaymentReceived) {
    $transactionId = $webhook->event->transactionId;
}
```

### 3. Confirm the payment before fulfilling the order

A webhook says something happened. `GET /transaction/{id}` tells you what is true:

```php
use Dev1\Pey\Enum\PayableType;

$transaction = $pey->transactions()->get($transactionId);

$paid = $transaction->isPaid()
    && $transaction->payableType === PayableType::CodiQr
    && $transaction->payableId === $order->codiQrId
    && $transaction->amountBreakdown?->base->equals($order->total) === true;   // base excludes tip and fee

if ($paid) {
    $order->fulfil($transaction->id);    // per transaction: a QR without expiration can be paid twice
}
```

## Documentation

- [Getting started](docs/getting-started.md): configuration, money, charges, errors.
- [Security](docs/security.md): what the SDK protects by default, and what is up to you.
- [Webhooks](docs/webhooks.md): verification, deduplication, events, responses.
- [Idempotency](docs/idempotency.md): create each charge at most once, and reconcile unknown outcomes.
- [Testing](docs/testing.md): the in-memory `FakePeyClient` and `WebhookSigner`.
- [Known gaps](docs/known-gaps.md): what Pey does not document yet, and how the SDK copes.

## What the SDK guarantees

- **Secrets never leak**: not into dumps, logs, exceptions or serialized objects.
- **POST requests are never retried.** GET requests retry with backoff on network errors, 429 and 503.
- **Money is integer centavos**, never a float, rounded half-up like Pey.
- **Input is validated before anything is sent**: CLABE check digit, amounts, ids, `numRef`, metadata, HTTPS URLs, expirations.
- **Bank accounts can be registered only from an explicit CLABE allowlist**, which is empty by default.
- **Webhooks are verified** with the official [Standard Webhooks](https://www.standardwebhooks.com) library before their body is read.
- **TLS verification is always on.**

## Development

```bash
composer install
composer test           # PHPUnit (never makes real HTTP calls)
composer test:coverage  # with coverage thresholds (needs pcov or Xdebug)
composer analyse        # PHPStan: level max, strict rules, no baseline
composer cs             # PHP-CS-Fixer check (PER-CS); `composer cs:fix` applies fixes
```

API reference: download [Doctum](https://github.com/code-lts/doctum)'s PHAR and run `php doctum.phar update doctum.php`. The output goes to `build/api/`.

`master` is the production branch and `develop` the integration branch. Work happens in `feature/<name>` branches cut from `develop`. The project follows [Semantic Versioning](https://semver.org): before 1.0, minor versions may change the API.

## License

MIT. See [LICENSE](LICENSE).
