# Vaulter Editor Pro documentation

Vaulter Editor Pro adds review and DOCX exchange to Vaulter documents. It is a set of four
packages built from this repository:

| Package | Kind | Role |
| --- | --- | --- |
| `axiomasoft/vaulter-editor-pro-review` | Laravel module `vaulter.review` | Revision pins, preview and compare, anchored range comments, tracked suggestions |
| `axiomasoft/vaulter-editor-pro-exchange` | Laravel module `vaulter.exchange` | Asynchronous DOCX import and export jobs |
| `@vaulter/editor-pro-docx` | npm, browser and Node | TipTap JSON ⇄ DOCX converter and the CLI the exchange module runs |
| `@vaulter/editor-pro-vue` | npm, Vue 3 | Review and DOCX UI components and composables |

## Getting started

- [Installation](getting-started/installation.md)
- [Quick start](getting-started/quick-start.md): pin a revision, comment on a range, import and export a DOCX over HTTP

## Concepts

- [Architecture](concepts/architecture.md)
- [DOCX profiles and the D1–D5 corpus](concepts/docx-corpus.md)

## Reference

- [Configuration](reference/configuration.md)
- [HTTP API](reference/http-api.md)
- [Error codes](reference/errors.md)

## Guides

- [Testing and CI](guides/testing-and-ci.md)

## Security

- [DOCX import security](security/docx-import.md)
- [Reporting a vulnerability](../SECURITY.md)
