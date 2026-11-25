# Contributing to Vaulter Editor Pro

This repository holds two Laravel packages (`packages/review`, `packages/exchange`) and two
npm workspaces (`packages/docx`, `packages/vue`). See the [README](README.md) for what each
one ships.

## Workspace layout

Composer path repositories and the CI checkout expect the sibling repositories next to this
one:

```text
workspace/
├── vaulter-editor-pro/      # this repository
├── vaulter/                 # axiomasoft/vaulter (core, media, drive, documents)
├── vaulter-collaboration/   # axiomasoft/vaulter-collaboration
└── packman/                 # axiomasoft/packman at the tag pinned in composer.json
```

`scripts/ci/checkout-ecosystem.sh` creates any missing sibling at the ref CI uses (Packman at
the tag named in the root `composer.json`, the Vaulter repositories at `main`). For private
repositories export `VAULTER_READ_TOKEN`; the script sends it as an HTTP header and never
writes it to a URL or git config.

## Prerequisites

- PHP `>=8.4 <8.6` with Composer
- Node.js `>=26.13.1` with npm
- PostgreSQL 18 or 19 for the server-database lane (optional locally; SQLite is the default)

## Setup

```bash
scripts/ci/checkout-ecosystem.sh
composer install
npm ci
```

## Checks

| What | Command |
| --- | --- |
| Code style | `composer lint:check` (fix with `composer lint`) |
| Static analysis (level max) | `composer phpstan` |
| Engineering rails (stop-cranes) | `python3 scripts/ci/engineering-rails.py` |
| PHP tests, one suite after the other | `composer test` |
| PHP tests, parallel workers | `composer test:parallel` |
| DOCX converter | `npm test`, `npm run typecheck`, `npm run build` in `packages/docx` |
| Vue components | `npm test`, `npm run typecheck`, `npm run build` in `packages/vue` |

The exchange tests run the DOCX converter as a subprocess, so both test scripts first run
`composer docx:cli`, which builds `packages/docx/dist/cli.js` when it is missing or older than
its sources. Build `packages/docx` before `packages/vue`: the Vue package imports the built
converter.

### PostgreSQL

`phpunit.xml.dist` defaults to in-memory SQLite. To run against PostgreSQL, export the usual
Laravel variables; the database name must end in `_test`:

```bash
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
       DB_DATABASE=vaulter_test DB_USERNAME=vaulter DB_PASSWORD=secret
composer test
```

For `composer test:parallel` on PostgreSQL, also set `VAULTER_TEST_DB_PER_PROCESS=1` and create
one database per worker, `vaulter_w1_test` … `vaulter_w<N>_test` for N = number of CPUs. Each
worker then migrates and refreshes its own database, which is what CI does.

## Commits and branches

- Work on a branch; `main` is updated through reviewed merges only.
- Use [Conventional Commits](https://www.conventionalcommits.org/) with the package as the
  scope (`review`, `exchange`, `docx`, `vue`, `repo`, `ci`, `deps`). The body says what
  changed and why.
- Every suppression (`@phpstan-ignore`, `@ts-expect-error`) carries its reason next to it.
  Skipped tests are not allowed; the rails reject `->skip()`.
- Do not commit AI agent or editor configuration; `.gitignore` excludes the known paths.
