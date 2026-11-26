# Architecture

Vaulter Editor Pro extends Vaulter documents without changing Vaulter itself. The two PHP
packages are Packman extension plugins that contribute to Vaulter's extension slots; the two
npm packages are a converter and a UI library that talk to the PHP side over the Vaulter REST
API.

```text
              @vaulter/editor-pro-vue  (Vue 3 + TipTap UI)
                 │ REST /api/vaulter/v1              │ local export (Web Worker)
                 ▼                                   ▼
 ┌─────────────── Laravel host ───────────────┐  @vaulter/editor-pro-docx (browser build)
 │ vaulter.review        vaulter.exchange      │
 │   pins, preview,        DOCX jobs ──────────┼─▶ node dist/cli.js (Node build, subprocess)
 │   comments, suggestions │                   │
 │        └──── Vaulter core, drive, documents, media ────┘
 └────────────────────────────────────────────┘
```

## PHP modules

Both modules are registered by their auto-discovered service provider through
`VaulterPluginRegistrar` and activate automatically when the Vaulter modules they require are
active. Each declares, in one module class (`EditorProReviewModule`, `EditorProExchangeModule`):

- its config section and options class (see [Configuration](../reference/configuration.md));
- its tables, prefixed `v_`, with migrations and Packman schema components;
- its problem codes (see [Error codes](../reference/errors.md));
- its routes, translations, container bindings and operation policies;
- its contributions to Vaulter slots.

| Slot contribution | Module | What it does |
| --- | --- | --- |
| `DriveExtension` `review.pins`, `review.comments`, `review.suggestions` | review | Exposes the operations classes on a Vaulter drive handle |
| `SnapshotWriteHook` `review.snapshot-anchors` | review | Remaps `snapshot_utf16` anchors inside every document content write |
| `EventSink` (restore anchor transition) | review | On `DocumentRestored` marks anchors of earlier generations `historical`; on cross-generation replacement marks them `detached` |
| `RevisionPruneProtection` `review.revision-pins` | review | Keeps pinned revisions out of revision pruning (`pins.enforce_retention`) |
| `DriveExtension` `exchange.jobs` | exchange | Exposes `ExchangeOperations` on a drive handle |
| `EventSink` `exchange.purge-pins` | exchange | Releases the Media pins of exchange jobs deleted with a purged document |
| `RouteLabelSource` | both | Metric labels of the module's routes (Vaulter request duration and throttling metrics) |

Entry points from PHP are `ReviewManager` (`pins()`, `comments()`, `suggestions()`) and
`ExchangeManager` (`forDrive()`, `reclaimExpiredLeases()`, `purgeExpiredJobs()`), each taking a
Vaulter `DriveHandle`, so every call runs with the drive's actor, tenant and permission checks.

### Review

| Table | Holds |
| --- | --- |
| `v_document_revision_pins` | Pinned revisions with reason (`manual_version`, `review_base`, `comment_history`, `exchange_source`) and optional expiry |
| `v_document_comment_anchors` | One range anchor per Vaulter comment (`v_node_comments`): generation, selector, quote, state |
| `v_document_comment_states` | Edit and archive markers of a comment (`edited_at`, `archived_at`, `archived_by_subject`) |
| `v_document_suggestions` | Text and structural suggestions with versions and groups |

An anchor is scoped to the document generation it was created on. A `snapshot_utf16` selector
counts UTF-16 code units (the offsets ProseMirror and JavaScript use) and is remapped by the
server on every snapshot write; a `yjs_relative` selector carries Yjs relative positions for
collaborative documents. An anchor is `attached`, `detached` (its text is gone; the quote is
kept) or `historical` (an older generation was restored).

Suggestions are written through the document's write mode: an atomic snapshot mutation, or the
`vaulter-collaboration` command journal for documents in journal mode (that package is then
required).

### Exchange

| Table | Holds |
| --- | --- |
| `v_document_exchange_jobs` | One import or export job: state, profile, source and output Media blobs, worker lease, diagnostics, apply receipt |
| `v_document_sections` | DOCX sections (page setup, headers, footers) imported into or exported from a document |

A job moves `queued` → `running` → `preview_ready` (import) or `completed` (export) →
`applied` (import), or ends in `failed`.

1. **Register.** The request is checked (format, profile, permissions). An export stores a
   snapshot of the document as a Media blob; an import stores the uploaded archive. The source
   blob is pinned to the job. With `auto_dispatch` the `RunDocxExchangeJob` queue job is dispatched; it carries
   only scalar IDs and re-checks permissions when it runs, as the actor resolved by
   `ExchangeActorResolver`.
2. **Process.** The worker takes a lease (`lease_seconds`). An import archive is first checked by
   `DocxZipArchiveGuard` (see [DOCX import security](../security/docx-import.md)). The
   conversion runs in the `DocxExchangeRuntime`, by default `node cli.js` with a JSON request on
   stdin and DOCX bytes passed through `0600` temp files. The result is committed only while the
   lease is still held; a worker that lost its lease gets `exchange_lease_lost`. A runtime
   failure (`exchange_runtime_failed`) returns the job to `queued` for the queue's next attempt;
   once the queue gives up (the worker's `--tries` are spent, or at once under `sync`) the job
   is marked `failed` with that error. A deterministic refusal marks it `failed` immediately.
3. **Apply (import).** `apply` locks the job and writes the imported content, sections,
   discussions and suggestions in one atomic document mutation, compare-and-set against the
   generation the preview was built from. A changed document answers `exchange_stale_preview`.
   `apply_command_id` makes the apply idempotent; a different id on an applied job answers
   `exchange_already_applied`.
4. **Recover.** `vaulter-editor-pro-exchange:recover` reclaims expired leases. With automatic dispatch it resubmits these jobs and aged queued jobs. Purging expired jobs releases their Media pins.

Both `DocxExchangeRuntime` and `ExchangeActorResolver` are SPIs: bind your own implementation
in the container to run conversions elsewhere or to resolve queued actors differently.

## npm packages

`@vaulter/editor-pro-docx` converts between TipTap JSON (with sections, discussions and
suggestions) and DOCX through an intermediate model. The same code runs in three places: the
PHP exchange runtime (`dist/cli.js`), the browser (local export in a Web Worker) and tests. Every
feature that does not survive a conversion is listed in a loss report; a profile decides whether
such a loss blocks the conversion (see [DOCX profiles and corpus](docx-corpus.md)).

`@vaulter/editor-pro-vue` provides the review UI (history compare, range comments, tracked
changes panel) and the DOCX UI (server exchange through the REST API, local export through
`@vaulter/editor-pro-vue/worker`), built on `@vaulter/vue`.
