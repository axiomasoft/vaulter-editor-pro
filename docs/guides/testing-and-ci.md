# Testing and CI

Setup (sibling checkouts, `composer install`, `npm ci`) is in [CONTRIBUTING](../../CONTRIBUTING.md).
This page explains what each suite covers and what CI runs.

## Suites

| Suite | Location | Runner | Needs |
| --- | --- | --- | --- |
| review | `packages/review/tests/{Unit,Arch,Feature,Integration}` | Pest (`--testsuite=review`) | SQLite or PostgreSQL |
| exchange | `packages/exchange/tests/{Arch,Feature}` | Pest (`--testsuite=exchange`) | SQLite or PostgreSQL, a built `packages/docx/dist/cli.js`, Node.js |
| docx | `packages/docx/tests` | Vitest | Node.js |
| vue | `packages/vue/tests` | Vitest (happy-dom) | a built `@vaulter/editor-pro-docx` and sibling `@vaulter/vue` |
| LibreOffice corpus gate | `scripts/ci/docx-libreoffice.sh` | bash + Node.js | `soffice` on `PATH`, built docx package |
| E2E | `tests/e2e/specs` | Playwright (Chromium) | PHP, PostgreSQL 18, sibling `vaulter` and `vaulter-collaboration` builds |
| Static | `phpstan.neon.dist`, Pint, `scripts/ci/engineering-rails.py` | | |

The PHP suites boot Testbench with the Vaulter modules they depend on and migrate a fresh
schema. `phpunit.xml.dist` defaults to in-memory SQLite.

`Arch` tests guard contracts that are easy to break silently, for example that every problem
code has one status, an exception, English and Russian texts, and an up-to-date row in
[docs/reference/errors.md](../reference/errors.md).

## Running PHP tests

```bash
composer test            # review, then exchange; builds the DOCX CLI first if it is stale
composer test:parallel   # both suites in Pest parallel workers
```

On PostgreSQL set `DB_CONNECTION=pgsql` and the `DB_*` variables; the database name must end in
`_test`. For the parallel run also set `VAULTER_TEST_DB_PER_PROCESS=1` and create
`<name without _test>_w<N>_test` for N = 1 … number of CPUs (for `vaulter_test`:
`vaulter_w1_test`, `vaulter_w2_test`, …). Each worker migrates and refreshes its own database,
so workers never block each other. Measured on four CPUs with PostgreSQL 18, the parallel run
takes about half the serial time.

Port 5432 is often taken on shared machines; any port works through `DB_PORT`.

## Running the E2E suite

```bash
npx playwright install chromium
npx playwright test --config=tests/e2e/playwright.config.mjs
```

The harness starts Testbench, the `vaulter-collaboration` hub (from the sibling checkout, or
`VAULTER_COLLAB_HUB_CLI`) and a Vite server for `tests/e2e/frontend`. It uses the database in
`E2E_DB_*` (falling back to `DB_*`) when it is reachable, and otherwise starts a temporary
PostgreSQL 18 cluster from `/usr/lib/postgresql/18/bin`.

## CI

`.github/workflows/ci.yml` runs on pushes to `main`, `feat/**` and `fix/**`, on pull requests
and manually. Every action is pinned to a commit SHA and the PostgreSQL images to a digest.

| Job | What it checks |
| --- | --- |
| Stop-cranes | `composer validate`; `engineering-rails.py`: license denylist (copyleft, `UNLICENSED`, `@tiptap-pro`), banned calls and patterns in PHP, SQL and tests, and its own negative self-test |
| PHP *version* / PG *version* | Matrix PHP 8.4 and 8.5 × PostgreSQL 18.7 and 19.0: build the DOCX CLI, migrate, `scripts/ci/sql/unindexed-fks.sql` (no foreign key without an index), Pest in parallel on PostgreSQL with a database per worker, and on SQLite (once per PHP version) |
| PHP *version* static | PHPStan at level max with strict rules, the SQL stop rail and disallowed calls; Pint |
| Node docx and vue | Vitest, typecheck and build of both npm packages |
| DOCX LibreOffice roundtrip | The corpus gate above |
| Playwright E2E | The E2E suite against PostgreSQL 18.7 |
| CI gate | Fails unless every job above succeeded; the one check a branch rule should require |

Sibling repositories are checked out by `scripts/ci/checkout-ecosystem.sh`: `vaulter` and
`vaulter-collaboration` at `main`, Packman at the tag pinned in the root `composer.json`.

### Requirements of a repository that runs this workflow

- GitHub Actions enabled, with hosted Ubuntu 24.04 runners (service containers need Docker).
- Read access to `axiomasoft/vaulter`, `axiomasoft/vaulter-collaboration` and
  `axiomasoft/packman` (the script fetches them from `github.com/axiomasoft` whatever the
  owner of the repository running the workflow) through the secret `VAULTER_READ_TOKEN`.
  Without the secret the checkout only works for public repositories.
