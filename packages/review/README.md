# Vaulter Editor Pro Review (`axiomasoft/vaulter-editor-pro-review`)

Laravel module `vaulter.review` for Vaulter documents: immutable revision pins, read-only
revision preview and compare, range comments anchored to a document generation, and tracked
suggestions (text and structural) with accept and reject.

## Requirements

- PHP `>=8.4 <8.6`, Laravel 13
- `axiomasoft/vaulter-core`, `axiomasoft/vaulter-drive` and `axiomasoft/vaulter-documents` 1.x

## Install

```bash
composer require axiomasoft/vaulter-editor-pro-review
php artisan vendor:publish --tag=vaulter-editor-pro-review-config   # optional
php artisan migrate
```

The service provider is auto-discovered and the module activates automatically once its
required Vaulter modules are active. Migrations run from the package; they are not published.

## What it adds

- HTTP routes under the Vaulter API prefix (`api/vaulter/v1` by default): `review/documents/{node}/pins`,
  `…/preview`, `…/compare`, `review/nodes/{node}/comments`, `review/documents/{node}/suggestions`.
- PHP entry point `Vaulter\EditorPro\Review\ReviewManager` (container alias `vaulter.review`):
  `pins($drive)`, `comments($drive)`, `suggestions($drive)`.
- Tables `v_document_revision_pins`, `v_document_comment_anchors`, `v_document_suggestions`,
  `v_document_comment_states`.
- Problem codes `review_anchor_not_found`, `invalid_anchor_selector`, `revision_pin_not_found`,
  `invalid_comment_state`, `suggestion_conflict`, `unsupported_suggestion`.

## Documentation

- [Getting started](../../docs/getting-started/installation.md)
- [Configuration](../../docs/reference/configuration.md#review)
- [HTTP API](../../docs/reference/http-api.md#review)
- [Error codes](../../docs/reference/errors.md#review)

## License

Proprietary. See [LICENSE](LICENSE).
