# Quick start

This page walks through the HTTP API with `curl`. It assumes the default prefix
`/api/vaulter/v1`, an authenticated client (`$TOKEN`) and a Vaulter document node `$NODE`.
Every write route accepts an `Idempotency-Key` header; a retry with the same key replays the
first answer and carries `Idempotent-Replayed: true`. Failures are `application/problem+json`
bodies, see [Error codes](../reference/errors.md).

```bash
API=https://app.example/api/vaulter/v1
H=(-H "Authorization: Bearer $TOKEN" -H 'Accept: application/json')
```

## Review

Pin a revision so pruning keeps it, then compare it with the working document:

```bash
curl "${H[@]}" -H 'Idempotency-Key: pin-1' -X POST \
  "$API/review/documents/$NODE/revisions/$REVISION/pin" -d pin_reason=review_base

curl "${H[@]}" "$API/review/documents/$NODE/compare?from=$REVISION"          # to defaults to the working copy
curl "${H[@]}" "$API/review/documents/$NODE/revisions/$REVISION/preview"
```

Add a comment anchored to characters 6–11 of a block. `snapshot_utf16` offsets count UTF-16
code units, like ProseMirror; `generation`, `source_position` and `schema_version` describe
the document state the client saw:

```bash
curl "${H[@]}" -H 'Content-Type: application/json' -H 'Idempotency-Key: c-1' -X POST \
  "$API/review/nodes/$NODE/comments" -d '{
    "body": "Should this be plural?",
    "anchor": {
      "generation": 3, "source_position": 42, "schema_version": 2,
      "block_id": "p_intro",
      "selector_kind": "snapshot_utf16",
      "selector_payload": {"from_utf16": 6, "to_utf16": 11},
      "quote_text": "world"
    }
  }'

curl "${H[@]}" "$API/review/nodes/$NODE/comments?status=active"
```

The create answers `201` with an `ETag`. Changing the comment later (resolve, reopen, edit,
reanchor, archive, delete) requires a strong `If-Match` with the current tag; a missing or weak
tag is refused, so two reviewers cannot overwrite each other.

When later edits delete the quoted text the anchor becomes `detached` and keeps its quote; a
restore of an older generation marks earlier anchors `historical`.

## DOCX export

```bash
curl "${H[@]}" -H 'Content-Type: application/json' -H 'Idempotency-Key: ex-1' -X POST \
  "$API/documents/$NODE/exchange-jobs" -d '{"kind": "export", "profile_id": "discussions"}'
# 202 {"data": {"id": "<job>", "state": "queued", ...}}

curl "${H[@]}" "$API/documents/$NODE/exchange-jobs/$JOB"          # poll until state = completed
curl "${H[@]}" -o document.docx "$API/documents/$NODE/exchange-jobs/$JOB/file"
```

## DOCX import

An import is a two-step operation: the job produces a preview, and a separate `apply` writes it
to the document if the document has not changed since.

```bash
curl "${H[@]}" -H 'Idempotency-Key: im-1' -X POST "$API/documents/$NODE/exchange-jobs" \
  -F kind=import -F profile_id=discussions -F file=@contract.docx
# 202, state queued -> running -> preview_ready (or failed)

curl "${H[@]}" "$API/documents/$NODE/exchange-jobs/$JOB"          # data.preview, data.diagnostics_report

curl "${H[@]}" -H 'Content-Type: application/json' -H 'Idempotency-Key: ap-1' -X POST \
  "$API/documents/$NODE/exchange-jobs/$JOB/apply" -d '{"apply_command_id": "apply-1"}'
# 200 (or 202 while the write is pending); the job becomes applied
```

If the document changed after the preview, `apply` answers `exchange_stale_preview`: register a
new import. An archive that fails the security checks ends in state `failed` with
`error_code: exchange_unsafe_archive` or `exchange_zip_limit_exceeded` and the reason in
`diagnostics_report.reason`; see [DOCX import security](../security/docx-import.md).

## From PHP

```php
use Vaulter\EditorPro\Exchange\ExchangeManager;
use Vaulter\EditorPro\Review\ReviewManager;

$pins = app(ReviewManager::class)->pins($drive);          // $drive: a Vaulter DriveHandle
$pins->pin($node, $revision);

$jobs = app(ExchangeManager::class)->forDrive($drive);
$jobs->status($node, $jobId);
```
