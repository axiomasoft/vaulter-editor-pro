# DOCX profiles and the D1–D5 corpus

## Feature classes

The DOCX converter groups Word features into five classes, D1 to D5. Each class has a
synthetic fixture in `tests/corpus/docx/`: a TipTap document defined in `corpus.ts`, its JSON
(`dN-*.tiptap.json`) and a committed `.docx`. The fixtures are deterministic and contain no
customer content.

| Class | Covers | Fixture |
| --- | --- | --- |
| **D1** Body and tables | Headings; paragraph alignment, indent and spacing; bullet and ordered lists (with a start number); hard breaks; tables with column widths, a repeated header row, horizontal and vertical merges, cell shading and vertical alignment; bold and external hyperlinks inside cells | `d1-body-table` |
| **D2** Sections | Several sections (`w:sectPr`) with distinct default, first-page and even-page headers and footers, footer inheritance into a later section, explicit page breaks | `d2-sections` |
| **D3** Discussions | Range comments (`w:commentRangeStart`/`End`/`Reference`), replies, resolved state, a detached discussion; Word's paragraph and durable comment IDs (`w14:paraId`, `w16cid:durableId`, kept in `00000001..7FFFFFFF`); a LibreOffice variant without `commentsExtended.xml` that exercises the fallback | `d3-discussions`, `d3-discussions-libreoffice` |
| **D4** Revisions | Tracked insertions and deletions (`w:ins`, `w:del` with preserved whitespace), replacement groups, paragraph joins, and a native formatting revision (`w:rPrChange`) that is reported as a loss | `d4-revisions` |
| **D5** Rich layout | Embedded images with crop, footnotes (and endnotes), table and cell borders and margins, bookmarks with internal links, block quotes, code blocks, nested ordered lists, formatting revisions; edge cases with SVG and VML images | `d5-rich-layout` |

In the server, D2 sections are stored in `v_document_sections`, D3 discussions as Vaulter
comments with anchors in `v_document_comment_anchors`, and D4 revisions as suggestions in
`v_document_suggestions`, so an imported document keeps its review state and an export can
write it back.

## Profiles

A profile chooses which classes a conversion carries and whether a loss blocks it. Every
profile blocks when a mandatory feature cannot be represented.

| Profile | Discussions (D3) | Threaded replies | Revisions (D4) | Formatting revisions required | Default projection | Use |
| --- | --- | --- | --- | --- | --- | --- |
| `standard` | no | no | no | no | `original` | Clean body, tables and sections (D1, D2) with accepted text |
| `discussions` | yes | yes | no | no | `original` | Adds range comments, replies, resolve state and detached discussions (default exchange profile) |
| `legacy-discussions` | yes | no | no | no | `original` | Flattens replies and omits the extended threading parts, for older editors |
| `revisions` | yes | yes | yes | no | `review` | Adds tracked changes and replacement groups; a lost formatting revision is reported |
| `strict-ooxml` | yes | yes | yes | yes | `review` | Blocks when any mandatory feature or a native formatting revision would lose fidelity |

Callers can switch discussions and revisions off per request (`enableDiscussions`,
`enableRevisions`) and require formatting revisions (`requireFormattingRevisions`). The
server only accepts profiles listed in the exchange `profiles` config.

## Where the corpus is tested

- `packages/docx/tests/corpusRoundtrip.test.ts` round-trips every class through export and
  import, compares the result and checks the loss report; it also imports the committed `.docx`
  files.
- `scripts/ci/docx-libreoffice.sh` (CI job *DOCX LibreOffice roundtrip*) opens each committed
  fixture in headless LibreOffice, saves it as DOCX and PDF, and imports LibreOffice's DOCX
  again, checking tables, sections, revisions, images, footnotes and key phrases survived.
- `packages/exchange/tests/Feature/DocxExchangeLifecycleTest.php` runs D2–D4 through the PHP
  exchange module end to end.
