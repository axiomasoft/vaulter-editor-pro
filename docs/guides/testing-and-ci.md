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

`.github/workflows/ci.yml` is a DAG. GitHub draws it from each job's `needs`:

```
changes ──► quality ──► php (8.4/8.5 × PostgreSQL 18.7/19.0, SQLite once per PHP)
                   ├──► mysql (MySQL 9.7 exchange and review integration)
                   ├──► node (docx and vue test, typecheck, build)
                   ├──► docx-libreoffice (corpus gate)
                   └──► e2e (Playwright against PostgreSQL 18.7)
                         └──► CI gate
```

1. **Changes** selects lanes. A push to `main`, a `workflow_dispatch`, or a change under
   `.github/` or `scripts/ci/` runs every lane. On other branches, `dorny/paths-filter` skips
   lanes whose files did not change.
2. **Quality** is the fast fail: the Node engines assertion, stop-cranes, `composer validate`,
   Pint, PHPStan, actionlint and zizmor. Heavier lanes start only after it succeeds.
3. **PHP, MySQL, Node, LibreOffice and E2E** then run in parallel. The PHP 8.4 / PostgreSQL 18.7 cell
   uploads a Clover coverage artifact.
4. **CI gate** waits for every job (`if: always()`) and fails if any needed job failed or was
   cancelled. A skipped lane (path filter) is allowed. Branch protection should require only
   `CI gate`.

Triggers: pushes to `main`, `refactor/**`, `feat/**`, `fix/**`, `hotfix/**` and `chore/**`, pull
requests, and manual `workflow_dispatch`. Concurrent runs on the same ref cancel the earlier one.
Every action is pinned to a commit SHA and the database images to a digest. PHP and Node setup,
Composer/npm caching, and workflow lint live in composite actions under `.github/actions/`.

Sibling repositories are checked out by `scripts/ci/checkout-ecosystem.sh`: `vaulter` and
`vaulter-collaboration` at `main`, Packman at the tag pinned in the root `composer.json`. The
secret `VAULTER_READ_TOKEN` is optional while those repositories are public; when set, it is
sent as an HTTP header, never in a URL. Without the secret the checkout only works for public
repositories. Hosted Ubuntu 24.04 runners (or self-hosted ones with Docker) are required for
service containers.
