# Security rules (non-negotiable)

The Pey API is production only. A single API key can create charges and register the bank accounts that receive money. Every rule below exists because breaking it costs real money or leaks a credential. None of them may be relaxed without explicit maintainer approval.

## 1. Secrets never leak

Applies to the Pey API key and the webhook secret.

- Mark every parameter that receives a secret (constructors, factories, setters, closures) with `#[\SensitiveParameter]`, so stack traces show `SensitiveParameterValue` instead of the value.
- Always hold a secret in `Security\Secret`, never in a plain `string` property. `var_export()` **ignores `__debugInfo()`** and prints private properties, and an `(array)` cast exposes even a closure's captured variables. `Secret` therefore keeps the value outside the object, in a private static `WeakMap` keyed by the instance. The object has no properties to print, and the entry is released when the instance is garbage-collected. `Secret`:
  - returns `[REDACTED]` from `__debugInfo()`, `__toString()` and `jsonSerialize()`;
  - refuses `serialize()`, `unserialize()` and `clone` by throwing `SecretExposureException`;
  - is read only through `reveal()`, at the exact point where the raw value must be sent.
- Never pass a PSR-18 exception or a PSR-7 request as an ordinary argument to a function that may throw: both carry the `Authorization` header, which would land in the new exception's trace arguments. Mark such parameters `#[\SensitiveParameter]`, and never chain PSR-18 exceptions as `previous`.
- Use `get_debug_type()`, never `::class`, for class names that end up in messages or logs. An anonymous class name contains a NUL byte and an absolute file path.
- Never store a `StandardWebhooks\Webhook` instance as a property. It keeps the decoded secret in a plain private property, which `var_export` and `serialize` expose. Create it inside the method that verifies and let it go out of scope.
- Exceptions never include the secret, request headers, or raw request bodies in their message or context. `PeyException` subclasses carry the method, a path template, the status code, Pey's `error.type` and `requestId`, and nothing more.
- Logging uses PSR-3. A logged request shows `Authorization: Bearer [REDACTED]`. Request and response bodies are not logged by default, because they contain CLABEs, names, phone numbers and metadata.
- Tests prove all of the above (see `testing.md`, "Mandatory security tests").

## 2. Never automatically retry a POST

- Pey has no idempotency keys, so a retried POST can create a duplicate charge, payment link or bank account.
- Retry **only GET** requests (`RetryPolicy::allowsMethod()`, also enforced in `Transport::retryDelay()`), and only on:
  - a transient network failure (`NetworkException::isTransient()`: connection, timeout or stream errors; not a request the client rejected as invalid),
  - HTTP 429,
  - HTTP 503.
- 500, other 5xx, other 4xx and malformed successes are never retried.
- The policy is `Config::$retryPolicy` (`RetryPolicy`):
  - `maxRetries` defaults to 2 and is capped at 10;
  - waits use exponential backoff with *equal* jitter: half of min(maxDelayMs, baseDelayMs × 2^(n−1)), plus a random amount up to the other half;
  - `Retry-After` (delta-seconds or IMF-fixdate) replaces the backoff. When it asks for more than `maxDelayMs` (default 10 s), the SDK does not wait: the exception is thrown with `retryAfter()` set.
- The sleeper, random source and clock (`psr/clock`) are injected into `Transport`. Tests never really sleep (`RecordingSleeper`, seeded `Randomizer`, `FrozenClock`).
- Each retry is logged as a warning with method, path template, reason, delay and attempt number, but never secrets or bodies.
- Mutating the retry rules (allowing POST in the policy, dropping the method check in `Transport`, retrying 500, ignoring `Retry-After`) fails the suite.
- A POST that fails with a network error or timeout **after it may have been sent** has an unknown outcome. Surface it as `NetworkException` stating that the request may have been processed. Never retry it or release its idempotency lock automatically.

## 3. Money is never a float

