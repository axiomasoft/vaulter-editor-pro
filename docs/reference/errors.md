# Error codes

Every failure of a review or exchange route is an [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457)
`application/problem+json` response in the Vaulter format:

```json
{
  "type": "https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md#exchange_unsafe_archive",
  "title": "Unsafe DOCX archive",
  "status": 422,
  "code": "exchange_unsafe_archive",
  "detail": "The DOCX archive failed pre-parse security verification.",
  "retryable": "never",
  "request_id": "01M88NNYVMW9J6EJGAA6VC4QZG",
  "errors": null,
  "meta": {"reason": "exchange_unsafe_archive"}
}
```

Branch on `code`; `title` and `detail` are localized (English and Russian ship with the
packages, chosen by `Content-Language`) and may change. `retryable` is `never` or
`with_backoff`: the same request may succeed later. **Receipt** is the idempotency policy: a
`cacheable` refusal is stored under the `Idempotency-Key` and replayed to a retry, a `never`
refusal is rolled back with the key. Codes from Vaulter itself (authentication, permissions,
validation, preconditions) are listed in the
[Vaulter error reference](https://github.com/axiomasoft/vaulter/blob/main/docs/reference/errors.md).

A failed exchange job does not answer with a problem: its job resource carries the code in
`error_code` and the reason in `diagnostics_report.reason` (for example `zip_slip`), and an
`apply` of that job then answers the problem.

The tables below are generated from the problem catalogue. The package tests fail when they
drift; regenerate them with `composer docs:errors`.

## Review

<!-- editor-pro:errors:review:start -->
| Code | HTTP | Retryable | Receipt | Exception |
|---|---|---|---|---|
| [`invalid_anchor_selector`](#invalid_anchor_selector) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Review\Exceptions\InvalidAnchorSelectorException` |
| [`invalid_comment_state`](#invalid_comment_state) | 409 | `never` | `cacheable` | `Vaulter\EditorPro\Review\Exceptions\InvalidCommentStateException` |
| [`review_anchor_not_found`](#review_anchor_not_found) | 404 | `never` | `never` | `Vaulter\EditorPro\Review\Exceptions\ReviewAnchorNotFoundException` |
| [`revision_pin_not_found`](#revision_pin_not_found) | 404 | `never` | `never` | `Vaulter\EditorPro\Review\Exceptions\RevisionPinNotFoundException` |
| [`suggestion_conflict`](#suggestion_conflict) | 409 | `never` | `cacheable` | `Vaulter\EditorPro\Review\Exceptions\SuggestionConflictException` |
| [`unsupported_suggestion`](#unsupported_suggestion) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Review\Exceptions\UnsupportedSuggestionException` |

#### `invalid_anchor_selector`

**Invalid anchor selector** — The comment range anchor selector is invalid for the document.

#### `invalid_comment_state`

**Invalid comment state** — The comment is not in a valid state for this operation.

#### `review_anchor_not_found`

**Review anchor not found** — The requested comment range anchor was not found.

#### `revision_pin_not_found`

**Revision pin not found** — The requested revision pin was not found.

#### `suggestion_conflict`

**Suggestion conflict** — The suggestion conflicts with another suggestion or the current document state.

#### `unsupported_suggestion`

**Unsupported suggestion structure** — The requested suggestion structure or operation is not supported by review capabilities.
<!-- editor-pro:errors:review:end -->

## Exchange

<!-- editor-pro:errors:exchange:start -->
| Code | HTTP | Retryable | Receipt | Exception |
|---|---|---|---|---|
| [`exchange_already_applied`](#exchange_already_applied) | 409 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_format_disabled`](#exchange_format_disabled) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_idempotency_conflict`](#exchange_idempotency_conflict) | 409 | `never` | `never` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_invalid_command`](#exchange_invalid_command) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_job_not_found`](#exchange_job_not_found) | 404 | `never` | `never` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_job_not_ready`](#exchange_job_not_ready) | 409 | `with_backoff` | `never` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_lease_lost`](#exchange_lease_lost) | 409 | `with_backoff` | `never` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_profile_blocked`](#exchange_profile_blocked) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_runtime_failed`](#exchange_runtime_failed) | 503 | `with_backoff` | `never` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_source_unavailable`](#exchange_source_unavailable) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_stale_preview`](#exchange_stale_preview) | 409 | `never` | `never` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_unsafe_archive`](#exchange_unsafe_archive) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_unsupported_document`](#exchange_unsupported_document) | 422 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |
| [`exchange_zip_limit_exceeded`](#exchange_zip_limit_exceeded) | 413 | `never` | `cacheable` | `Vaulter\EditorPro\Exchange\Exceptions\ExchangeException` |

#### `exchange_already_applied`

**Exchange import already applied** — This import job has already been applied with a different apply_command_id.

#### `exchange_format_disabled`

**Exchange format disabled** — The requested exchange format is disabled.

#### `exchange_idempotency_conflict`

**Exchange request key conflict** — A different exchange request already exists with this request_key.

#### `exchange_invalid_command`

**Invalid exchange command** — The document exchange command payload is invalid.

#### `exchange_job_not_found`

**Exchange job not found** — The requested document exchange job was not found.

#### `exchange_job_not_ready`

**Exchange job is not ready** — The exchange job has not finished preparing its artifact yet.

#### `exchange_lease_lost`

**Exchange worker lease lost** — The worker lease expired or was fenced before committing the exchange result.

#### `exchange_profile_blocked`

**DOCX compatibility profile blocked** — The selected DOCX compatibility profile blocked this exchange operation.

#### `exchange_runtime_failed`

**DOCX runtime failed** — The DOCX conversion runtime failed to process the request.

#### `exchange_source_unavailable`

**Exchange source unavailable** — The referenced Media blob or revision source is missing or unavailable.

#### `exchange_stale_preview`

**Import preview is stale** — The document changed after this import preview was generated; regenerate preview before applying.

#### `exchange_unsafe_archive`

**Unsafe DOCX archive** — The DOCX archive failed pre-parse security verification.

#### `exchange_unsupported_document`

**Document does not support DOCX exchange** — This document type or state does not support DOCX exchange.

#### `exchange_zip_limit_exceeded`

**DOCX archive limits exceeded** — The DOCX archive exceeded size, entry count, or compression ratio limits.
<!-- editor-pro:errors:exchange:end -->
