# Testing

## Basics

- PHPUnit 11 with attributes only (no annotations). Every test class declares `#[CoversClass(...)]`, or `#[CoversNothing]` for project-level guards; `requireCoverageMetadata` enforces this.
- Tests run in random order and fail on warnings, notices, deprecations and risky tests. Every test must be independent.
- The `tests/` tree mirrors `src/` (`src/Money/Amount.php` → `tests/Money/AmountTest.php`). Helpers live in `tests/Support/`; fixtures live in `tests/Fixtures/`.
- Method names are `testItDoesSomethingWhenSomething()`. Data providers are `public static` methods named `provide…()`.

## No network, no real time

- **No real HTTP calls, ever.** Use a mock PSR-18 client that records requests and returns canned responses. A test that opens a socket is a bug.
- Never call `sleep()` or read the system clock in tests. Inject `Psr\Clock\ClockInterface` with a frozen clock, and inject the retry sleeper and jitter source so backoff is deterministic.
- Never use a real API key or webhook secret. Use obviously fake values, for example `pey_test_fake_key` and the published Standard Webhooks test vectors. If gitleaks flags a fake fixture, allowlist that exact fixture path in `.gitleaks.toml`, never a whole directory of source code.

## Contract tests

- `tests/Fixtures/openapi.json` is a snapshot of https://docs.pey.mx/openapi.json. Record its `info.version` and download date in `tests/Fixtures/README.md`.
- Contract tests check that each request DTO serializes only fields the spec defines, with the right names and types and with required fields present, and that each response DTO hydrates from the spec's examples.
- The spec's `webhooks` object is empty, so webhook fixtures are the documented examples, copied verbatim into `tests/Fixtures/webhooks/` with their source URL.
- Do not use ids from the spec's examples where a valid ULID is required: several are 22 or 24 characters long. Use fixed, valid ULIDs instead.

## Coverage

- At least 90% line coverage on `src/` and 100% on `src/Money`, `src/Security`, `src/Validation` and `src/Webhooks`. `tools/coverage-check.php` enforces this in CI. Run it locally with `composer test:coverage`.
- Prefer exhaustive data providers for validators, for example CLABE check-digit vectors (valid ones, every invalid check digit, wrong lengths, non-digits) and amount boundaries (0, 0.01, 1,000,000, 1,000,000.01).

## Mandatory security tests

- Every class that receives or holds the API key or webhook secret needs tests proving the secret is absent from `var_dump`, `print_r`, `var_export`, `serialize`/`json_encode` (or that these throw), `__debugInfo`, exception messages, exception traces (via `#[\SensitiveParameter]`), and logged request context (`Authorization: Bearer [REDACTED]`).
- Retry tests prove that a POST is never retried, whatever the failure (network error, 429, 503, 5xx).
- `BankAccountGuard` tests prove that an empty allowlist blocks every CLABE and that a blocked request never reaches the HTTP client.
- Webhook tests prove that a tampered body, a wrong secret, a stale or future timestamp, and missing headers are all rejected before any JSON is parsed.

## Consumers' tests

- `Testing\FakePeyClient` is the in-memory fake for applications that use this SDK. It must enforce the same validation as the real client, so a test that passes against the fake does not fail in production on input validation.
