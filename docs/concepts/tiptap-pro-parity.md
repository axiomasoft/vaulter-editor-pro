# TipTap Pro parity

Vaulter Editor Pro exists so that the family never depends on TipTap Pro or TipTap Cloud. Before it
was built, every entry of the TipTap Pro catalogue (93 at the time) was studied and given an owner.
This page keeps the outcome: what replaces each Pro module, and the design notes for the modules
that are not built yet.

## Covered

| TipTap Pro module | Replacement |
| --- | --- |
| Cloud provider, Document REST API, JWT authentication, webhooks | The base `vaulter` documents API and outbox webhooks, and `vaulter-collaboration` (hub, room JWT, journal, fencing) |
| Comments | Anchored range comments in `vaulter-editor-pro-review` (UTF-16 anchors over Yjs, threads, replies, resolve, archive) |
| Snapshots, Snapshot Compare, Compare | Revision pins, read-only preview and structural compare in `vaulter-editor-pro-review` and `@vaulter/editor-pro-vue` |
| Tracked Changes | Text and structural tracked suggestions with accept/reject in `vaulter-editor-pro-review` |
| ConvertKit, DOCX import and export | `@vaulter/editor-pro-docx` and the `vaulter-editor-pro-exchange` jobs, checked against the D1–D5 corpus |
| Markdown import and export | The base `vaulter/markdown` document type with the open-source `@tiptap/markdown` |
| AI Generation, Client and Server AI Toolkit | `vaulter-ai`: Office SDK, proposals and receipts, resumable chat, TipTap AI commands and autocomplete |

Utilities that TipTap has since open-sourced (`UniqueID`, `TableOfContents`, `DragHandle`,
`FileHandler`, `FindAndReplace`, `TextStyleKit`, `Emoji`, `Mathematics`, `Details`,
`InvisibleCharacters`) are reused from `@tiptap/*` rather than rewritten. Block identity is the
family's own `blockId` extension, because `UniqueID` does not give the split, join and paste
semantics that receipts depend on.

## Not built yet

Each of these is an independent, opt-in extension with its own data owner, schema version and
acceptance corpus. None of them may pull a closed dependency into the base editor.

### Office paste normalisation

TipTap's Paste Handler rewrites clipboard HTML in the browser. The replacement needs normalisation
rules for Word, Outlook, Google Docs and Google Sheets HTML, checked against a clipboard corpus, and
must report what it dropped. A styled table pasted from a spreadsheet stays a rich-text table; it is
not a workbook.

### File drop and paste

`@tiptap/extension-file-handler` only hands dropped or pasted files to the application. Bind its
callbacks to the Media upload flow so that size limits, scanning and access control apply exactly
as for any other upload. It is a different feature from paste normalisation.

### PDF, ODT and EPUB export

TipTap's PDF, ODT and EPUB exporters call its conversion service. The replacement renders a pinned
revision on the server with pinned fonts and an access check at render time, and reports the
properties it could not represent. ODT and EPUB only on a product request.

### Pages and page breaks

TipTap Pages paginates in the editor and needs a pagination-safe table kit; page breaks are a
separate node. `@vaulter/editor-pro-docx` already reads and writes `pageBreak` blocks, but the
editor schema has no page-break node, so breaks are not visible or editable. Layout pagination is
not a substitute for a server-side export.

### Template slots and variables

TipTap's Slots and Variables are client-side alphas: the document stores a reference, the value
lives only in the editor. The replacement needs typed, fill-only fields with validation, values
resolved from sources the user is authorised to read, and a server-side commit so that a headless
caller (an agent filling a contract or a lesson) gets the same result as a person.

### Content protection for human edits

TipTap's Content Protection is client-side only. `vaulter-ai` enforces its
`ContentProtectionPolicy` on AI writes, but the hub and the base documents API do not consult it, so
a person can still change a protected node. Either enforce the policy on every write path or
document it as an AI-only guard.

### Comment mentions and notifications

Threads, replies, resolve and archive exist. There is no way to address a person in a comment, no
unread state and no notification, which TipTap Cloud provides through comment webhooks. A design
needs trusted identities for mentions, per-user read markers and delivery through the outbox.

### Diagrams and Whiteboard

Both are pilot-only in TipTap Pro. A whiteboard brings its own document, undo and text schema, so
it is a separate resource type with its own room protocol, not a node inside rich text. Only on a
product request.
