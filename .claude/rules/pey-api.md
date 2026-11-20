# Pey API facts

Verified on 2026-11-20 against `openapi.json` with `info.version` `2026-10-20` (OpenAPI 3.1.1) and the docs pages linked below. **When this file and the spec disagree, the spec wins.** Update this file in the same PR as the code.

## Sources

- Spec: https://docs.pey.mx/openapi.json. A verbatim snapshot lives in `tests/Fixtures/openapi.json`; see `tests/Fixtures/README.md` for its version, SHA-256 and how to refresh it.
- API intro: https://docs.pey.mx/en/docs/api/codi-api
- Webhooks:
  - intro (Standard Webhooks, `webhook-id`): https://docs.pey.mx/en/docs/webhooks/introduction
  - CoDi QR payment received: https://docs.pey.mx/en/docs/webhooks/codi/qr
  - CoDi push payment received: https://docs.pey.mx/en/docs/webhooks/codi/pn
  - Payment link status update: https://docs.pey.mx/en/docs/webhooks/payment-link/status-update
  - Bank account updated: https://docs.pey.mx/en/docs/webhooks/bank-account/updated
- Types: https://docs.pey.mx/en/docs/types/transaction-object, `/amount-breakdown`, `/metadata-response`, `/bank-account`
- Index: https://docs.pey.mx/llms.txt. It is partly stale: it claims a test environment that the spec does not have.

## Basics

- Base URL `https://api.pey.mx`. **Production only.** The test environment is "coming soon"; `llms.txt` mentions `https://api.dev.pey.mx`, but the spec does not, so we do not use it.
- Auth: `Authorization: Bearer <api key>`. There are no scopes and a single key does everything. The security scheme is an AWS Lambda authorizer.
- No idempotency keys and no documented rate limits.
- All bodies are JSON. Amounts are JSON numbers in MXN. Timestamps are Unix seconds (integers).
- **No 2xx response schema marks any field as required**, so response DTOs must tolerate missing fields.

## Errors

All documented errors share this shape. `requestId` may be `null`.

```json
{ "error": { "message": "…", "type": "INVALID_AMOUNT", "requestId": "abc123-…" } }
```

| Status | Where documented | `error.type` values |
|---|---|---|
| 400 | all endpoints | varies per endpoint (see below) |
| 403 | payment-link get and update | `FORBIDDEN` |
| 404 | GET by id | `NOT_FOUND`; `BANK_ACCOUNT_NOT_FOUND` for bank-account endpoints |
| 409 | payment-link update | `PAYMENT_LINK_NOT_PENDING`, `CONCURRENT_UPDATE` |
| 500 | all endpoints | `INTERNAL_SERVER_ERROR` |
| 503 | `/codi/qr`, `/codi/pn`, both `/bank-account` endpoints, `/transaction/bank-account/{id}` | `SERVICE_UNAVAILABLE`, `FLAGS_UNAVAILABLE` (maintenance) |
| 401, 429 | **nowhere** | unknown. Model defensively (TODO(pey-docs)) |

## Endpoints

`*` marks a required request field.

### `POST /codi/qr` → **201**

- Request:
  - `amount*`: number ≥ 0 (0 lets the payer enter the amount)
  - `concept`: string
  - `expiration`: int, default 0 (0 = none, otherwise ≥ 360)
  - `numRef`: string `^[0-9]{1,7}$`, default `"0"`
  - `generateImage`: bool, default false
  - `bankAccountId`: ULID of an `ACTIVE` account; when omitted, the API key's own CoDi account receives the money
  - `metadata`
- Response:
  - `id`: Banxico **IDC**, not a ULID (e.g. `AB12CD34EF`)
  - `qrString`
  - `qrImage`: base64 PNG; only when `generateImage` is true; `null` if rendering failed (the QR still exists)
- 400 types: `INVALID_REQUEST`, `PEY_CONSISTENCY_ERROR`, `INVALID_STATE`, `INVALID_AMOUNT`, `INVALID_PARAMETER`

### `POST /codi/pn` → 200

