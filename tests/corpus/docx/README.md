# Synthetic DOCX OOXML Corpus (D1–D5)

Deterministic OOXML and TipTap JSON test corpus for `@vaulter/editor-pro-docx` and `@vaulter/editor-pro-vue`:

- **D1 (`d1-body-table.docx` / `d1-body-table.tiptap.json`)**: Heading, paragraph formatting (`textAlign`, `indent`, `spacingBefore`/`spacingAfter`), bullet & ordered (`start=3`) lists, `hardBreak`, and table (`tblGrid`, `tblHeader`, 2×2 merge with `gridSpan=2`/`vMerge`, `bold` + external hyperlink inside merged cell, cell shading & vertical alignment).
- **D2 (`d2-sections.docx` / `d2-sections.tiptap.json`)**: Two sections (`sectPr`), distinct `default`/`first`/`even` headers and footers, `evenAndOddHeaders`, `titlePg`, footer inheritance into section 2, and explicit page break (`w:br w:type="page"`).
- **D3 (`d3-discussions.docx`, `d3-discussions-libreoffice.docx` / `d3-discussions.tiptap.json`)**: Range-anchored comments (`w:commentRangeStart`/`End`/`Reference`), `w14:paraId` and `w16cid:durableId` strictly in `00000001..7FFFFFFF` linked via last `<w:p>` to `commentsExtended.xml`/`commentsIds.xml`, replies (`w15:paraIdParent`), resolved state (`w15:done="1"`), detached discussion, and a LibreOffice-style variant omitting `commentsExtended.xml` for coordinate fallback testing.
- **D4 (`d4-revisions.docx` / `d4-revisions.tiptap.json`)**: Tracked insertions (`w:ins`), deletions (`w:del` + `<w:delText xml:space="preserve">`), paired replacement group metadata (`customXml/item1.xml`), `block_join` (`<w:pPr><w:rPr><w:del/></w:rPr></w:pPr>`), and native formatting revision (`w:rPrChange`) loss-report fixture.
- **D5 (`d5-rich-layout.docx` / `d5-rich-layout.tiptap.json`)**: Embedded image with crop, footnote, table and cell borders and margins, bookmark with an internal link, blockquote, `codeBlock`, nested ordered list, and formatting revision (`w:rPrChange`).

What each class covers and which profile carries it: [docs/concepts/docx-corpus.md](../../../docs/concepts/docx-corpus.md).
