# `@vaulter/editor-pro-docx`

Isomorphic DOCX (OOXML) converter for Vaulter Editor Pro. It maps TipTap JSON, including
range comments, tracked suggestions and document sections, to DOCX and back through an
intermediate model, and reports every feature it cannot carry over in a loss report.

## Entry points

| Import | Use |
| --- | --- |
| `@vaulter/editor-pro-docx` | Resolves to the browser or Node build by export condition |
| `@vaulter/editor-pro-docx/browser` | Browser build (ZIP via `fflate`), plus the worker message types |
| `@vaulter/editor-pro-docx/node` | Node build (ZIP via `yauzl`) |
| `@vaulter/editor-pro-docx/cli` | The JSON-over-stdin CLI the PHP exchange module runs (`dist/cli.js`) |

Main functions: `exportDocx`, `importDocx`, `previewDocxExport`, `getProfileCapabilities`.
Compatibility profiles: `standard`, `discussions`, `legacy-discussions`, `revisions`,
`strict-ooxml` (see [corpus and profiles](../../docs/concepts/docx-corpus.md)).

Every archive is checked before parsing: entry count, entry and total size, compression
ratio, path traversal, symlinks, duplicate entries, DOCTYPE/entity/XInclude constructs, XML
depth, external relationships and embedded media (format, SVG content, size).

## Development

```bash
npm test          # vitest
npm run typecheck
npm run build     # dist/, including dist/cli.js
```

Requires Node.js `>=26.13.1`.

## License

Proprietary. See [LICENSE](LICENSE).
