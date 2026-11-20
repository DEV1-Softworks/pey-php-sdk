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

- The spec's `webhooks` object is empty. Webhook fixtures come from the documentation pages instead (see `.claude/rules/pey-api.md`).
- Several ids in the spec's examples are not valid ULIDs (22 or 24 characters). Response DTOs do not validate ids, so the examples still hydrate. Request tests use valid ULIDs.
- The example `nextToken` values are base64 pagination cursors, not credentials. `.gitleaks.toml` allowlists them for this file only.

### Refreshing

1. Download https://docs.pey.mx/openapi.json over this file without reformatting it.
2. Update the version, date and SHA-256 above.
3. Run `composer test`. A failing contract test means the API changed: update the DTOs, enums and `.claude/rules/pey-api.md` in the same PR.
