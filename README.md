# Pey PHP SDK (unofficial)

[![CI](https://github.com/DEV1-Softworks/pey-php-sdk/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/DEV1-Softworks/pey-php-sdk/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**Unofficial PHP SDK for the Pey API.** It covers CoDi® payments in Mexico: QR charges, push-notification charges, payment links, bank accounts, transactions and signed webhooks.

> [!IMPORTANT]
> This SDK is built and maintained by DEV1 Softworks Labs. It is **not affiliated with, endorsed by, or supported by Pey or Banco de México**. CoDi® is a registered trademark of Banco de México. The official API reference is at [docs.pey.mx](https://docs.pey.mx).

> [!WARNING]
> **Pre-release.** The SDK is in active development towards `v0.1.0`, and its API may change before then. Do not use it in production yet.

## Before you integrate

- **Production only.** Pey has no sandbox. Every request to `https://api.pey.mx` creates real charges and moves real money, so test with small amounts.
- **The API key can move money and register bank accounts.** Treat it like a password. Keep it out of source control, logs and error trackers.

## Planned for v0.1.0

- CoDi QR and push-notification charges, payment links, bank accounts, and transactions with paginated listing.
- Local validation before any request is sent: CLABE check digit, amounts, ULIDs, `numRef`, metadata limits, HTTPS URLs and expiration rules.
- Money as integer centavos (`Amount`), never `float`.
- Safe retries: POST requests are never retried automatically. GET requests retry with backoff on network errors, 429 and 503.
- Bank account registration is blocked unless the CLABE is on an explicit allowlist.
- Webhook verification with the official [Standard Webhooks](https://www.standardwebhooks.com) library, returning typed events.
- An in-memory `FakePeyClient` for your own tests.
- Framework agnostic: works with any PSR-18 HTTP client and PSR-17 factories, auto-discovered. Laravel and Symfony integrations will ship as separate packages.

## Requirements

- PHP 8.2 or later.
- A PSR-18 HTTP client and PSR-17 factories, for example `symfony/http-client` with `nyholm/psr7`, or `guzzlehttp/guzzle`.

## Installation

The package is not on Packagist yet. Once `v0.1.0` is released:

```bash
composer require dev1/pey-php-sdk
```

## Development

```bash
composer install
composer test           # PHPUnit
composer test:coverage  # PHPUnit with coverage thresholds (needs pcov or Xdebug)
composer analyse        # PHPStan: level max, strict rules, no baseline
composer cs             # PHP-CS-Fixer check (PER-CS); `composer cs:fix` applies fixes
```

Tests never make real HTTP calls.

`master` is the production branch and `develop` is the integration branch. Work happens in `feature/<name>` branches cut from `develop` and merged back through pull requests.

## License

MIT. See [LICENSE](LICENSE).
