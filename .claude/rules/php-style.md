# PHP style

## Language level

- The floor is PHP 8.2 and CI runs 8.2, 8.3, 8.4 and 8.5. Do not use features newer than 8.2:
  - 8.3: typed class constants, `#[\Override]`, `json_validate()`, `mb_str_pad()`, dynamic class constant fetch
  - 8.4: property hooks, asymmetric visibility, `new Foo()->bar()` without parentheses, `array_find()`/`array_any()`/`array_all()`, `#[\Deprecated]`
  - 8.5: the pipe operator `|>`, and any other 8.5-only syntax or function
- Start every PHP file with `declare(strict_types=1);`.

## Design

- Classes are `final` by default. Make one extensible only with a concrete reason.
- DTOs and value objects are `final readonly class` with promoted constructor properties. Use named constructors (`fromString()`, `fromCentavos()`, `fromArray()`) when construction needs validation or parsing.
- Closed sets of API values are string-backed `enum`s whose values match the API exactly (for example `PaymentLinkStatus::Pending = 'pending'`, `TransactionStatus::Paid = 'PAID'`).
- Money is never a `float`. Only `Money\Amount` represents money (see `security.md`).
- Depend on PSR interfaces only: PSR-18 client, PSR-17 factories, PSR-7 messages, PSR-3 logger, PSR-20 clock. Never import a concrete HTTP client (Guzzle, Symfony) at runtime.
- No global state, singletons or static mutable properties. Inject dependencies through constructors.
- No magic: no `__get`/`__set`/`__call`, no dynamic properties, no `extract()`. A `__debugInfo` that redacts secrets is the exception.
- Every thrown exception extends `Exception\PeyException`. Messages must not contain secrets, request headers or raw request bodies.
- Mark anything that is not public API `@internal`. The Laravel and Symfony bridges must only need public API.
- Runtime dependencies are limited to `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/log`, `psr/clock`, `php-http/discovery` and `standard-webhooks/standard-webhooks`. Ask the maintainer before adding anything else. `tests/PackageMetadataTest.php` enforces this list.

## Types and static analysis

- Use native types everywhere. Add PHPDoc only for what native types cannot say: array shapes, `list<T>`, generics, `@throws`.
- Decode API payloads into `array<string, mixed>` and narrow them explicitly (`is_string`, `is_int`, …) before building DTOs. Do not cast blindly.
- PHPStan runs at level max with strict rules and **no baseline**. Do not add `@phpstan-ignore` or inline `@var` overrides to silence an error; fix the types. If a suppression is truly unavoidable (for example a bug in a third-party stub), add an inline `@phpstan-ignore <identifier>` with a comment explaining why, and point it out in the PR.

## Formatting

- PER-CS 3.0 through PHP-CS-Fixer (`composer cs:fix`), plus strict comparisons, strict params, single quotes and ordered imports.
- Use fully qualified global functions only where PHP-CS-Fixer or PHPStan asks for them. Do not mass-convert.

## Naming

- Namespace root `Dev1\Pey\`, PSR-4 from `src/`. Tests live under `Dev1\Pey\Tests\` and mirror `src/`.
- Resources use plural nouns (`BankAccounts`, `PaymentLinks`, `Transactions`), except `Codi`.
- Use the API's field names in DTO properties (`clientReferenceId`, `numRef`, `datePaid`) so DTOs map 1:1 to the spec.
