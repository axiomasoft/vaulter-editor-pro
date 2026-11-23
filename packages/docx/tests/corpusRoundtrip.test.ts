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
} from '../../../tests/corpus/docx/corpus'

describe('DOCX Corpus D1–D4 Roundtrip & Loss Report (10.1-A)', () => {
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
})