- `Money\Amount` stores integer centavos. It is built from `string` (`'150.00'`, `'150'`, `'0.5'`) or `int` centavos only. No public method accepts or returns `float`.
- Decimal strings are parsed strictly: no exponent notation, no thousands separators, no signs other than an optional leading `-` (which is then rejected). Extra decimals round **half-up** to centavos, matching Pey.
- `Amount` serializes as a decimal with exactly 2 places (`150.00`).
- The API types amounts as JSON **numbers**, in both directions. The JSON layer must write the number from the decimal string without going through `float`, and must read numbers from responses without float arithmetic.
- Limits depend on the endpoint and are validated by the request DTOs:
  - CoDi QR: amount ≥ 0 (0 lets the payer enter the amount)
  - Push notification: amount > 0 and ≤ 1,000,000
  - Payment link create and update: amount > 0

## 4. Validate locally before sending

Throw `Exception\ValidationException` with field-level details (`field => list of messages`) before any HTTP call:

- `clabe`: exactly 18 digits with a valid check digit (weights 3, 7, 1 over the first 17 digits; check = (10 − Σ(dᵢ·wᵢ mod 10) mod 10) mod 10).
- ULIDs (`bankAccountId`, `paymentLinkId`, transaction ids): 26 characters of Crockford base32. CoDi QR ids are Banxico IDCs, **not** ULIDs; validate them only as non-empty.
- `numRef`: 1–7 digits, sent as a string.
- `phoneNumber` (push notifications): exactly 10 digits.
- `metadata`: at most 50 pairs; keys at most 40 characters; values must be scalar (string, int, float, bool) or null, and at most 500 characters once stringified; no nested arrays or objects. Pey returns every value as a string.
- `successUrl` and `backUrl`: absolute `https://` URLs.
- Expiration:
  - QR: `0` (no expiration) or ≥ 360 seconds.
  - Push notification: ≥ 360 seconds (default 360).
- Bank account `alias` and `name`: 1–255 characters. `entity` must be one of the `Entity` enum cases.
- Payment link `clientReferenceId`: at most 255 characters.

## 5. BankAccountGuard is the default

- Registering a CLABE redirects incoming money, so registration requires an explicit allowlist: `Config::$allowedClabes`.
- An **empty allowlist (the default) blocks all registrations**. Allowlist entries must be valid 18-digit CLABEs with correct check digits; invalid entries are a `ConfigurationException`. CLABEs are compared exactly, with no trimming.
- `BankAccounts::register()` calls `BankAccountGuard::assertAllowed()` **before any HTTP request is built**. A blocked attempt:
  - throws `BankAccountNotAllowedException`, which carries the masked CLABE only (last 4 digits);
  - is logged as a PSR-3 warning with the masked CLABE.
- CLABE parameters of the guard and of `register()` are `#[\SensitiveParameter]`, so full account numbers (personal data) stay out of traces.
- There is **no** switch to disable the guard: to register a CLABE, allowlist it. Pey's own tutorial suggests registering a third-party CLABE for testing; our docs must never repeat that advice.
- Tests prove that a blocked registration sends zero requests. Mutating the guard (allow-on-empty, skipping the check, dropping `#[\SensitiveParameter]`) fails the suite.

## 6. Idempotency for charge-creating POSTs

`Idempotency\IdempotentRequests` guarantees that, per key, Pey is called **at most once** for `createQr`, `createPushNotification` and `createPaymentLink`. A repeat with the same parameters replays the stored result without calling Pey.

- **Keys**:
  - QR and push notifications take a caller-supplied key, 1–255 characters;
  - payment links use `clientReferenceId`, which is therefore required;
  - each `Operation` has its own namespace, and keys are stored and logged only as SHA-256 hashes.
- **Store contract** (`IdempotencyStore`: `acquire`, `remember`, `release`, `recall`):
  - `acquire` MUST be atomic across processes (Redis `SET NX EX`, Laravel `Cache::add`), and records must not be evicted early;
  - `InMemoryIdempotencyStore` is for tests and single processes only. Bridges provide real stores.
