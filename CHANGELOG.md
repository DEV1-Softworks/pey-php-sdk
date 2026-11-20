# Changelog

All notable changes to `dev1/pey-php-sdk` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Project scaffold: Composer package `dev1/pey-php-sdk`, PHPUnit 11, PHPStan at level max with strict rules (no baseline), PHP-CS-Fixer with PER-CS 3.0, and a coverage gate (90% on `src/`, 100% on `Money/`, `Security/`, `Validation/` and `Webhooks/`).
- GitHub Actions CI on PHP 8.2–8.5, with a gitleaks secret scan.
- `Version` class with the SDK user agent.
- `Config`: the API key (wrapped in a `Secret`), an HTTPS-only base URL (default `https://api.pey.mx`), and timeouts of 5 s to connect and 15 s in total, all validated.
- `Security\Secret`: holds the API key or a webhook secret so it never appears in `var_dump`, `print_r`, `var_export`, `debug_zval_dump`, `json_encode` or array casts. Serializing and cloning it are refused.
- HTTP transport: Bearer auth, a JSON codec that never uses floats (amounts are sent and received as exact decimals), logging with the `Authorization` header redacted and no bodies, and requests that are sent exactly once (never retried).
- Default HTTP client with timeouts, redirects disabled and TLS verification on. Guzzle is preferred, then Symfony HttpClient, then php-http/discovery with a warning.
- Exception hierarchy under `PeyException`:
  - `ValidationException` (local field errors, or HTTP 400)
  - `AuthenticationException` (401)
  - `ForbiddenException` (403)
  - `NotFoundException` (404)
  - `ConflictException` (409, with `PAYMENT_LINK_NOT_PENDING` / `CONCURRENT_UPDATE` helpers)
  - `RateLimitedException` (429)
  - `ServerException` (5xx) and `ServiceUnavailableException` (503)
  - `NetworkException`, which says whether a failed POST may have been processed
  - `UnexpectedResponseException`, `ConfigurationException` and `SecretExposureException`
- `Money\Amount`: MXN amounts as integer centavos, built only from decimal strings or integer centavos, never negative. Extra decimals round half-up like Pey, and amounts serialize with two decimals.
- Local validators in `Validation\`, collecting field-level errors in `Violations`:
  - `Clabe`: 18 digits and Banco de México check digit
  - `Ulid`
  - `NumRef`: 1–7 digits
  - `PhoneNumber`: 10 digits
  - `Url`: HTTPS redirect URLs
  - `Expiration`: QR 0 or ≥ 360 s; push notification ≥ 360 s
  - `AmountRules`: push notification > 0 and ≤ 1,000,000; payment link > 0
  - `Metadata`: ≤ 50 pairs, keys ≤ 40 characters, scalar values ≤ 500 characters
  - `Text`: Unicode-aware length checks
- Enums `CodiStatus`, `PaymentLinkStatus`, `TransactionStatus`, `PaymentMethod`, `PayableType` and `Entity`. Each has an `Unknown` case so values Pey adds later never break parsing.
- Request DTOs that validate every field and report all errors at once: `CreateQrRequest`, `CreatePushNotificationRequest`, `RegisterBankAccountRequest`, `CreatePaymentLinkRequest` and `UpdatePaymentLinkRequest`. Amounts are sent as exact JSON numbers, and metadata always as a JSON object.
- Response DTOs: `QrCode`, `PushNotification`, `BankAccountRegistration`, `BankAccount`, `CreatedPaymentLink`, `UpdatedPaymentLink`, `PaymentLink`, `Transaction`, `TransactionSummary`, `TransactionPage`, `AmountBreakdown` and `TransactionMetadata`. Amounts are `Amount`, timestamps are UTC `DateTimeImmutable`, and malformed responses raise `UnexpectedResponseException` naming the field.
- Contract tests against a verbatim snapshot of Pey's `openapi.json` (version `2026-10-20`), covering request schemas, response examples, DTO field names and enum values.
- `PeyClient::create()` and `PeyClientInterface`: the entry point, with HTTP components discovered when not given.
- `$pey->codi()->createQr()` and `->createPushNotification()`: CoDi QR codes and push notifications. They are never retried automatically.
- `$pey->bankAccounts()->register()` and `->get()`. Registration goes through `Security\BankAccountGuard`: only CLABEs listed in the new `Config::$allowedClabes` can be registered, and an empty list (the default) blocks every registration before anything is sent (`BankAccountNotAllowedException`, which shows a masked CLABE).
- `Validation\Clabe::mask()`, which shows only the last 4 digits.
- `$pey->paymentLinks()->create()`, `->update()` and `->get()`. Updates change only the amount and raise `ConflictException` (`isPaymentLinkNotPending()` / `isConcurrentUpdate()`) once a link is no longer pending.
- `$pey->transactions()`:
  - `->get()` confirms a payment before you fulfil an order;
  - `->listForApiKey()`, `->listForBankAccount()` and `->listForCodiQr()` return one page each;
  - the matching `all*()` generators walk every page lazily.
- `Pagination\Paginator`: lazy `nextToken` pagination that stops with `UnexpectedResponseException` if Pey repeats a cursor.
- `Validation\OpaqueId` for identifiers with undocumented formats (CoDi QR ids, cursors).
- Automatic retries for **GET requests only**, on transient network errors, 429 and 503:
  - configured with `Config::$retryPolicy` (`RetryPolicy`: 2 retries by default, capped at 10);
  - exponential backoff with equal jitter, and `Retry-After` respected up to `maxDelayMs`;
  - POST requests are never retried.
- `Clock\SystemClock` (PSR-20, UTC) and the `psr/clock` dependency.
- `NetworkException::isTransient()`.
- Idempotency for charge-creating requests (`Idempotency\IdempotentRequests`): each key reaches Pey at most once.
  - Applies to `createQr`, `createPushNotification` and `createPaymentLink` (whose key is its `clientReferenceId`).
  - A repeat with the same parameters returns the stored result without calling Pey. Reusing a key for different parameters is refused.
  - Keys are released only when Pey certainly did not act; any doubtful outcome stays blocked until it is reconciled and `release()` is called.
  - Includes the `IdempotencyStore` contract and an `InMemoryIdempotencyStore` for tests.
- `IdempotencyInProgressException`, `IdempotencyOutcomeUnknownException` and `IdempotencyKeyReusedException`.
- `toArray()` on `QrCode`, `PushNotification` and `CreatedPaymentLink`.
- Webhook verification (`Webhooks\WebhookVerifier`):
  - checks Standard Webhooks signatures with the official `standard-webhooks/standard-webhooks` library, against a configurable timestamp tolerance (PSR-20 clock, default 5 minutes);
  - accepts several secrets during a rotation;
  - verifies before parsing, and returns a `VerifiedWebhook` with the `webhook-id` for deduplication and a typed event.
- Webhook events: `CodiQrPaymentReceived`, `CodiPushNotificationPaymentReceived`, `PaymentLinkStatusUpdated`, `BankAccountUpdated`, and `UnknownEvent` for types this version does not model.
- `WebhookVerificationException`, which exposes the failure reason (`VerificationFailure`).

### Fixed

- API keys and JSON number literals with a trailing line break were accepted, because PCRE's `$` matches before a final newline.

[Unreleased]: https://github.com/DEV1-Softworks/pey-php-sdk/commits/develop
