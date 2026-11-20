# Test fixtures

## `openapi.json`

A verbatim, byte-for-byte snapshot of Pey's published OpenAPI document. Contract tests in `tests/Contract/` check the SDK's DTOs and enums against it.

| | |
|---|---|
| Source | https://docs.pey.mx/openapi.json |
| `info.version` | `2026-10-20` |
| Retrieved | 2026-11-20 |
| SHA-256 | `5b92417faa1898e94561a723aae40ce4931b73a0dd3c5b5352f414b0b9c739cb` |

Notes:

- The spec's `webhooks` object is empty. Webhook fixtures come from the documentation pages instead (see below).
- Several ids in the spec's examples are not valid ULIDs (22 or 24 characters). Response DTOs do not validate ids, so the examples still hydrate. Request tests use valid ULIDs.
- The example `nextToken` values are base64 pagination cursors, not credentials. `.gitleaks.toml` allowlists them for this file only.

### Refreshing

1. Download https://docs.pey.mx/openapi.json over this file without reformatting it.
2. Update the version, date and SHA-256 above.
3. Run `composer test`. A failing contract test means the API changed: update the DTOs, enums and `.claude/rules/pey-api.md` in the same PR.

## `webhooks/*.json`

The example payload of each documented webhook, retrieved 2026-11-20. Values and number literals are verbatim (for example `153.50`, `100.00`); only whitespace was normalized, because the HTML rendering drops line breaks. `WebhookContractTest` checks the event classes against them.

| File | Source |
|---|---|
| `codi.qr.payment_received.json` | https://docs.pey.mx/en/docs/webhooks/codi/qr |
| `codi.pn.payment_received.json` | https://docs.pey.mx/en/docs/webhooks/codi/pn |
| `payment_link.status_updated.json` | https://docs.pey.mx/en/docs/webhooks/payment-link/status-update |
| `bank.account.updated.json` | https://docs.pey.mx/en/docs/webhooks/bank-account/updated |

Signing secrets for webhook tests live in `tests/Support/StandardWebhooksVectors.php`. They are the published Standard Webhooks test vector and a second test-only secret, and `.gitleaks.toml` allowlists that one file.
