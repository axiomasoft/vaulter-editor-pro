import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { zipSync, unzipSync } from 'fflate'
import {
  compareSemanticReports,
  exportDocx,
  fromIntermediate,
  importDocx,
  isValidOoxmlHexId,
  parseXmlPart,
  roundtripDocx,
  toIntermediate,
  findAllDescendants,
  findChildren,
  getAttr,
  decodeUtf8,
  encodeUtf8,
  nodeZipReader,
} from '../src/node'
import {
  CORPUS_D1,
  CORPUS_D2,
  CORPUS_D3,
  CORPUS_D4,
  CORPUS_D5,
} from '../../../tests/corpus/docx/corpus'

describe('DOCX corpus D1–D5 roundtrip and loss report', () => {
  it('decodes XML character references once and preserves literal CDATA', () => {
    const root = parseXmlPart(
      `<root attr="&lt;&gt;&quot;&apos;&amp;&#38;&#x1F44B;">&amp;lt;&#x3C;&#62;<![CDATA[&amp;]]></root>`,
    )
    expect(root.attrs.attr).toBe(`<>"'&&👋`)
    expect(root.text).toBe('&lt;<>&amp;')
  })

  it('imports the referenced numbering level and applies its paragraph properties before inline overrides', async () => {
    const ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
    const blank = await exportDocx({ type: 'doc', content: [{ type: 'paragraph' }] })
    const entries = unzipSync(blank.binary!)
    entries['word/numbering.xml'] = new Uint8Array(encodeUtf8(`
      <w:numbering xmlns:w="${ns}">
        <w:abstractNum w:abstractNumId="1">
          <w:lvl w:ilvl="0"><w:pPr><w:ind w:left="240"/></w:pPr></w:lvl>
          <w:lvl w:ilvl="1"><w:pPr><w:ind w:left="480"/></w:pPr></w:lvl>
        </w:abstractNum>
        <w:num w:numId="7">
          <w:abstractNumId w:val="1"/>
          <w:lvlOverride w:ilvl="1">
            <w:startOverride w:val="4"/>
            <w:lvl w:ilvl="1"><w:pPr><w:ind w:left="720" w:hanging="180"/><w:rPr><w:i/></w:rPr></w:pPr></w:lvl>
          </w:lvlOverride>
        </w:num>
      </w:numbering>`))
    entries['word/document.xml'] = new Uint8Array(encodeUtf8(`
      <w:document xmlns:w="${ns}"><w:body>
        <w:p><w:pPr><w:numPr><w:numId w:val="7"/></w:numPr></w:pPr><w:r><w:t>Root</w:t></w:r></w:p>
        <w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="7"/></w:numPr><w:ind w:left="960"/></w:pPr>
          <w:r><w:t>Child &amp; sibling</w:t></w:r></w:p>
      </w:body></w:document>`))

    const imported = await importDocx(zipSync(entries), { profile: 'standard' })
    expect(imported.model.sections[0].blocks).toMatchObject([
      { type: 'paragraph', attrs: { indentLeftDxa: 240, list: { level: 0, start: 1 } } },
      {
        type: 'paragraph',
        attrs: { indentLeftDxa: 960, indentFirstLineDxa: -180, list: { level: 1, start: 4 } },
        inlines: [{ text: 'Child & sibling', marks: expect.arrayContaining([{ type: 'italic' }]) }],
      },
    ])
  })

  it('D1: roundtrips body/table widths, header row, 2x2 merge, bold, link, lists, and units losslessly', async () => {
    const exported = await exportDocx(
      { document: CORPUS_D1.document, sourceMarker: CORPUS_D1.sourceMarker },
      { profile: 'standard' },
    )
    expect(exported.blocked).toBe(false)
    expect(exported.binary).not.toBeNull()
    expect(exported.report.profile).toBe('standard')
    expect(exported.report.source_marker).toEqual(CORPUS_D1.sourceMarker)

    // Inspect raw OOXML XML invariants directly
    const zip = unzipSync(exported.binary!)
    const docXml = parseXmlPart(decodeUtf8(zip['word/document.xml']), 'word/document.xml')
    const numberingXml = parseXmlPart(decodeUtf8(zip['word/numbering.xml']), 'word/numbering.xml')
    const relsXml = parseXmlPart(decodeUtf8(zip['word/_rels/document.xml.rels']), 'word/_rels/document.xml.rels')

    const tbl = findAllDescendants(docXml, 'tbl')[0]
    expect(tbl).toBeDefined()
    const gridCols = findAllDescendants(tbl, 'gridCol').map((g) => getAttr(g, 'w:w', 'w'))
    expect(gridCols).toEqual(['1800', '1800', '1800'])
    expect(findAllDescendants(tbl, 'tblHeader')).toHaveLength(1)

    const gridSpans = findAllDescendants(tbl, 'gridSpan').map((s) => getAttr(s, 'w:val', 'val'))
    expect(gridSpans).toEqual(['2', '2'])
    const vMerges = findAllDescendants(tbl, 'vMerge').map((m) => getAttr(m, 'w:val', 'val') ?? 'continue')
    expect(vMerges).toEqual(['restart', 'continue'])

    // Verify hyperlink relationships & ordered start=3
    const hyperlinkRels = findChildren(relsXml, 'Relationship').filter((r) =>
      (getAttr(r, 'Type') ?? '').endsWith('/hyperlink'),
    )
    expect(hyperlinkRels.some((r) => getAttr(r, 'Target') === 'https://example.test/merged-cell')).toBe(true)
    const startTags = findAllDescendants(numberingXml, 'start').map((s) => getAttr(s, 'w:val', 'val'))
    expect(startTags).toContain('3')

    // Roundtrip: DOCX -> model -> DOCX -> model
    const rt = await roundtripDocx(exported.binary!, {
      profile: 'standard',
      zipReader: nodeZipReader,
    })
    expect(rt.firstImport).toBeDefined()
    expect(rt.firstImport!.model.sourceMarker).toEqual(CORPUS_D1.sourceMarker)
    expect(rt.exported.blocked).toBe(false)
    expect(rt.secondImport).not.toBeNull()

    const parity = compareSemanticReports(rt.firstImport!.semantic, rt.secondImport!.semantic)
    expect(parity.differences).toEqual([])
    expect(parity.equal).toBe(true)

    const table = rt.secondImport!.semantic.tables[0]
    expect(table.rows).toBe(2)
    expect(table.cols).toBe(3)
    expect(table.gridColsDxa).toEqual([1800, 1800, 1800])
    expect(table.headerRows).toBe(1)
    expect(table.cells[0]).toMatchObject({
      text: 'Bold merged cell Clause link',
      colspan: 2,
      rowspan: 2,
      bold: true,
      links: ['https://example.test/merged-cell'],
      backgroundHex: 'DDEEFF',
      verticalAlign: 'center',
    })

    // Convert back to TipTap JSON and verify structure
    const tiptapBack = fromIntermediate(rt.secondImport!.model)
    expect(tiptapBack.document.type).toBe('doc')
    expect(tiptapBack.document.content?.some((n) => n.type === 'table')).toBe(true)
  })

  it('D2: roundtrips 2 sections, distinct default/first/even headers & footers, inheritance, and explicit page break', async () => {
    const exported = await exportDocx(
      {
        document: CORPUS_D2.document,
        sections: CORPUS_D2.sections,
        sourceMarker: CORPUS_D2.sourceMarker,
      },
      { profile: 'standard' },
    )
    expect(exported.blocked).toBe(false)
    expect(exported.binary).not.toBeNull()

    const zip = unzipSync(exported.binary!)
    const settingsXml = parseXmlPart(decodeUtf8(zip['word/settings.xml']), 'word/settings.xml')
    expect(findAllDescendants(settingsXml, 'evenAndOddHeaders').length).toBeGreaterThanOrEqual(1)

    const docXml = parseXmlPart(decodeUtf8(zip['word/document.xml']), 'word/document.xml')
    expect(findAllDescendants(docXml, 'titlePg').length).toBeGreaterThanOrEqual(1)
    const pageBreaks = findAllDescendants(docXml, 'br').filter(
      (br) => getAttr(br, 'w:type', 'type') === 'page',
    )
    expect(pageBreaks).toHaveLength(1)

    const rt = await roundtripDocx(exported.binary!, {
      profile: 'standard',
      zipReader: nodeZipReader,
    })
    const parity = compareSemanticReports(rt.firstImport!.semantic, rt.secondImport!.semantic)
    expect(parity.differences).toEqual([])
    expect(parity.equal).toBe(true)

    const sections = rt.secondImport!.semantic.sections
    expect(sections).toHaveLength(2)
    expect(sections[0].firstPageVariant).toBe(false)
    expect(sections[0].explicitHeadersFooters).toMatchObject({
      'header.default': 'Section 1 default header',
      'header.even': 'Section 1 even header',
      'footer.default': 'Shared default footer',
    })
    expect(sections[1].firstPageVariant).toBe(true)
    expect(sections[1].explicitHeadersFooters).toMatchObject({
      'header.default': 'Section 2 default header',
      'header.first': 'Section 2 first header',
    })
    expect(sections[1].explicitHeadersFooters['footer.default']).toBeUndefined()
    expect(sections[1].effectiveHeadersFooters['footer.default']).toBe('Shared default footer')
    expect(sections[1].effectiveHeadersFooters['header.even']).toBe('Section 1 even header')
    expect(rt.secondImport!.semantic.explicitPageBreaks).toBe(1)
  })

  it('D3: roundtrips threaded discussions with 00000001..7FFFFFFF paraId/durableId, detached anchor, LibreOffice fallback & legacy profile', async () => {
    // Also test that out-of-range imported paraId/durableId are repaired into 00000001..7FFFFFFF
    const exported = await exportDocx(
      {
        document: CORPUS_D3.document,
        discussions: CORPUS_D3.discussions,
        sourceMarker: CORPUS_D3.sourceMarker,
      },
      { profile: 'discussions' },
    )
    expect(exported.blocked).toBe(false)
    expect(exported.binary).not.toBeNull()

    const zip = unzipSync(exported.binary!)
    expect(zip['word/comments.xml']).toBeDefined()
    expect(zip['word/commentsExtended.xml']).toBeDefined()
    expect(zip['word/commentsIds.xml']).toBeDefined()

    const commentsXml = parseXmlPart(decodeUtf8(zip['word/comments.xml']), 'word/comments.xml')
    const extXml = parseXmlPart(decodeUtf8(zip['word/commentsExtended.xml']), 'word/commentsExtended.xml')
    const idsXml = parseXmlPart(decodeUtf8(zip['word/commentsIds.xml']), 'word/commentsIds.xml')

    const commentEls = findChildren(commentsXml, 'comment')
    expect(commentEls.length).toBe(5)
    const commentLastParaIds = new Set<string>()
    for (const cEl of commentEls) {
      const pNodes = findChildren(cEl, 'p')
      const lastP = pNodes[pNodes.length - 1]
      const paraId = getAttr(lastP, 'w14:paraId', 'paraId')
      expect(isValidOoxmlHexId(paraId)).toBe(true)
      commentLastParaIds.add(paraId!)
    }

    for (const exEl of findChildren(extXml, 'commentEx')) {
      const pId = getAttr(exEl, 'w15:paraId', 'paraId')
      expect(isValidOoxmlHexId(pId)).toBe(true)
      expect(commentLastParaIds.has(pId!)).toBe(true)
    }
    for (const cidEl of findChildren(idsXml, 'commentId')) {
      const pId = getAttr(cidEl, 'w16cid:paraId', 'paraId')
      const dId = getAttr(cidEl, 'w16cid:durableId', 'durableId')
      expect(isValidOoxmlHexId(pId)).toBe(true)
      expect(isValidOoxmlHexId(dId)).toBe(true)
      expect(commentLastParaIds.has(pId!)).toBe(true)
    }

    // Roundtrip DOCX -> model -> DOCX -> model
    const rt = await roundtripDocx(exported.binary!, {
      profile: 'discussions',
      zipReader: nodeZipReader,
    })
    const parity = compareSemanticReports(rt.firstImport!.semantic, rt.secondImport!.semantic)
    expect(parity.differences).toEqual([])
    expect(parity.equal).toBe(true)

    const discussions = rt.secondImport!.semantic.discussions
    expect(discussions).toHaveLength(3)
    const resolvedThread = discussions.find((d) => d.id === 'thread-resolved')!
    expect(resolvedThread.resolved).toBe(true)
    expect(resolvedThread.anchorState).toBe('attached')
    expect(resolvedThread.comments).toHaveLength(2)
    expect(resolvedThread.comments[1].isReply).toBe(true)

    const detachedThread = discussions.find((d) => d.id === 'thread-detached')!
    expect(detachedThread.anchorState).toBe('detached')
    expect(detachedThread.comments).toHaveLength(2)

    const tiptap = fromIntermediate(rt.firstImport!.model)
    const fromTipTap = await exportDocx(tiptap, { profile: 'discussions' })
    const reimported = await importDocx(fromTipTap.binary!, { profile: 'discussions' })
    expect(reimported.model.discussions).toEqual(rt.firstImport!.model.discussions)

    // LibreOffice fallback: strip commentsExtended.xml & commentsIds.xml and verify import degrades gracefully
    const loEntries = { ...zip }
    delete loEntries['word/commentsExtended.xml']
    delete loEntries['word/commentsIds.xml']
    const loBytes = zipSync(loEntries)
    const loImport = await importDocx(loBytes, { profile: 'discussions', zipReader: nodeZipReader })
    expect(loImport.blocked).toBe(false)
    expect(loImport.report.diagnostics.some((d) => d.code === 'COMMENTS_EXTENDED_MISSING')).toBe(true)
    expect(loImport.model.discussions.length).toBeGreaterThanOrEqual(2)

    // Legacy discussions profile: flattens replies and omits extended/ids parts
    const legacyExport = await exportDocx(
      { document: CORPUS_D3.document, discussions: CORPUS_D3.discussions },
      { profile: 'legacy-discussions' },
    )
    expect(legacyExport.blocked).toBe(false)
    expect(legacyExport.report.diagnostics.some((d) => d.code === 'LEGACY_COMMENTS_FLATTENED')).toBe(true)
    const legacyZip = unzipSync(legacyExport.binary!)
    expect(legacyZip['word/comments.xml']).toBeDefined()
    expect(legacyZip['word/commentsExtended.xml']).toBeUndefined()
    expect(legacyZip['word/commentsIds.xml']).toBeUndefined()
  })

  it('D4: roundtrips tracked insert/delete/replace group metadata, block_join, xml:space="preserve", and flags native formatting revision', async () => {
    const exported = await exportDocx(
      { document: CORPUS_D4.document, sourceMarker: CORPUS_D4.sourceMarker },
      { profile: 'revisions', projection: 'review', enableRevisions: true },
    )
    expect(exported.blocked).toBe(false)
    expect(exported.binary).not.toBeNull()
    expect(
      exported.report.diagnostics.some((d) => d.code === 'NATIVE_FORMATTING_REVISION' && d.severity === 'warning'),
    ).toBe(true)

    const zip = unzipSync(exported.binary!)
    const docRaw = decodeUtf8(zip['word/document.xml'])
    expect(docRaw).toContain('<w:delText')
    expect(docRaw).toContain('xml:space="preserve"')
    expect(docRaw).toContain('<w:ins')
    expect(docRaw).toContain('<w:del')
    expect(docRaw).toContain('<w:rPrChange')
    expect(zip['customXml/item1.xml']).toBeDefined()

    const rt = await roundtripDocx(exported.binary!, {
      profile: 'revisions',
      projection: 'review',
      enableRevisions: true,
      zipReader: nodeZipReader,
    })
    const parity = compareSemanticReports(rt.firstImport!.semantic, rt.secondImport!.semantic)
    expect(parity.differences).toEqual([])
    expect(parity.equal).toBe(true)

    expect(rt.secondImport!.semantic.revisions.insertions.map((i) => i.text)).toEqual([
      '30',
      'added clause',
    ])
    expect(rt.secondImport!.semantic.revisions.deletions.map((d) => d.text)).toEqual([
      '15',
      ' only ',
    ])
    expect(rt.secondImport!.semantic.revisions.replacementGroups).toHaveLength(1)
    expect(rt.secondImport!.semantic.revisions.replacementGroups[0].groupId).toBe('grp-term-42')
    expect(rt.secondImport!.semantic.revisions.blockJoinDeletions).toBe(1)
    expect(rt.secondImport!.semantic.revisions.formattingRevisions).toBe(1)

    // External DOCX without customXml replacement metadata preserves insert/delete and reports group loss
    const externalEntries = { ...zip }
    delete externalEntries['customXml/item1.xml']
    const extBytes = zipSync(externalEntries)
    const extImport = await importDocx(extBytes, {
      profile: 'revisions',
      enableRevisions: true,
      zipReader: nodeZipReader,
    })
    expect(extImport.report.diagnostics.some((d) => d.code === 'REPLACEMENT_GROUP_METADATA_MISSING')).toBe(true)
    expect(extImport.semantic.revisions.insertions).toHaveLength(2)
    expect(extImport.semantic.revisions.deletions).toHaveLength(2)

    // Strict OOXML profile blocks on mandatory native formatting revision
    const strictBlocked = await exportDocx(
      { document: CORPUS_D4.document },
      { profile: 'strict-ooxml', enableRevisions: true },
    )
    expect(strictBlocked.blocked).toBe(true)
    expect(strictBlocked.binary).toBeNull()
    expect(
      strictBlocked.report.diagnostics.some(
        (d) => d.code === 'NATIVE_FORMATTING_REVISION_UNSUPPORTED' && d.severity === 'error',
      ),
    ).toBe(true)

    // Original projection excludes pending insertions and keeps baseline deletion text cleanly
    const orig = toIntermediate(CORPUS_D4.document, {
      profile: 'standard',
      projection: 'original',
    })
    expect(orig.diagnostics.some((d) => d.code === 'REVISIONS_PROJECTED_TO_ORIGINAL')).toBe(true)
    const origText = JSON.stringify(fromIntermediate(orig.model).document)
    expect(origText).toContain('15')
    expect(origText).not.toContain('added clause')
  })

  it('projects a nested live deletion and blocks its review export', async () => {
    const document = {
      type: 'doc' as const,
      content: [{
        type: 'paragraph',
        content: [
          { type: 'text', text: 'old', marks: [
            { type: 'reviewSuggestion', attrs: { suggestionId: 'parent', kind: 'delete' } },
          ] },
          { type: 'text', text: 'pending-child-deletion', marks: [
            { type: 'reviewSuggestion', attrs: { suggestionId: 'parent', kind: 'insert' } },
            { type: 'reviewSuggestion', attrs: { suggestionId: 'child', kind: 'delete', parentId: 'parent' } },
          ] },
          { type: 'text', text: 'new', marks: [
            { type: 'reviewSuggestion', attrs: { suggestionId: 'parent', kind: 'insert' } },
          ] },
        ],
      }],
    }
    const original = toIntermediate(document)
    expect(fromIntermediate(original.model).document.content?.[0].content).toEqual([{ type: 'text', text: 'old' }])
    const proposed = toIntermediate(document, { profile: 'revisions', projection: 'proposed' })
    expect(fromIntermediate(proposed.model).document.content?.[0].content).toEqual([{ type: 'text', text: 'new' }])

    const review = await exportDocx(document, { profile: 'revisions', projection: 'review' })
    expect(review.blocked).toBe(true)
    expect(review.binary).toBeNull()
    expect(review.report.diagnostics).toContainEqual(expect.objectContaining({
      code: 'NESTED_LIVE_REVISIONS_UNSUPPORTED',
      severity: 'error',
      action: 'blocked',
      location: 'doc.block[0].inline[1]',
    }))
  })

  it('blocks profile when an unsupported mandatory element or broken 2x2 merge geometry is present', async () => {
    const unsupportedDoc = {
      type: 'doc' as const,
      content: [
        { type: 'paragraph', content: [{ type: 'text', text: 'Valid' }] },
        { type: 'customDiagramWidget', attrs: { diagramId: 'x' } },
      ],
    }
    const res = await exportDocx({ document: unsupportedDoc }, { profile: 'standard' })
    expect(res.blocked).toBe(true)
    expect(res.binary).toBeNull()
    expect(res.report.diagnostics.some((d) => d.code === 'UNSUPPORTED_MANDATORY_BLOCK' && d.severity === 'error')).toBe(true)
    await expect(exportDocx({ document: unsupportedDoc }, { profile: 'standard', throwOnBlocked: true })).rejects.toThrow(/blocked/i)

    // Broken OOXML vMerge continue without restart blocks import
    const brokenZip = zipSync({
      '[Content_Types].xml': encodeUtf8(
        '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>',
      ),
      '_rels/.rels': encodeUtf8(
        '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
      ),
      'word/document.xml': encodeUtf8(
        '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl><w:tblGrid><w:gridCol w:w="1800"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p><w:r><w:t>Broken</w:t></w:r></w:p></w:tc></w:tr></w:tbl></w:body></w:document>',
      ),
    })

    const brokenImport = await importDocx(brokenZip, { profile: 'standard', zipReader: nodeZipReader })
    expect(brokenImport.blocked).toBe(true)
    expect(
      brokenImport.report.diagnostics.some(
        (d) => d.code === 'INVALID_TABLE_MERGE_GEOMETRY' && d.severity === 'error',
      ),
    ).toBe(true)
  })

  it('roundtrips committed on-disk D1-D4 DOCX corpus files and validates multi-paragraph comment paraId placement', async () => {
    const corpusDir = resolve(__dirname, '../../../tests/corpus/docx')
    const cases: Array<{ file: string; profile: 'standard' | 'discussions' | 'revisions'; enableRevisions?: boolean }> = [
      { file: 'd1-body-table.docx', profile: 'standard' },
      { file: 'd2-sections.docx', profile: 'standard' },
      { file: 'd3-discussions.docx', profile: 'discussions' },
      { file: 'd4-revisions.docx', profile: 'revisions', enableRevisions: true },
    ]

    for (const c of cases) {
      const bytes = new Uint8Array(readFileSync(resolve(corpusDir, c.file)))
      const rt = await roundtripDocx(bytes, {
        profile: c.profile,
        projection: c.enableRevisions ? 'review' : 'original',
        enableRevisions: c.enableRevisions,
        zipReader: nodeZipReader,
      })
      expect(rt.firstImport?.blocked).toBe(false)
      expect(rt.exported.blocked).toBe(false)
      expect(rt.secondImport).not.toBeNull()
      expect(compareSemanticReports(rt.firstImport!.semantic, rt.secondImport!.semantic)).toEqual({
        equal: true,
        differences: [],
      })
    }

    const loBytes = new Uint8Array(readFileSync(resolve(corpusDir, 'd3-discussions-libreoffice.docx')))
    const loImp = await importDocx(loBytes, { profile: 'discussions', zipReader: nodeZipReader })
    expect(loImp.report.diagnostics.some((d) => d.code === 'COMMENTS_EXTENDED_MISSING')).toBe(true)

    // Multi-paragraph comment without replies: MUST emit full triple and put w14:paraId on the LAST <w:p>
    const multiParaDoc = await exportDocx(
      {
        document: {
          type: 'doc',
          content: [
            {
              type: 'paragraph',
              content: [
                {
                  type: 'text',
                  text: 'Target phrase',
                  marks: [{ type: 'inlineThread', attrs: { 'data-thread-id': 't-multi' } }],
                },
              ],
            },
          ],
        },
        discussions: [
          {
            id: 't-multi',
            resolved: true,
            comments: [
              {
                id: 'c-multi',
                author: 'Reviewer',
                body: ['First paragraph in comment', 'Last paragraph in comment'],
                originalParaId: '00000000', // invalid zero -> must repair into 00000001..7FFFFFFF
                originalDurableId: '80000005', // invalid MSB=1 -> must repair into 00000001..7FFFFFFF
              },
            ],
          },
        ],
      },
      { profile: 'discussions' },
    )
    expect(multiParaDoc.blocked).toBe(false)
    const zip = unzipSync(multiParaDoc.binary!)
    expect(zip['word/comments.xml']).toBeDefined()
    expect(zip['word/commentsExtended.xml']).toBeDefined()
    expect(zip['word/commentsIds.xml']).toBeDefined()

    const commentsXml = parseXmlPart(decodeUtf8(zip['word/comments.xml']), 'word/comments.xml')
    const pNodes = findChildren(findChildren(commentsXml, 'comment')[0], 'p')
    expect(pNodes).toHaveLength(2)
    expect(getAttr(pNodes[0], 'w14:paraId', 'paraId')).toBeUndefined()
    const lastParaId = getAttr(pNodes[1], 'w14:paraId', 'paraId')
    expect(isValidOoxmlHexId(lastParaId)).toBe(true)

    const extXml = parseXmlPart(decodeUtf8(zip['word/commentsExtended.xml']), 'word/commentsExtended.xml')
    const exEl = findChildren(extXml, 'commentEx')[0]
    expect(getAttr(exEl, 'w15:paraId', 'paraId')).toBe(lastParaId)
    expect(getAttr(exEl, 'w15:done', 'done')).toBe('1')

    const idsXml = parseXmlPart(decodeUtf8(zip['word/commentsIds.xml']), 'word/commentsIds.xml')
    const idEl = findChildren(idsXml, 'commentId')[0]
    expect(getAttr(idEl, 'w16cid:paraId', 'paraId')).toBe(lastParaId)
    expect(isValidOoxmlHexId(getAttr(idEl, 'w16cid:durableId', 'durableId'))).toBe(true)
  })

  it('D5: roundtrips embedded images+crop, footnotes, table/cell borders+margins, bookmarks+internal links, blockquote, codeBlock, multilevel lists, and rPrChange', async () => {
    const exported = await exportDocx(
      { document: CORPUS_D5.document, sourceMarker: CORPUS_D5.sourceMarker },
      { profile: 'revisions', projection: 'review', enableRevisions: true },
    )
    expect(exported.blocked).toBe(false)
    expect(exported.binary).not.toBeNull()
    expect(exported.report.summary.error).toBe(0)
    expect(exported.report.diagnostics.map((d) => d.code)).toEqual(['NATIVE_FORMATTING_REVISION'])

    const zip = unzipSync(exported.binary!)
    expect(Object.keys(zip).some((k) => /^word\/media\/.+\.png$/i.test(k))).toBe(true)
    expect(zip['word/footnotes.xml']).toBeDefined()
    const docRaw = decodeUtf8(zip['word/document.xml'])
    expect(docRaw).toContain('<a:srcRect')
    expect(docRaw).toContain('<wp:extent')
    expect(docRaw).toContain('<w:bookmarkStart')
    expect(docRaw).toContain('w:anchor="d5_target"')
    expect(docRaw).toContain('w:val="Quote"')
    expect(docRaw).toContain('CodeBlock')
    expect(docRaw).toContain('<w:rPrChange')
    const numRaw = decodeUtf8(zip['word/numbering.xml'])
    expect(numRaw).toContain('lowerLetter')

    const rt = await roundtripDocx(exported.binary!, {
      profile: 'revisions',
      projection: 'review',
      enableRevisions: true,
    })
    expect(rt.firstImport?.blocked).toBe(false)
    expect(rt.secondImport?.blocked).toBe(false)
    expect(compareSemanticReports(rt.firstImport!.semantic, rt.secondImport!.semantic)).toEqual({
      equal: true,
      differences: [],
    })

    const tiptapRoundtrip = fromIntermediate(rt.firstImport!.model)
    const reexported = await exportDocx(tiptapRoundtrip.document, {
      profile: 'revisions',
      projection: 'review',
      enableRevisions: true,
      sourceMarker: CORPUS_D5.sourceMarker,
    })
    expect(reexported.blocked).toBe(false)
    const reimported = await importDocx(reexported.binary!, { profile: 'revisions' })
    expect(compareSemanticReports(rt.firstImport!.semantic, reimported.semantic)).toEqual({
      equal: true,
      differences: [],
    })

    const sem = rt.firstImport!.semantic
    expect(sem.images).toHaveLength(1)
    expect(sem.images[0]).toMatchObject({
      alt: 'Tiny PNG',
      widthPx: 120,
      heightPx: 80,
      crop: { left: 10, top: 10, right: 10, bottom: 10 },
    })
    expect(sem.footnotes).toEqual([{ id: 1, text: 'Footnote body text' }])
    expect(sem.bookmarks).toEqual([{ id: 'd5_target', name: 'd5_target' }])
    expect(sem.blockquotes).toBe(1)
    expect(sem.codeBlocks).toEqual([{ language: 'typescript', text: 'const answer = 42;' }])
    expect(sem.lists).toEqual([
      { kind: 'ordered', level: 0, start: 3, format: 'decimal', text: 'Level 0 item' },
      { kind: 'ordered', level: 1, start: 2, format: 'lowerLetter', text: 'Level 1 nested item' },
    ])
    expect(sem.tables[0].borders).toMatchObject({
      top: { style: 'single', sizeEighthPt: 8, colorHex: '112233' },
      insideH: { style: 'single', sizeEighthPt: 4, colorHex: 'AABBCC' },
    })
    expect(sem.tables[0].margins).toEqual({ topDxa: 100, rightDxa: 140, bottomDxa: 100, leftDxa: 140 })
    expect(sem.tables[0].cells[0].borders).toMatchObject({
      top: { style: 'double', sizeEighthPt: 6, colorHex: '445566' },
    })
    expect(sem.tables[0].cells[0].margins).toEqual({ topDxa: 80, rightDxa: 120, bottomDxa: 80, leftDxa: 120 })
    expect(sem.revisions.formattingRevisions).toBe(1)

    // On-disk d5-rich-layout.docx & d5-rich-layout.tiptap.json parity
    const diskDocx = readFileSync(resolve(__dirname, '../../../tests/corpus/docx/d5-rich-layout.docx'))
    const diskJson = JSON.parse(readFileSync(resolve(__dirname, '../../../tests/corpus/docx/d5-rich-layout.tiptap.json'), 'utf8'))
    const diskImport = await importDocx(diskDocx, { profile: 'revisions' })
    const jsonExport = await exportDocx(
      { document: diskJson, sourceMarker: CORPUS_D5.sourceMarker },
      { profile: 'revisions', projection: 'review', enableRevisions: true },
    )
    const jsonImport = await importDocx(jsonExport.binary!, { profile: 'revisions' })
    expect(compareSemanticReports(diskImport.semantic, jsonImport.semantic)).toEqual({
      equal: true,
      differences: [],
    })
  })

  it('D5 edge cases: endnotes, SVG images, VML <w:pict> images, removed rPrChange, and pPrChange roundtrip', async () => {
    const svgDataUri =
      'data:image/svg+xml;base64,' +
      Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20"><rect width="40" height="20" fill="#123456"/></svg>').toString('base64')

    const doc = {
      type: 'doc' as const,
      content: [
        {
          type: 'paragraph',
          attrs: {
            textAlign: 'center',
            formattingChange: {
              id: 'ppr-1',
              targetType: 'paragraph',
              author: 'Editor',
              date: '2026-11-24T10:00:00Z',
              summary: 'changed:textAlign',
              previousParagraphProps: { textAlign: 'left' },
            },
          },
          content: [
            {
              type: 'text',
              text: 'Endnote ref',
              marks: [
                {
                  type: 'suggestion',
                  attrs: {
                    id: 'rev-rem-bold',
                    type: 'markChange',
                    userId: 'editor-1',
                    createdAt: '2026-11-24T10:00:00Z',
                    userMetadata: { name: 'Editor' },
                    markChanges: [{ operation: 'removed', markName: 'bold', markAttrs: {} }],
                  },
                },
              ],
            },
            {
              type: 'endnote',
              attrs: { id: 1 },
              content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Endnote body' }] }],
            },
            {
              type: 'image',
              attrs: { src: svgDataUri, alt: 'Vector SVG', width: 40, height: 20 },
            },
          ],
        },
      ],
    }

    const strictBlocked = await exportDocx(doc, { profile: 'strict-ooxml', enableRevisions: true })
    expect(strictBlocked.blocked).toBe(true)
    expect(strictBlocked.report.diagnostics.some((d) => d.code === 'NATIVE_FORMATTING_REVISION_UNSUPPORTED')).toBe(true)

    const exp = await exportDocx(doc, { profile: 'revisions', projection: 'review', enableRevisions: true })
    expect(exp.blocked).toBe(false)
    const zip = unzipSync(exp.binary!)
    expect(zip['word/endnotes.xml']).toBeDefined()
    expect(decodeUtf8(zip['word/document.xml'])).toContain('<w:pPrChange')

    const imp = await importDocx(exp.binary!, { profile: 'revisions' })
    expect(imp.semantic.endnotes).toEqual([{ id: 1, text: 'Endnote body' }])
    expect(imp.semantic.images).toHaveLength(1)
    expect(imp.semantic.images[0].alt).toBe('Vector SVG')
    expect(imp.semantic.revisions.formattingRevisions).toBe(2)

    // VML <w:pict><v:imagedata> legacy image import
    const pngBytes = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64',
    )
    const vmlZip = zipSync({
      'word/document.xml': encodeUtf8(
        '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body><w:p><w:r><w:pict><v:shape style="width:60pt;height:30pt" alt="Legacy VML"><v:imagedata r:id="rIdVml"/></v:shape></w:pict></w:r></w:p></w:body></w:document>',
      ),
      'word/_rels/document.xml.rels': encodeUtf8(
        '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdVml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/vml.png"/></Relationships>',
      ),
      'word/media/vml.png': pngBytes,
    })
    const vmlImport = await importDocx(vmlZip, { profile: 'standard' })
    expect(vmlImport.blocked).toBe(false)
    expect(vmlImport.semantic.images).toHaveLength(1)
    expect(vmlImport.semantic.images[0]).toMatchObject({ alt: 'Legacy VML', widthPx: 80, heightPx: 40 })
  })
})
