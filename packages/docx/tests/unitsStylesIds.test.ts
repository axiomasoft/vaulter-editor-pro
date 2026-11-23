import { describe, expect, it } from 'vitest'
import {
  anchoredCommentsToDocxDiscussions,
  emuToPixels,
  isSafeHyperlinkUrl,
  isValidOoxmlHexId,
  lineHeightToOoxml,
  normalizeHexColor,
  OoxmlIdAllocator,
  ooxmlToLineHeight,
  parseOoxmlWidth,
  parseXmlPart,
  pixelsToEmu,
  pixelsToTwips,
  pointsToTwips,
  ptToEighthPoints,
  ptToHalfPoints,
  StyleRegistry,
  type AnchoredCommentLike,
} from '../src/node'

describe('OOXML Units, Style Cascade, ID Allocator & Anchor Mapping', () => {
  it('implements exact integer unit conversions and safe URL validation', () => {
    expect(pixelsToTwips(96)).toBe(1440)
    expect(pixelsToTwips(24)).toBe(360)
    expect(pointsToTwips(12)).toBe(240)
    expect(pixelsToEmu(10)).toBe(95250)
    expect(emuToPixels(95250)).toBe(10)
    expect(lineHeightToOoxml(1.5)).toEqual({ line: 360, lineRule: 'auto' })
    expect(ooxmlToLineHeight(240)).toEqual({ lineSpacing: 1, lineRule: 'auto' })
    expect(parseOoxmlWidth('100%')).toEqual({ unit: 'pct', value: 5000 })
    expect(parseOoxmlWidth('5000', 'pct')).toEqual({ unit: 'pct', value: 5000 })
    expect(parseOoxmlWidth('3600', 'dxa')).toEqual({ unit: 'dxa', value: 3600 })
    expect(ptToHalfPoints(12)).toBe(24)
    expect(ptToEighthPoints(0.5)).toBe(4)
    expect(ptToEighthPoints(20)).toBe(96)
    expect(ptToEighthPoints(0.1)).toBe(2)
    expect(normalizeHexColor('#ddeeff')).toBe('DDEEFF')
    expect(normalizeHexColor('rgb(10, 20, 30)')).toBe('0A141E')

    expect(isSafeHyperlinkUrl('https://vaulter.test/path')).toBe(true)
    expect(isSafeHyperlinkUrl('http://vaulter.test/path')).toBe(true)
    expect(isSafeHyperlinkUrl('mailto:review@vaulter.test')).toBe(true)
    expect(isSafeHyperlinkUrl('javascript:alert(1)')).toBe(false)
    expect(isSafeHyperlinkUrl('ms-msdt:/id%20PCWDiagnostic')).toBe(false)
    expect(isSafeHyperlinkUrl('file:///etc/passwd')).toBe(false)
    expect(isSafeHyperlinkUrl('\\\\evil-server\\share')).toBe(false)
  })

  it('resolves styles.xml cascade docDefaults -> tblStyle -> pStyle(basedOn <= 16) -> rStyle -> numPr -> inline while preserving null inheritance', () => {
    const stylesRoot = parseXmlPart(
      `<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
        <w:docDefaults>
          <w:rPrDefault><w:rPr><w:sz w:val="22"/><w:color w:val="111111"/></w:rPr></w:rPrDefault>
          <w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="240"/></w:pPr></w:pPrDefault>
        </w:docDefaults>
        <w:style w:type="table" w:styleId="TblGrid"><w:pPr><w:rPr><w:b/></w:rPr></w:pPr></w:style>
        <w:style w:type="paragraph" w:styleId="BaseP"><w:pPr><w:ind w:left="360"/><w:rPr><w:strike/></w:rPr></w:pPr></w:style>
        <w:style w:type="paragraph" w:styleId="ChildP"><w:basedOn w:val="BaseP"/><w:pPr><w:jc w:val="center"/></w:pPr></w:style>
        <w:style w:type="character" w:styleId="StrongChar"><w:rPr><w:i/></w:rPr></w:style>
      </w:styles>`,
      'word/styles.xml',
    )

    const registry = new StyleRegistry(stylesRoot)
    const resolvedP = registry.resolveParagraph({
      tblStyleId: 'TblGrid',
      pStyleId: 'ChildP',
      numPrProps: { indentRightDxa: 180, run: { underline: 'single' } },
      inlinePPr: { indentLeftDxa: null, spacingBeforeDxa: 0, run: { colorHex: '222222' } },
    })

    // null inline indentLeftDxa MUST inherit 360 from BaseP rather than becoming 0; explicit 0 overrides!
    expect(resolvedP.indentLeftDxa).toBe(360)
    expect(resolvedP.spacingBeforeDxa).toBe(0)
    expect(resolvedP.spacingAfterDxa).toBe(120)
    expect(resolvedP.textAlign).toBe('center')
    expect(resolvedP.indentRightDxa).toBe(180)
    expect(resolvedP.run).toMatchObject({ bold: true, strike: true, underline: 'single', colorHex: '222222' })

    const resolvedRun = registry.resolveRun({
      paragraphStyle: resolvedP,
      rStyleId: 'StrongChar',
      inlineRPr: { colorHex: 'AA0000' },
    })
    expect(resolvedRun.bold).toBe(true)
    expect(resolvedRun.italic).toBe(true)
    expect(resolvedRun.fontSizeHalfPt).toBe(22)
    expect(resolvedRun.colorHex).toBe('AA0000')
  })

  it('allocates w14:paraId and durableId strictly within 00000001..7FFFFFFF and repairs out-of-range inputs', () => {
    expect(isValidOoxmlHexId('00000000')).toBe(false)
    expect(isValidOoxmlHexId('80000000')).toBe(false)
    expect(isValidOoxmlHexId('FFFFFFFF')).toBe(false)
    expect(isValidOoxmlHexId('00000001')).toBe(true)
    expect(isValidOoxmlHexId('7FFFFFFF')).toBe(true)

    const allocator = new OoxmlIdAllocator()
    const p1 = allocator.allocateParaId('c1', '00000000')
    const p2 = allocator.allocateParaId('c2', '80000001')
    const p3 = allocator.allocateParaId('c3', '1234ABCD')
    const p4 = allocator.allocateParaId('c4', '1234ABCD') // collision avoided

    for (const id of [p1, p2, p3, p4]) {
      expect(isValidOoxmlHexId(id)).toBe(true)
    }
    expect(p3).toBe('1234ABCD')
    expect(p4).not.toBe('1234ABCD')
    expect(new Set([p1, p2, p3, p4]).size).toBe(4)

    const d1 = allocator.allocateDurableId('c1', 'FFFFFFFF')
    expect(isValidOoxmlHexId(d1)).toBe(true)
  })

  it('adapts Vaulter 8.1 AnchoredComment threads including detached anchors and replies', () => {
    const anchored: AnchoredCommentLike[] = [
      {
        id: 'c1',
        parent_id: null,
        author: { type: 'user', id: '1' },
        body: 'Root feedback',
        anchor: {
          generation: 1,
          source_position: 4,
          block_id: 'blk_1',
          selector_kind: 'snapshot_utf16',
          selector_payload: { schema: 1, block_id: 'blk_1', from_utf16: 4, to_utf16: 9 },
          quote_text: '15 US',
          anchor_state: 'attached',
        },
        resolved_at: '2026-11-23T01:00:00Z',
        created_at: '2026-11-23T00:00:00Z',
      },
      {
        id: 'c2',
        parent_id: 'c1',
        author: { type: 'user', id: '2' },
        body: 'Resolved reply',
        anchor: null,
        resolved_at: null,
        created_at: '2026-11-23T00:05:00Z',
      },
    ]

    const converted = anchoredCommentsToDocxDiscussions(anchored)
    expect(converted).toHaveLength(1)
    expect(converted[0].resolved).toBe(true)
    expect(converted[0].anchorState).toBe('attached')
    expect(converted[0].comments).toHaveLength(2)
    expect(converted[0].comments[1].parentId).toBe('c1')
  })
})
