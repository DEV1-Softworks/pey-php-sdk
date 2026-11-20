---
title: Known gaps
description: What Pey's documentation does not cover yet, and how the SDK handles each gap.
---

# Known gaps

This SDK follows Pey's published documentation ([docs.pey.mx](https://docs.pey.mx), OpenAPI version `2026-10-20`, checked on 2026-11-20). Where the documentation is silent, the SDK picks the safe interpretation and marks the code with `TODO(pey-docs)`. These are the open questions.

## TODO(pey-docs)

### Errors and limits

| Gap | How the SDK handles it | Where |
|---|---|---|
| No 401 response is documented. Authentication goes through an API gateway authorizer whose error body is unknown | `AuthenticationException` for any 401, with error type `UNKNOWN` when the body is not Pey's usual format | `Exception/AuthenticationException.php` |
| No 429 response, rate limit or `Retry-After` is documented, although free accounts have request limits | `RateLimitedException` for 429, `Retry-After` honoured for GET retries (RFC 9110) | `Exception/RateLimitedException.php` |
| Error bodies outside the documented `{"error": {…}}` shape (for example gateway 401/403 bodies) | Parsed defensively: a top-level `message` is kept, the type becomes `UNKNOWN` | `Http/ApiError.php` |
| 403 is documented only for payment links | Any 403 maps to `ForbiddenException` | `Http/Transport.php` |

### Resources

| Gap | How the SDK handles it | Where |
|---|---|---|
| `taxId` appears in bank account responses and webhooks, but registration has no field to provide it | Response-only, nullable | `Dto/Response/BankAccount.php` |
| Uniqueness of `clientReferenceId` and server-side deduplication are undocumented; there is no lookup by reference | Idempotency is enforced client-side only | `Idempotency/IdempotentRequests.php` |
| The meaning of payment-link status `partial` is undocumented, and the webhook page does not list it | Modelled as `PaymentLinkStatus::Partial`; unknown statuses become `Unknown` | `Enum/PaymentLinkStatus.php`, `Webhooks/Event/PaymentLinkStatusUpdated.php` |
| No maximum amount is documented for QR codes or payment links | Not limited locally (push notifications: at most 1,000,000.00, as documented) | `Validation/AmountRules.php` |
| No maximum expiration is documented | Only the documented minimums are enforced | `Validation/Expiration.php` |
| Rounding of request amounts is documented only for payment-link updates | Half-up everywhere | `Money/Amount.php` |
| The formats of CoDi QR ids (IDC) and of `nextToken` are undocumented | Checked only as non-empty printable ASCII without spaces | `Validation/OpaqueId.php` |
| The error returned when `bankAccountId` is not `ACTIVE` is undocumented | The fake client answers 400 `INVALID_STATE` | `Testing/Internal/FakeCodi.php` |
| Several rules appear only in field descriptions, not in the JSON schema: QR `expiration` 0 or ≥ 360, the CLABE check digit, HTTPS redirect URLs, ULID ids | Enforced anyway; the contract tests record each gap | `tests/Contract/RequestContractTest.php` |
| Several ids in the spec's examples are not valid ULIDs (22 or 24 characters) | Responses do not validate ids; requests do | `Validation/Ulid.php` |
| The sort key of transaction listings is not documented beyond "newest first" | The cursors in Pey's examples suggest creation order; the fake follows that | `Testing/Internal/FakeState.php` |

### Webhooks

| Gap | How the SDK handles it | Where |
|---|---|---|
| The OpenAPI document's `webhooks` object is empty | Events are modelled from the documentation pages and contract-tested against their examples | `tests/Contract/WebhookContractTest.php` |
| Retry schedule, delivery ordering and the overlap window when rotating the secret are undocumented | Deduplicate by `webhook-id`; accept both secrets during a rotation | `Webhooks/WebhookVerifier.php` |
| `ownerType` values other than `API_KEY` are undocumented | Kept as a string | `Webhooks/Event/BankAccountUpdated.php` |
| The push-notification webhook has no `codiId` | Correlate by the `transactionId` returned at creation | `Webhooks/Event/CodiPushNotificationPaymentReceived.php` |

## Environment

- **No sandbox.** Pey says a test environment is "coming soon". Its `llms.txt` index mentions `https://api.dev.pey.mx`, but the OpenAPI document does not, so the SDK defaults to production. `Config::$baseUrl` can point elsewhere if Pey releases one.
- Pey's API key tutorial suggests registering a third-party test CLABE whose payments "are not returned". The SDK's bank account allowlist blocks that unless you add it explicitly. Don't.

If Pey documents any of these, please open an issue or a pull request so the SDK can follow.
