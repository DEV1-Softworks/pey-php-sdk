# dev1/pey-php-sdk: unofficial PHP SDK for the Pey API

Framework-agnostic PHP 8.2+ SDK for the Pey API (CoDi payments, Mexico). This is the core package. The Laravel bridge (`dev1/pey-laravel`) and Symfony bundle only wire things together and never re-implement logic, so every behavior belongs here. Never present the SDK as an official Pey or Banco de México product.

User docs live in `docs/` (Markdown with front matter for labs.dev1.mx). `DocumentationTest` checks that their PHP examples parse and that their SDK imports exist. Update `docs/known-gaps.md` together with any `TODO(pey-docs)`.

Detailed rules live in `.claude/rules/`: `php-style.md`, `testing.md`, `security.md` (full security rules) and `pey-api.md` (verified API facts and doc gaps). Machine-specific notes go in `CLAUDE.local.md` (gitignored).

## Commands

- `composer test`: PHPUnit 11
- `composer test:coverage`: coverage plus thresholds (90% `src/`; 100% `Money/`, `Security/`, `Validation/`, `Webhooks/`)
- `composer analyse`: PHPStan level max with strict rules. Never add a baseline or `ignoreErrors`.
- `composer cs` checks PER-CS; `composer cs:fix` applies it

## Security rules (short form)

1. The API key and webhook secret never appear in exceptions, logs, `var_dump`/`print_r`/`var_export`, `__debugInfo` or serialized output. Put `#[\SensitiveParameter]` on every parameter that receives them, redact `Authorization` in logged requests, and prove all of it with tests.
2. Never retry a POST automatically (there is no idempotency, so a retry can duplicate a charge). Retry only GETs, on network errors, 429 and 503, with exponential backoff and jitter. Respect `Retry-After`. Default: 2 retries.
3. Money is never a `float`. `Amount` stores integer centavos, is built from string or int only, serializes with 2 decimals, and rounds half-up.
4. Validate locally before sending (CLABE check digit, amounts, ULIDs, `numRef`, metadata, HTTPS URLs, expirations). Failures throw `ValidationException` with field-level details.
5. `BankAccountGuard` is on by default: an empty CLABE allowlist blocks registration.
6. POST idempotency goes through `IdempotencyStore` (`acquire`/`release`/`remember`).
7. Webhooks use the official Standard Webhooks library. Tolerance comes from `psr/clock` (default 5 min). Expose `webhook-id`, never parse an unverified body, and tell consumers to confirm with `GET /transaction/{id}`.
8. Timeouts default to 5 s connect and 15 s total. TLS verification is always on and cannot be disabled.
9. No secrets in the repo: `.env*` is gitignored and gitleaks runs in CI.

## Pey API

Production only (`https://api.pey.mx`), with no sandbox: every request moves real money. When the brief and the spec disagree, the spec wins. Model undocumented behavior defensively, mark it with `// TODO(pey-docs):`, and never invent fields.

## Git workflow

- `master` is production; `develop` is the integration branch. **Never create or use a branch named `main`.**
- Branch `feature/<short-name>` from the latest `develop`. One PR per feature, targeting `develop`, never stacked. Start the next feature only after the previous one is merged.
- Use Conventional Commits: small, one logical change each, no WIP commits.
- The PR body lists what changed, tests added, `TODO(pey-docs)` items, and what was deliberately not done.
- Self-merge only when CI is green (tests, PHPStan, CS, gitleaks). Wait for human review on `transport-and-errors`, `retry-policy`, `idempotency`, `bank-account-guard` and `webhooks`.
- Release: cut `release/x.y.z` from `develop`, merge it into `master` and back into `develop`, then tag `vx.y.z` on `master`.
