# Configuration

Each module reads one config section, compiled once into an immutable options object. Publish
a section only to change a default:

| Module | Config file | Publish tag | Options class |
| --- | --- | --- | --- |
| review | `config/vaulter-editor-pro-review.php` | `vaulter-editor-pro-review-config` | `Vaulter\EditorPro\Review\Configuration\ReviewOptions` |
| exchange | `config/vaulter-editor-pro-exchange.php` | `vaulter-editor-pro-exchange-config` | `Vaulter\EditorPro\Exchange\Configuration\ExchangeOptions` |

Routes, authentication middleware and the API prefix come from the Vaulter core config
(`vaulter.routes`), not from these sections.

## Review

The section is closed. An unknown key, a value of the wrong type or a limit below its minimum
throws `Packman\Config\Exceptions\InvalidConfiguration` listing every offending path; it is
reported by `php artisan packman:config:validate` and `php artisan vaulter:doctor`.

| Key | Type | Default | Minimum | Effect |
| --- | --- | --- | --- | --- |
| `pins.enforce_retention` | bool | `true` | — | Pinned revisions are protected from revision pruning, and a pin without `expires_at` also marks the revision keep-forever. `false` leaves retention to Vaulter. |
| `anchors.max_selector_bytes` | int | `16384` | `256` | Largest JSON-encoded `selector_payload` of a comment anchor. |
| `anchors.max_selector_depth` | int | `16` | `2` | Deepest JSON nesting of a `selector_payload`. |
| `anchors.max_quote_bytes` | int | `4096` | `64` | Largest `quote_text` stored with an anchor, in bytes. The HTTP request additionally caps `quote_text` at 4096 characters. |

## Exchange

| Key | Env | Type | Default | Effect |
| --- | --- | --- | --- | --- |
| `formats` | — | list | `['docx']` | Enabled formats. A job for another format answers `exchange_format_disabled`. |
| `default_profile` | — | string | `discussions` | Profile used when a job omits `profile_id`. |
| `profiles` | — | list | `standard`, `discussions`, `legacy-discussions`, `revisions`, `strict-ooxml` | Allowed profiles; another `profile_id` answers `exchange_invalid_command` (reason `invalid_profile`). See [profiles](../concepts/docx-corpus.md#profiles). |
| `max_import_bytes` | — | int | 50 MiB | Largest uploaded or stored DOCX accepted for import. |
| `max_export_bytes` | — | int | 50 MiB | Largest document JSON read for export and largest DOCX written. |
| `zip.max_entries` | — | int | `2000` | Most entries in an archive. |
| `zip.max_entry_bytes` | — | int | 50 MiB | Largest uncompressed entry. |
| `zip.max_total_bytes` | — | int | 150 MiB | Largest uncompressed archive. |
| `zip.max_ratio` | — | int | `100` | Highest compression ratio of an entry (zip-bomb guard). |
| `zip.max_rels_bytes` | — | int | 256 KiB | Largest `.rels` part. |
| `lease_seconds` | — | int | `60` | Worker lease on a running job. A job whose lease expires is reclaimed by `vaulter-editor-pro-exchange:recover`, and the old worker's result is fenced off (`exchange_lease_lost`). |
| `job_retention_seconds` | — | int | 7 days | Age after which finished jobs are purged by the recover command. |
| `auto_dispatch` | — | bool | `true` | Dispatch `RunDocxExchangeJob` when a job is registered. With `false` the host runs jobs itself (`ExchangeManager::forDrive($drive)->process($jobId)`). |
| `queue_connection` | `VAULTER_EXCHANGE_QUEUE_CONNECTION` | string\|null | `null` (default connection) | Queue connection for dispatched jobs. |
| `queue_name` | `VAULTER_EXCHANGE_QUEUE` | string | `default` | Queue name for dispatched jobs. |
| `node_binary` | `VAULTER_DOCX_NODE_BIN` | string | `node` | Node.js executable that runs the CLI. |
| `cli_path` | `VAULTER_DOCX_CLI_PATH` | string | `packages/docx/dist/cli.js` of this repository | Path to `@vaulter/editor-pro-docx/dist/cli.js`. Set it in every installation outside this monorepo. |
| `runtime_timeout_seconds` | — | int | `30` | Timeout of one CLI run. |
| `persistent_worker` | — | bool | `false` | Keep one CLI process per PHP worker and talk to it over NDJSON instead of starting Node.js per conversion. Not in the shipped config file; add the key to enable it. |

The exchange section is read leniently: a missing or invalid value (a non-positive number, an
empty string) falls back to its default instead of failing validation, and `formats` and
`profiles` are trimmed and de-duplicated. Check a published file against this table.
