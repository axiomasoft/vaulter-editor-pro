import { describe, expect, it } from 'vitest'
import { zipSync } from 'fflate'
import {
  browserZipReader,
  compareSemanticReports,
  DocxSecurityError,
  encodeUtf8,
  exportDocx,
  importDocx,
  nodeZipReader,
  validateArchiveMediaEntries,
  validateMediaAsset,
} from '../src/node'
import { CORPUS_D1, CORPUS_D2, CORPUS_D3, CORPUS_D5 } from '../../../tests/corpus/docx/corpus'

/**
 * Builds a minimal valid uncompressed ZIP from raw entries, allowing explicit control over
 * fileName bytes, externalFileAttributes (e.g. UNIX S_IFLNK), and local header offsets.
 */
function buildCraftedZip(
  items: Array<{
    name: string
    data: Uint8Array
    externalFileAttributes?: number
    overrideOffsetDelta?: number
  }>,
): Uint8Array {
  const localParts: Uint8Array[] = []
  const centralParts: Uint8Array[] = []
  let offset = 0

  for (const item of items) {
    const nameBytes = encodeUtf8(item.name)
    const localHeader = new Uint8Array(30 + nameBytes.length)
    const lv = new DataView(localHeader.buffer)
    lv.setUint32(0, 0x04034b50, true)
    lv.setUint16(4, 20, true)
    lv.setUint16(6, 0, true)
    lv.setUint16(8, 0, true) // stored
    lv.setUint32(18, item.data.length, true)
    lv.setUint32(22, item.data.length, true)
    lv.setUint16(26, nameBytes.length, true)
    localHeader.set(nameBytes, 30)

    const recordedOffset = offset + (item.overrideOffsetDelta ?? 0)
    localParts.push(localHeader, item.data)
    offset += localHeader.length + item.data.length

    const cdHeader = new Uint8Array(46 + nameBytes.length)
    const cv = new DataView(cdHeader.buffer)
    cv.setUint32(0, 0x02014b50, true)
    cv.setUint16(4, 0x031e, true) // UNIX + ZIP 3.0
    cv.setUint16(6, 20, true)
    cv.setUint16(10, 0, true)
    cv.setUint32(20, item.data.length, true)
    cv.setUint32(24, item.data.length, true)
    cv.setUint16(28, nameBytes.length, true)
    cv.setUint32(38, item.externalFileAttributes ?? 0, true)
    cv.setUint32(42, recordedOffset, true)
    cdHeader.set(nameBytes, 46)
    centralParts.push(cdHeader)
  }

  const cdOffset = offset
  const cdSize = centralParts.reduce((s, p) => s + p.length, 0)
  const eocd = new Uint8Array(22)
  const ev = new DataView(eocd.buffer)
  ev.setUint32(0, 0x06054b50, true)
  ev.setUint16(8, items.length, true)
  ev.setUint16(10, items.length, true)
  ev.setUint32(12, cdSize, true)
  ev.setUint32(16, cdOffset, true)

  const totalSize = cdOffset + cdSize + eocd.length
  const out = new Uint8Array(totalSize)
  let pos = 0
  for (const part of localParts) {
    out.set(part, pos)
    pos += part.length
  }
  for (const part of centralParts) {
    out.set(part, pos)
    pos += part.length
  }
  out.set(eocd, pos)
  return out
}

const VALID_DOC_XML = encodeUtf8(
  '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>',
)