- Request:
  - `amount*`: number > 0 and ≤ 1,000,000
  - `phoneNumber*`: `^[0-9]{10}$`
  - `concept`
  - `expiration`: int ≥ 360, default 360
  - `numRef`, `bankAccountId`, `metadata`: same rules as QR
- Response:
  - `transactionId`: ULID
  - `codiId`: 20-digit Banxico folio
- 400 types: `INVALID_REQUEST`, `INCOMPLETE_PARAMETERS`, `INVALID_PARAMETER`, `INVALID_AMOUNT`, `INVALID_STATE`, `PEY_CONSISTENCY_ERROR`, `BAD_REQUEST`

### `POST /bank-account` → 200

- Request:
  - `alias*`: 1–255 characters
  - `name*`: legal name, 1–255 characters
  - `clabe*`: `^[0-9]{18}$`; the check digit must be valid and the bank must have CoDi enabled
  - `entity*`: `BUSINESS` | `PERSON`
- Response: `id` (ULID) and `codiStatus` (starts as `PENDING`). Activation with Banxico takes up to 5 business days.
- 400 types: `INVALID_REQUEST`, `INCOMPLETE_PARAMETERS`, `INVALID_PARAMETER`, `INVALID_CLABE`

### `GET /bank-account/{id}` → 200

- Response fields:
  - `id`, `alias`, `name`, `clabe`, `entity`
  - `taxId?`: "only present when provided on registration", but registration has no `taxId` field
  - `codiStatus`
  - `errorMessage?`: only when `codiStatus` is `ERROR`
  - `dateCreated`
- Unknown accounts and other keys' accounts both return 404 `BANK_ACCOUNT_NOT_FOUND`.

### `POST /payment-link/create` → 200

- Request:
  - `amount*`: number > 0
  - `successUrl*`, `backUrl*`: HTTPS only
  - `hasTip`: bool, default false
  - `clientReferenceId`: at most 255 characters
  - `metadata`
- Response: `id` (ULID) and `url`
- 400 types: `AUTH_ERROR`, `PEY_CONSISTENCY_ERROR`, `REQUEST_PARSING_ERROR`, `INCOMPLETE_PARAMETERS`, `INVALID_URL`, `INVALID_AMOUNT`, `INVALID_HAS_TIP`, `INVALID_CLIENT_REFERENCE_ID`

### `POST /payment-link/update` → 200

- Request: only `paymentLinkId*` and `amount*` (> 0). `additionalProperties` is false: any other field is a 400 `INVALID_PARAMETER`. Pey rounds amounts with more than 2 decimals half-up.
- Response: `id` and `amount`
- Allowed only while the link is `pending`. Otherwise:
  - 409 `PAYMENT_LINK_NOT_PENDING` or `CONCURRENT_UPDATE`
  - 403 `FORBIDDEN` for another key's link
  - 404 `NOT_FOUND`

### `GET /payment-link/{id}` → 200

- Response fields:
  - `id`, `amount`
  - `status`: `pending` | `processing` | `paid` | `partial`
  - `successUrl`, `backUrl`, `hasTip`
  - `paymentMethods[]`: strings, e.g. `CODI`, `CARD`
  - `dateCreated`
  - `clientReferenceId?`
  - `metadata?`: string values
  - `transactions[]`: Transaction Object (summary)

### `GET /transaction/{id}` → Transaction

Unknown transactions and other keys' transactions both return 404 `NOT_FOUND`. A CoDi QR is not a transaction.

### Paginated listings

All three return `{ "items": Transaction[], "nextToken": string|null }`, newest first. Pass `nextToken` back as a query parameter. `nextToken` is `null` on the last page, and an invalid token returns 400 `INVALID_PARAMETERS`.

| Endpoint | Page size | Notes |
|---|---|---|
| `GET /transaction/api-key` | 80 | all transactions of the key |
| `GET /transaction/bank-account/{id}` | 80 | only `PAID` CoDi transactions (`API_CODI_QR` / `API_CODI_PN`) |
| `GET /transaction/codi/qr/{id}` | 20 | `{id}` is the **IDC**. Items have no `payableType` or `payableId` |

