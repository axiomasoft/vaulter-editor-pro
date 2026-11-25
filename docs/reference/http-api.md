# HTTP API

All routes are registered in the Vaulter API group: the prefix is
`vaulter.routes` `versionedPrefix()` (`api/vaulter/v1` by default), the middleware is the
Vaulter authenticated stack, and route names start with `vaulter.v1.`. Path parameters match
`[A-Za-z0-9_-]+`. Routes marked **idempotent** run behind the Vaulter `vaulter.idempotency`
middleware: an `Idempotency-Key` header makes a retry replay the first answer. Errors are
[problem documents](errors.md).

## Review

| Method | Path | Name | Idempotent | Purpose |
| --- | --- | --- | --- | --- |
| GET | `review/documents/{node}/pins` | `review.pins.index` | | List revision pins |
| POST | `review/documents/{node}/revisions/{revision}/pin` | `review.pins.store` | yes | Pin a revision (`pin_reason`, `expires_at`) |
| DELETE | `review/documents/{node}/revisions/{revision}/pin` | `review.pins.destroy` | yes | Unpin |
| GET | `review/documents/{node}/revisions/{revision}/preview` | `review.revisions.preview` | | Read-only content of a revision |
| GET | `review/documents/{node}/compare?from=&to=` | `review.revisions.compare` | | Compare two revisions; `to` omitted or `working` means the working document |
| GET | `review/nodes/{node}/comments?status=` | `review.comments.index` | | List comments; `status` is `all` (default), `active`, `resolved`, `detached` or `archived` |
| POST | `review/nodes/{node}/comments` | `review.comments.store` | yes | Add a comment, optionally anchored (`snapshot_utf16` or `yjs_relative` selector) or as a reply (`parent_id`) |
| POST | `review/nodes/{node}/comments/{comment}/resolve` | `review.comments.resolve` | yes | Resolve (`If-Match`) |
| POST | `review/nodes/{node}/comments/{comment}/reopen` | `review.comments.reopen` | yes | Reopen (`If-Match`) |
| POST | `review/nodes/{node}/comments/{comment}/reanchor` | `review.comments.reanchor` | yes | Move the anchor to a new range (`If-Match`) |
| PATCH | `review/nodes/{node}/comments/{comment}` | `review.comments.update` | yes | Edit the body, author only (`If-Match`) |
| POST | `review/nodes/{node}/comments/{comment}/archive` | `review.comments.archive` | yes | Archive (`If-Match`) |
| DELETE | `review/nodes/{node}/comments/{comment}` | `review.comments.destroy` | yes | Delete (`If-Match`) |
| GET | `review/documents/{node}/suggestions/capabilities` | `review.suggestions.capabilities` | | Suggestion kinds and structures the server accepts |
| GET | `review/documents/{node}/suggestions` | `review.suggestions.index` | | List suggestions |
| POST | `review/documents/{node}/suggestions` | `review.suggestions.store` | yes | Save a text or structural suggestion |
| POST | `review/documents/{node}/suggestions/{suggestion}/accept` | `review.suggestions.accept` | yes | Accept one suggestion |
| POST | `review/documents/{node}/suggestions/{suggestion}/reject` | `review.suggestions.reject` | yes | Reject one suggestion |
| POST | `review/documents/{node}/suggestions/groups/{group}/accept` | `review.suggestions.groups.accept` | yes | Accept a group (for example a replacement) |
| POST | `review/documents/{node}/suggestions/groups/{group}/reject` | `review.suggestions.groups.reject` | yes | Reject a group |
| POST | `review/documents/{node}/suggestions/bulk/accept` | `review.suggestions.bulk.accept` | yes | Accept many |
| POST | `review/documents/{node}/suggestions/bulk/reject` | `review.suggestions.bulk.reject` | yes | Reject many |

Accept and reject take `generation` (the document generation the decision was made on),
`expected_version` (one suggestion) or `expected_versions` (a group, keyed by suggestion id)
and an optional `command_id`. Bulk decisions take `generation`, `scope` (`all`; `by_user` with
`author_subject`; `in_range` with `from` and `to`) and an optional `command_id`.

Suggestion kinds: `insert`, `delete`, `replace`, `mark_change`, `attr_change`, `block_split`,
`block_join`, `list_change`, `table_change`.

The suggestion and pin lists are not paginated: the editor renders every open suggestion of the
current generation, and pins are created one per revision on purpose. Comments, which grow
without such a bound, are paged with `per_page` and a cursor. A document generation holds at
most 1000 open suggestions; saving a new one beyond that answers `unsupported_suggestion` with
reason `open_suggestion_limit` until some are accepted or rejected. Amending an existing draft
is not affected.

## Exchange

| Method | Path | Name | Idempotent | Purpose |
| --- | --- | --- | --- | --- |
| POST | `documents/{node}/exchange-jobs` | `exchange.jobs.store` | yes | Register an import or export job; `202` while queued or running, `200` for an existing finished job |
| GET | `documents/{node}/exchange-jobs/{job}?include_preview=` | `exchange.jobs.show` | | Job state, diagnostics and (import) preview; `include_preview` defaults to true |
| GET | `documents/{node}/exchange-jobs/{job}/file` | `exchange.jobs.file` | | Download the exported DOCX (`ETag` is its SHA-256) |
| POST | `documents/{node}/exchange-jobs/{job}/apply` | `exchange.jobs.apply` | yes | Apply an import preview; `200`, or `202` while the write is pending |

Register body: `kind` (`import` or `export`, required), `format` (`docx`), `profile_id`,
`request_key`, and for an import the source as a multipart `file`, `docx_b64` or
`source_blob_id`. `request_key` identifies the job; when it is omitted the `Idempotency-Key`
header is used, and a request without either is refused. Reusing a key with different
parameters answers `exchange_idempotency_conflict`. Apply body: `apply_command_id`
and optionally `expected_generation` / `expected_position`.

Job states: `queued` → `running` → `preview_ready` (import) or `completed` (export) →
`applied` (import, after apply); any of them may end in `failed` with `error_code` and
`diagnostics_report`.
