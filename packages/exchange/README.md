# Vaulter Editor Pro Exchange (`axiomasoft/vaulter-editor-pro-exchange`)

Laravel module `vaulter.exchange`: asynchronous DOCX import and export jobs for Vaulter
documents. Uploaded archives pass ZIP and XML security checks before any conversion, the
conversion runs `@vaulter/editor-pro-docx` in a Node.js subprocess, artifacts are stored and
pinned as Media blobs, and an import is applied to the document atomically against the
generation it was previewed from.

## Requirements

- PHP `>=8.4 <8.6` with `ext-zip`, `ext-xmlreader`, `ext-libxml`, `ext-mbstring`; Laravel 13
- `axiomasoft/vaulter-core`, `-drive`, `-documents` and `-media` 1.x
- Node.js `>=26.13.1` and a built `@vaulter/editor-pro-docx` CLI on the worker hosts
- A Laravel queue worker when `auto_dispatch` is on (the default)

## Install

```bash
composer require axiomasoft/vaulter-editor-pro-exchange
npm install @vaulter/editor-pro-docx
php artisan vendor:publish --tag=vaulter-editor-pro-exchange-config
php artisan migrate
```

Point `VAULTER_DOCX_CLI_PATH` at `node_modules/@vaulter/editor-pro-docx/dist/cli.js` (the
default path only exists inside this monorepo), and schedule
`php artisan vaulter-editor-pro-exchange:recover` to reclaim stale leases and purge expired jobs.

## What it adds

- HTTP routes under the Vaulter API prefix: `POST documents/{node}/exchange-jobs`,
  `GET …/exchange-jobs/{job}`, `GET …/exchange-jobs/{job}/file`, `POST …/exchange-jobs/{job}/apply`.
- PHP entry point `Vaulter\EditorPro\Exchange\ExchangeManager` (container alias `vaulter.exchange`).
- SPI `DocxExchangeRuntime` (the conversion runtime) and `ExchangeActorResolver` (who runs a
  queued job).
- Tables `v_document_exchange_jobs` and `v_document_sections`.

## Documentation

- [Getting started](../../docs/getting-started/installation.md)
- [Configuration](../../docs/reference/configuration.md#exchange)
- [DOCX import security](../../docs/security/docx-import.md)
- [Error codes](../../docs/reference/errors.md#exchange)

## License

Proprietary. See [LICENSE](LICENSE).
