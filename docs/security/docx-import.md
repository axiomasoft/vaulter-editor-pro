# DOCX import security

A DOCX file is a ZIP archive of XML parts, so an uploaded document is untrusted input to two
parsers. The exchange module checks it twice, in PHP before conversion and again inside the
converter, and never extracts it to disk.

## Before conversion (PHP)

`DocxZipArchiveGuard` opens the archive read-only with consistency checks and inspects every
entry before any part is parsed. A violation fails the job with `exchange_unsafe_archive` or
`exchange_zip_limit_exceeded`; the reason is in the job's `diagnostics_report.reason`, with the
entry name in `diagnostics_report.entry` when there is one.

| Check | Reason |
| --- | --- |
| Not a ZIP, inconsistent central directory, empty, encrypted | `not_a_zip`, `invalid_zip`, `empty_archive`, `encrypted_archive` |
| Entry count above `zip.max_entries` | `too_many_entries` |
| Entry above `zip.max_entry_bytes`, archive above `zip.max_total_bytes` | `entry_size_limit`, `total_size_limit` |
| Compression ratio above `zip.max_ratio`, or more bytes than declared while reading | `compression_ratio_limit`, `zip_bomb_overflow`, `size_mismatch` |
| Unreadable or corrupt entries, bad entry headers or sizes, directories with content | `invalid_entry_header`, `invalid_entry_size`, `unreadable_entry`, `corrupt_stream`, `non_empty_directory_entry` |
| Absolute or drive paths, backslashes, `.` or `..` segments (zip slip); empty, non-UTF-8 or duplicate (case-insensitive) names | `zip_slip`, `invalid_entry_name`, `duplicate_entry_name` |
| Symlinks and special files in the entry attributes | `zip_symlink`, `zip_special_file` |
| Missing `[Content_Types].xml` or main document part | `missing_ooxml_parts` |
| `DOCTYPE`, `ENTITY`, `ELEMENT`, `ATTLIST` declarations or XInclude in any XML part | `xxe_doctype`, `xxe_or_xinclude`, `xxe_xinclude` |
| XML nesting deeper than 256 elements, malformed XML or encoding | `xml_depth_exceeded`, `malformed_xml`, `invalid_xml_encoding` |
| `.rels` part above `zip.max_rels_bytes` or malformed | `oversized_rels`, `malformed_rels` |
| External relationship that is not a hyperlink to `http`, `https` or `mailto` | `disallowed_external_relationship` |

Entries are read with bounded buffers; their declared sizes are not trusted.

## During conversion (Node.js)

The `@vaulter/editor-pro-docx` CLI receives the same ZIP quotas and applies its own guard
(`DocxSecurityError`): archive structure, quotas and compression ratio, path traversal,
symlinks, duplicate and overlapping entries, DTD/entity/XInclude constructs, XML depth,
external relationships, and embedded media (allowed formats, SVG content, media size and image
dimensions). Its archive and XML violations map to the same two problem codes.

The CLI runs as a separate process with a timeout (`runtime_timeout_seconds`). DOCX bytes are
exchanged through temporary files created with mode `0600` and deleted afterwards, and the
JSON request and response carry a request id that must match. An import result is held as a
preview and reaches the document only through `apply`.

## Exports

An export writes only hyperlinks with allowed schemes and bounds its output by
`max_export_bytes`. The file download is sent with `X-Content-Type-Options: nosniff` and
`Cache-Control: no-store, private`.

## Operating advice

- Keep the ZIP quotas near the defaults unless real documents need more; they bound memory
  and CPU per job.
- Run exchange workers with the least privileges the queue needs; the Node.js process inherits
  the worker's environment and file access.
- Report vulnerabilities as described in [SECURITY.md](../../SECURITY.md).
