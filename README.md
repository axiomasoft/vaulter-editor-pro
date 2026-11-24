# vaulter-editor-pro

Vaulter Editor Pro — zero-royalty OSS replacement for Tiptap Pro: History Preview and Compare, Range Comments, Tracked Changes and structural review, and bidirectional DOCX OOXML exchange.

Private / proprietary channel for FlexCrm and Edufy.

## Layout

| Path | Artifact | Role |
| --- | --- | --- |
| `packages/review/` | `axiomasoft/vaulter-editor-pro-review` | Review, comments and suggestions |
| `packages/exchange/` | `axiomasoft/vaulter-editor-pro-exchange` | Server-side exchange jobs |
| `packages/docx/` | `@vaulter/editor-pro-docx` | Tiptap JSON and OOXML mapping |
| `packages/vue/` | `@vaulter/editor-pro-vue` | Vue 3 review and DOCX UI |
| `release/packages.json` | — | Private PHP ZIP and npm tgz manifest |

Composer path repository `packages/*` and npm workspaces ship `review`, `exchange`, `docx` and `vue`. CI on PHP 8.4 and 8.5 runs migrations, `unindexed-fks.sql` and Pest on PostgreSQL 18.7 and 19.0 and on SQLite, plus PHPStan, Pint, and Node.js 26.13.1 `test`, `typecheck` and `build` for `docx` and `vue`.

## Toolchain

- PHP `>=8.4 <8.6`
- Node.js `>=26.13.1` (`package.json` `engines.node`)
- PostgreSQL CI matrix: 18.7 and 19.0

## CI

`.github/workflows/ci.yml` validates the Composer and npm manifests, runs the PHP 8.4/8.5 matrix against PostgreSQL 18.7 and 19.0 (migrate the suite database, then `scripts/ci/sql/unindexed-fks.sql`, then Pest on PostgreSQL and SQLite), runs PHPStan and Pint, builds and tests `@vaulter/editor-pro-docx` and `@vaulter/editor-pro-vue` on Node.js 26.13.1, and fails when a dependency or a source file hits a stop-crane (`UNLICENSED`, `@tiptap-pro`, copyleft, or a banned call). The workflow's negative fixture must fail on a forbidden package, an `UNLICENSED` license, or a banned call.
