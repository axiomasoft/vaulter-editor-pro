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

## Documentation

English documentation lives in [`docs/`](docs/index.md): installation, architecture, DOCX
profiles and the D1–D5 corpus, configuration, HTTP API, error codes, testing and CI, and DOCX
import security. Development setup is in [CONTRIBUTING.md](CONTRIBUTING.md), vulnerability
reporting in [SECURITY.md](SECURITY.md).

## CI

`.github/workflows/ci.yml` runs on pushes to `main`, `feat/**` and `fix/**`, on pull requests
and on demand. Siblings (`vaulter`, `vaulter-collaboration`, Packman at the pinned tag) are
checked out by `scripts/ci/checkout-ecosystem.sh` with `secrets.VAULTER_READ_TOKEN`. Jobs:

- **Stop-cranes**: `composer validate` and `scripts/ci/engineering-rails.py` (license
  denylist: copyleft, `UNLICENSED`, commercial TipTap and Univer package scopes; banned calls and patterns), with its negative
  self-test.
- **PHP 8.4/8.5 × PostgreSQL 18.7/19.0**: build the DOCX CLI, migrate,
  `scripts/ci/sql/unindexed-fks.sql`, then `composer test:parallel` on PostgreSQL (one database
  per worker) and on SQLite.
- **MySQL 9.7** runs exchange and review integration tests, including interrupted migration recovery.
- **PHP static**: PHPStan at level max and Pint.
- **Node 26.13.1**: Vitest, typecheck and build of `@vaulter/editor-pro-docx` and
  `@vaulter/editor-pro-vue`.
- **DOCX LibreOffice roundtrip** of the D1–D5 corpus, and **Playwright E2E**.
- **CI gate** verifies that every selected job succeeded and every other lane was skipped. It is the required branch check.

Actions are pinned to commit SHAs and database images to digests. Details:
[Testing and CI](docs/guides/testing-and-ci.md).
