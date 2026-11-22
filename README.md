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

Package trees arrive in later waves. This scaffold is the root workspace: Composer path repository `packages/*`, npm workspaces, PHPStan, PHPUnit, the release manifest and CI.

## Toolchain

- PHP `>=8.4 <8.6`
- Node.js `>=26.13.1` (`package.json` `engines.node`)
- PostgreSQL CI matrix: 18.7 and 19.0

## CI

`.github/workflows/ci.yml` validates `composer.json`, asserts Node.js 26.13.1, runs PostgreSQL 18.7 and 19.0, applies the unindexed-foreign-key gate, and fails when a dependency or a source file hits a stop-crane. The workflow's negative fixture must fail on a forbidden package or a banned call.