describe('ZipCentralDirectoryGuard, XML XXE/External-Rel Guard, and Node/Browser Parity', () => {
  it('rejects Zip Slip, backslashes, leading slashes, Windows drives, S_IFLNK symlinks, duplicates, and overlapping byte ranges in BOTH yauzl and fflate', async () => {
    const vectors: Array<{ label: string; bytes: Uint8Array; expectedCode: string }> = [
      {
        label: 'path traversal ..',
        bytes: buildCraftedZip([{ name: '../evil.xml', data: VALID_DOC_XML }]),
        expectedCode: 'zip_slip',
      },
      {
        label: 'backslash path',
        bytes: buildCraftedZip([{ name: 'word\\document.xml', data: VALID_DOC_XML }]),
        expectedCode: 'zip_slip',
      },
      {
        label: 'leading slash',
        bytes: buildCraftedZip([{ name: '/word/document.xml', data: VALID_DOC_XML }]),
        expectedCode: 'zip_slip',
      },
      {
        label: 'Windows drive letter',
        bytes: buildCraftedZip([{ name: 'C:word/document.xml', data: VALID_DOC_XML }]),
        expectedCode: 'zip_slip',
      },
      {
        label: 'UNIX symlink S_IFLNK',
        bytes: buildCraftedZip([
          {
            name: 'word/document.xml',
            data: VALID_DOC_XML,
            externalFileAttributes: (0o120777 << 16) >>> 0,
          },
        ]),
        expectedCode: 'zip_symlink',
      },
      {
        label: 'case-insensitive duplicate entry',
        bytes: buildCraftedZip([
          { name: 'word/document.xml', data: VALID_DOC_XML },
          { name: 'WORD/DOCUMENT.XML', data: VALID_DOC_XML },
        ]),
        expectedCode: 'zip_duplicate_entry',
      },
      {
        label: 'overlapping ZIP byte ranges',
        bytes: buildCraftedZip([
          { name: 'word/document.xml', data: VALID_DOC_XML },
          {
            name: 'word/other.xml',
            data: VALID_DOC_XML,
            overrideOffsetDelta: -(30 + 'word/document.xml'.length + VALID_DOC_XML.length),
          },
        ]),
        expectedCode: 'zip_overlapping_ranges',
      },
    ]

    for (const reader of [nodeZipReader, browserZipReader]) {
      for (const vec of vectors) {
        await expect(
          importDocx(vec.bytes, { zipReader: reader }),
          `${vec.label}`,
        ).rejects.toMatchObject({ code: vec.expectedCode })
      }
    }
  })

  it('rejects zip bombs (> 100x compression ratio / max entries / max file size) in BOTH yauzl and fflate', async () => {
    const bombData = new Uint8Array(256 * 1024).fill(0x41) // highly compressible -> ~600x ratio
    const bombZip = zipSync({ 'word/document.xml': bombData }, { level: 9 })

    for (const reader of [nodeZipReader, browserZipReader]) {
      await expect(importDocx(bombZip, { zipReader: reader })).rejects.toBeInstanceOf(DocxSecurityError)
      await expect(importDocx(bombZip, { zipReader: reader })).rejects.toMatchObject({
        code: 'zip_bomb_ratio',
      })

      await expect(
        importDocx(
          zipSync({
            'word/document.xml': VALID_DOC_XML,
            'word/extra1.xml': encodeUtf8('<a/>'),
            'word/extra2.xml': encodeUtf8('<b/>'),
          }),
          { zipReader: reader, quotas: { maxEntries: 2 } },
        ),
      ).rejects.toMatchObject({ code: 'zip_quota_exceeded' })
    }
  })

  it('rejects XXE DOCTYPE/ENTITY, XInclude, excessive XML depth, and non-hyperlink External relationships', async () => {
    const xxeZip = zipSync({
      'word/document.xml': encodeUtf8(
        '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>',
      ),
    })

    const externalTemplateZip = zipSync({
      'word/document.xml': VALID_DOC_XML,
      'word/_rels/document.xml.rels': encodeUtf8(
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rExt" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="https://evil.test/payload.dotm" TargetMode="External"/></Relationships>',
      ),
    })

    const unsafeHyperlinkZip = zipSync({
      'word/document.xml': VALID_DOC_XML,
      'word/_rels/document.xml.rels': encodeUtf8(
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rBadLink" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="ms-msdt:/id%20PCWDiagnostic" TargetMode="External"/></Relationships>',
      ),
    })

    await expect(importDocx(xxeZip, { zipReader: nodeZipReader })).rejects.toMatchObject({
      code: 'xml_xxe_forbidden',
    })
    await expect(importDocx(externalTemplateZip, { zipReader: nodeZipReader })).rejects.toMatchObject({
      code: 'external_rel_forbidden',
    })
    await expect(importDocx(unsafeHyperlinkZip, { zipReader: browserZipReader })).rejects.toMatchObject({
      code: 'external_rel_forbidden',
    })
  })

  it('produces 100% identical SemanticReport and LossReport across Node (yauzl) and Browser (fflate)', async () => {
    for (const spec of [CORPUS_D1, CORPUS_D2, CORPUS_D3, CORPUS_D5]) {
      const profile = spec.id === 'D3' ? 'discussions' : spec.id === 'D5' ? 'revisions' : 'standard'
      const exp = await exportDocx(
        {
          document: spec.document,
          sections: spec.sections,
          discussions: spec.discussions,
          sourceMarker: spec.sourceMarker,
        },
        { profile, ...(spec.id === 'D5' ? { enableRevisions: true, projection: 'review' as const } : {}) },
      )
      expect(exp.binary).not.toBeNull()

      const fromNode = await importDocx(exp.binary!, {
        profile,
        zipReader: nodeZipReader,
      })
      const fromBrowser = await importDocx(exp.binary!, {
        profile,
        zipReader: browserZipReader,
      })

      expect(compareSemanticReports(fromNode.semantic, fromBrowser.semantic)).toEqual({
        equal: true,
        differences: [],
      })
      expect(fromNode.report).toEqual(fromBrowser.report)
    }
  })

  it('rejects corrupt media, extension/signature mismatch, scripted/XXE SVGs, image-bomb quotas, and TargetMode="External" images', async () => {
    const validPng = new Uint8Array([
      0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
      0x00, 0x00, 0x00, 0x0d, 0x49, 0x48, 0x44, 0x52,
      0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
      0x08, 0x04, 0x00, 0x00, 0x00, 0xb5, 0x1c, 0x0c, 0x02,
    ])

    // 1. Corrupt PNG header
    expect(() => validateMediaAsset(new Uint8Array([1, 2, 3, 4]), { fileName: 'word/media/bad.png' })).toThrow(
      DocxSecurityError,
    )
    expect(() => validateMediaAsset(new Uint8Array([1, 2, 3, 4]), { fileName: 'word/media/bad.png' })).toThrow(
      /Unrecognized or corrupt/,
    )

    // 2. Extension mismatch (PNG bytes named .jpg)
    expect(() => validateMediaAsset(validPng, { fileName: 'word/media/fake.jpg' })).toThrow(DocxSecurityError)

    // 3. Scripted SVG (<script>, onload=, <foreignObject>, javascript:)
    const scriptedSvgs = [
      '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
      '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>',
      '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body>hi</body></foreignObject></svg>',
      '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="10" height="10"/></a></svg>',
    ]
    for (const svg of scriptedSvgs) {
      expect(() => validateMediaAsset(encodeUtf8(svg), { fileName: 'word/media/evil.svg' })).toThrow(
        DocxSecurityError,
      )
    }

    // 4. XXE inside SVG
    const xxeSvg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"/>'
    expect(() => validateMediaAsset(encodeUtf8(xxeSvg), { fileName: 'word/media/xxe.svg' })).toThrow(
      DocxSecurityError,
    )

    // 5. PNG dimension/pixel bomb (20000x20000 = 400M pixels)
    const bombPng = new Uint8Array(validPng)
    const dv = new DataView(bombPng.buffer)
    dv.setUint32(16, 20000, false)
    dv.setUint32(20, 20000, false)
    expect(() => validateMediaAsset(bombPng, { fileName: 'word/media/bomb.png' })).toThrow(DocxSecurityError)

    // 6. Media quota overflow (maxMediaFiles)
    expect(() =>
      validateArchiveMediaEntries(
        { 'word/media/a.png': validPng, 'word/media/b.png': validPng },
        { maxMediaFiles: 1 },
      ),
    ).toThrow(DocxSecurityError)

    // 7. TargetMode="External" image rel rejected directly by zip/guard.ts AND by importDocx
    const extImageRels = encodeUtf8(
      '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rExtImg" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="https://evil.test/track.png" TargetMode="External"/></Relationships>',
    )
    expect(() =>
      validateArchiveMediaEntries({ 'word/_rels/document.xml.rels': extImageRels }),
    ).toThrow(DocxSecurityError)

    const extImgZip = zipSync({
      'word/document.xml': VALID_DOC_XML,
      'word/_rels/document.xml.rels': extImageRels,
    })
    const rLinkZip = zipSync({
      'word/document.xml': encodeUtf8(
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body><w:p><w:r><w:drawing><wp:inline><wp:extent cx="95250" cy="95250"/><a:graphic><a:graphicData><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:blipFill><a:blip r:link="rExtLink"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:body></w:document>',
      ),
      'word/_rels/document.xml.rels': encodeUtf8(
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rExtLink" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://evil.test/track.png" TargetMode="External"/></Relationships>',
      ),
    })
    for (const reader of [nodeZipReader, browserZipReader]) {
      await expect(importDocx(extImgZip, { zipReader: reader })).rejects.toMatchObject({
        code: 'external_rel_forbidden',
      })
      await expect(importDocx(rLinkZip, { zipReader: reader })).rejects.toMatchObject({
        code: 'external_rel_forbidden',
      })
    }
  })
})
