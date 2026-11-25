# Installation

Vaulter Editor Pro is a proprietary add-on to Vaulter. It is distributed through the private
release channel (`release/packages.json` lists the PHP ZIP and npm tarball artifacts); the
commands below assume that channel is configured as a Composer repository and an npm registry.

## Requirements

| | Version |
| --- | --- |
| PHP | `>=8.4 <8.6` (exchange also needs `ext-zip`, `ext-xmlreader`, `ext-libxml`) |
| Laravel | 13 |
| Vaulter | `axiomasoft/vaulter-core`, `-drive`, `-documents` 1.x; exchange also `-media` 1.x |
| Node.js | `>=26.13.1` (exchange workers run the DOCX CLI; the frontend build) |
| Database | PostgreSQL (CI runs 18.7 and 19.0) or SQLite |

## 1. Backend

Install either module or both:

```bash
composer require axiomasoft/vaulter-editor-pro-review
composer require axiomasoft/vaulter-editor-pro-exchange
```

Both service providers are auto-discovered. Each registers a Vaulter module (`vaulter.review`,
`vaulter.exchange`) that activates automatically when the modules it requires are active
(core, drive and documents; exchange also media).

Publish the config only if you want to change a default, then run the migrations. Migrations
run from the package and are not published:

```bash
php artisan vendor:publish --tag=vaulter-editor-pro-review-config
php artisan vendor:publish --tag=vaulter-editor-pro-exchange-config
php artisan migrate
php artisan vaulter:doctor
```

`vaulter:doctor` (and `packman:config:validate`) report an invalid review config section, for
example an unknown key or a selector limit below its minimum.

The routes are added to the Vaulter API group, `api/vaulter/v1` by default, behind the
Vaulter authenticated middleware (`vaulter.routes.middleware.authenticated`). Write routes use
the Vaulter `Idempotency-Key` middleware.

## 2. DOCX runtime (exchange only)

The exchange module converts documents by running the `@vaulter/editor-pro-docx` CLI with
Node.js on the host that executes exchange jobs:

```bash
npm install @vaulter/editor-pro-docx
```

Then set, in `.env` or in the published config:

```dotenv
VAULTER_DOCX_CLI_PATH=/absolute/path/to/node_modules/@vaulter/editor-pro-docx/dist/cli.js
VAULTER_DOCX_NODE_BIN=node
```

The default `cli_path` points at `packages/docx/dist/cli.js` inside this repository and only
works in a monorepo checkout. When the file is missing every attempt fails with
`exchange_runtime_failed` (reason `cli_missing`): the job goes back to `queued` while the queue
retries it and ends `failed` once the worker's `--tries` are spent.

## 3. Queue and maintenance (exchange only)

With `auto_dispatch` on (the default) registering a job dispatches `RunDocxExchangeJob` on
`queue_connection` / `queue_name` (`VAULTER_EXCHANGE_QUEUE_CONNECTION`,
`VAULTER_EXCHANGE_QUEUE`), so run a queue worker for that queue. With the `sync` queue driver
the job runs inside the request.

Schedule the recovery command. It moves `running` jobs whose worker lease expired back to
`queued`, and deletes `preview_ready`, `completed`, `applied` and `failed` jobs not updated for
`job_retention_seconds`, releasing their Media pins:

```php
// routes/console.php
Schedule::command('vaulter-editor-pro-exchange:recover --limit=100')->everyFiveMinutes();
```

## 4. Frontend

```bash
npm install @vaulter/editor-pro-vue
```

It needs the peer dependencies `vue`, `@tiptap/core`, `@tiptap/pm`, `@vaulter/vue`, `yjs` and
`prosemirror-changeset`. See the [package README](../../packages/vue/README.md) for the exported
components and composables.

Next: [Quick start](quick-start.md).
