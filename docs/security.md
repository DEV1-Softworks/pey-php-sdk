---
title: Security
description: What the Pey PHP SDK protects by default, and what your application remains responsible for.
---

# Security

Pey's API is production-only, and one API key can create charges and register the bank accounts that receive money. The SDK makes the dangerous mistakes hard. Some protections, though, only work if your application plays its part.

## What the SDK does for you

### Secrets never leak

The API key and webhook secrets are held in `Dev1\Pey\Security\Secret`. The value is kept outside the object, so `var_dump`, `print_r`, `var_export`, `json_encode`, array casts and error trackers have nothing to show. Serializing or cloning a secret throws.

Parameters that receive secrets are marked `#[\SensitiveParameter]`, so stack traces show `SensitiveParameterValue` instead. Logged requests show `Authorization: Bearer [REDACTED]`, and request and response bodies are never logged. Exceptions never contain the key, request headers or bodies.

### Charges are never retried automatically

Pey has no idempotency keys, so retrying a POST could create a second charge. The SDK **never retries POST requests**. GET requests are retried after network errors, 429 and 503, with exponential backoff and jitter, respecting `Retry-After` (see `Config::$retryPolicy`).

When a POST fails without a response, the charge may exist. An HTTP client cannot prove that a failed request never reached Pey, whatever exception it throws, so for a POST `NetworkException::requestMayHaveBeenProcessed()` is always true. Treat that case as "unknown", not as "failed".

### Money is exact

Amounts are integer centavos (`Amount`). They are never floats, round half-up like Pey, and travel to and from the API as exact JSON numbers.

### Input is validated before anything is sent

Request objects reject, all at once and before any network call:
- invalid CLABEs (check digit included), ids, `numRef`, phone numbers and expirations;
- metadata beyond Pey's limits;
- non-HTTPS redirect URLs;
- amounts outside each endpoint's range.

### Bank account registration is locked by default

Registering a CLABE redirects incoming CoDi payments to it. The SDK registers a CLABE **only if it is listed in `Config::$allowedClabes`**, and the list is empty by default. A blocked attempt throws `BankAccountNotAllowedException` before any request is built and logs a warning with the CLABE masked to its last four digits. There is no switch to turn this off: to register a CLABE, add it to the list after verifying the account is yours.

> Pey's own tutorial suggests registering a third-party test CLABE whose payments "are not returned". Do not do that with real money.

### Webhooks are verified before they are read

`WebhookVerifier` checks the Standard Webhooks signature with the official library, plus a timestamp tolerance against replays, before it parses anything. See [Webhooks](webhooks.md).

### TLS cannot be turned off

The base URL must be HTTPS, and the HTTP clients the SDK builds always verify certificates and never follow redirects.

## What remains your responsibility

- **Store the API key and webhook secret as secrets** (environment variables or a secret manager), and rotate them in the Pey portal if they are exposed.
- **Confirm payments through the API** (`transactions()->get()`) before fulfilling an order. Never rely on a webhook or a redirect alone.
- **Make charge creation idempotent** with [`IdempotentRequests`](idempotency.md) and a shared, durable store.
- **Deduplicate webhooks** by `VerifiedWebhook::$id`.
- **Keep the allowlist short.** Every CLABE on `allowedClabes` is an account your application can redirect money to.
- **If you pass your own PSR-18 client**, configure its timeouts and TLS verification, keep it from following redirects, and never configure the API key on the client itself.
- **Test with small amounts.** There is no sandbox.

## Reporting a vulnerability

Please do not open a public issue. Report it privately through GitHub's private vulnerability reporting on [DEV1-Softworks/pey-php-sdk](https://github.com/DEV1-Softworks/pey-php-sdk/security).