## Shared types

- **Transaction** (full):
  - `id`: ULID
  - `status`: `PENDING` | `PAID`
  - `amount`: total = base + tip + fee
  - `amountBreakdown`: `{base, tip, fee}`; components may be omitted
  - `currency`: ISO 4217
  - `paymentMethod`: `CARD` | `CODI`
  - `payableType`: `PAYMENT_LINK` | `API_CODI_QR` | `API_CODI_PN`
  - `payableId`: ULID, or the IDC for QRs
  - `description`, `applicationFee`, `dateCreated`
  - `datePaid?`: once paid
  - `metadata`: Metadata Response
- **Transaction Object** (summary, used in payment links and webhooks): `transactionId`, `paymentMethod`, `datePaid`, `amount`, `amountBreakdown`, `applicationFee` (number or null), `metadata`
- **Metadata Response**:
  - CoDi only: `clabe` (the payer's CLABE), `bankCode` (int), `accountType` (int), `description`, `trackingKey`
  - `externalMetadata`: the caller's metadata, with string values
- **Bank Account** `codiStatus`:
  - `PENDING`: received, not yet sent to Banxico
  - `PROCESSING`: registration in progress
  - `ACTIVE`: can receive payments
  - `ERROR`: see `errorMessage`
- **Fees**: `fee` is charged to the payer. `applicationFee` is retained by Pey.

## Webhooks

Pey uses Standard Webhooks headers (`webhook-id`, `webhook-timestamp`, `webhook-signature`). `webhook-id` is the dedup key. Each API key has a single webhook URL, and its secret (`whsec_…`) can be rotated in the portal. Payloads have no envelope: the event is identified by the body's `type`. The spec's `webhooks` object is **empty**.

| `type` | Fields |
|---|---|
| `codi.qr.payment_received` | `amount`, `apiKeyId`, `bankAccountId?`, `codiQrId` (IDC), `concept`, `datePaid`, `metadata` (Metadata Response), `numRef`, `transactionId` |
| `codi.pn.payment_received` | same as QR, without `codiQrId` |
| `payment_link.status_updated` | `apiKeyId`, `paymentLinkId`, `status` (`paid`/`pending`/`processing` documented), `clientReferenceId`, `transactions[]` (Transaction Object) |
| `bank.account.updated` | `bankAccountId`, `name`, `ownerType` (`API_KEY`), `ownerId`, `taxId?`, `codiStatus` (always `ACTIVE`), `clabe`, `entity`. Sent only on the transition to `ACTIVE` |

## Known gaps (`TODO(pey-docs)`)

Collect these in `docs/known-gaps.md` for v0.1.0.

1. No 401 documented. The authorizer's failure status and body are unknown.
2. No 429, rate limit or `Retry-After` documented. Free-account limits are not documented either.
3. 403 is documented only for payment links. Other endpoints may return authorizer 403s with a non-standard body.
4. `taxId` appears in bank-account responses and the webhook, but registration has no field for it.
5. Uniqueness of `clientReferenceId` and server-side deduplication are undocumented, and there is no lookup by reference.
6. The meaning of payment-link status `partial` is undocumented, and the webhook page does not list it.
7. No maximum is documented for QR or payment-link amounts, or for expirations.
8. Rounding of request amounts is documented only for payment-link update.
9. Webhook retry and delivery semantics, ordering, and the overlap window during secret rotation are undocumented.
10. `ownerType` values other than `API_KEY` are undocumented.
11. Several ids in the spec's examples are not valid ULIDs (22 and 24 characters).
12. The push webhook has no `codiId`. Correlate pushes by the `transactionId` returned at creation.
13. Several rules exist only in field descriptions, not in the JSON schema: the QR `expiration` "0 or ≥ 360" (the schema says `minimum: 0`), the CLABE check digit, HTTPS-only redirect URLs, and ULID ids. The SDK enforces them anyway; `RequestContractTest` records each gap.