- **Records** are versioned JSON holding a state (`started`, `completed` or `unknown`), the start time and a **fingerprint**: SHA-256 of the operation plus the canonical body, with object keys sorted.
- **When the key is already taken:**
  - **different fingerprint** → `IdempotencyKeyReusedException`: never replay another charge's result;
  - **completed** → replay;
  - **started**, younger than `staleAfterSeconds` (default 300) → `IdempotencyInProgressException`;
  - **started but stale, or unknown, or unreadable** → `IdempotencyOutcomeUnknownException`.
- **After a failure, release only when Pey certainly did not act**:
  - local `ValidationException`, or a 400 from Pey;
  - `BankAccountNotAllowedException` or `ConfigurationException`;
  - a 4xx from Pey;
  - a `NetworkException` whose `requestMayHaveBeenProcessed()` is false.

  **Everything else** keeps the key blocked as `unknown`: timeouts, 5xx (503 included), unreadable 2xx bodies, 3xx, and any non-Pey `Throwable`.
- **Fail closed**:
  - if `acquire` throws, nothing is sent;
  - if storing the result fails after success, the result is still returned and the key stays `started`, which goes stale and then `unknown`. It never reopens;
  - if releasing fails, the key stays blocked.
- **The only way to resend a blocked key** is an explicit `release()`, after the application has confirmed with Pey that nothing was created. Releases are logged at `notice`.
- **Retention** defaults to 7 days. It must outlast every retry horizon (queues, users). `staleAfterSeconds` must be at least 60 and must exceed the HTTP timeout.
- Mutating any decision (release on timeout, release unexpected errors, treat 5xx or unreadable responses as client errors, skip the fingerprint, ignore `acquire`, skip the unknown or stale checks, accept a corrupt record) fails the suite.

## 7. Webhooks

- Verify with the official Standard Webhooks PHP library (`standard-webhooks/standard-webhooks`). **Never hand-roll HMAC.**
- Verify the raw body exactly as received (no re-encoding) and match header names case-insensitively.
- Timestamp tolerance defaults to 5 minutes, is configurable, and is measured with `psr/clock`. The library hard-codes ±5 minutes against `time()`, so the timestamp check is ours and the signature computation is the library's. The design is reviewed in the `webhooks` PR.
- Expose `webhook-id` so consumers can deduplicate. It is Pey's documented idempotency key for deliveries.
- Build typed events **only** from the verified raw body. Never parse an unverified body.
- Document for consumers:
  - Confirm a payment with `GET /transaction/{id}` before fulfilling: `status` must be `PAID`, and `amountBreakdown.base`, `payableType` and `payableId` must match the order. `amount` includes tip and fee.
  - A QR without expiration can be paid many times, so fulfil per `transactionId`.

## 8. Transport defaults

- Timeouts default to 5 s connect and 15 s total, and both are configurable. PSR-18 has no timeout API, so `Http\HttpClientFactory` builds known clients itself, with redirects off and TLS verification on:
  1. Guzzle: exact `connect_timeout` and `timeout`.
  2. Symfony HttpClient: it has no connect timeout, so both `timeout` (idle) and `max_duration` are set to the total. A short idle timeout would abort slow responses and leave a POST's outcome unknown.
  3. Any other client from php-http/discovery, with a logged warning that timeouts could not be set.

  A client the user passes in is used as-is: its timeouts and TLS settings are the user's responsibility, and the docs say so.
- Never configure the API key on the HTTP client (for example as Guzzle default headers). It is added per request, in `Transport::send()`.
- TLS verification is always on. There is **no** option to disable it. The base URL must be `https://`; it is configurable only so a future Pey environment can be used.

## 9. No secrets in the repository

- `.env*` is gitignored, and so is `CLAUDE.local.md`.
- gitleaks scans the full git history in CI.
- Test fixtures use obviously fake credentials only.
