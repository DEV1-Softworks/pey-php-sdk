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

[Unreleased]: https://github.com/DEV1-Softworks/pey-php-sdk/commits/develop
