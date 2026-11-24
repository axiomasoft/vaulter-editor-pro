import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { crc32 } from 'node:zlib'
import { expect, test } from '@playwright/test'
import { zipSync } from 'fflate'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const corpusDir = path.resolve(__dirname, '../../corpus/docx')

function buildSymlinkZip() {
  const name = 'word/document.xml'
  const nameBytes = new TextEncoder().encode(name)
  const content = new TextEncoder().encode('target')
  const crc = crc32(content) >>> 0

  const lfh = new Uint8Array(30 + nameBytes.length)
  const lv = new DataView(lfh.buffer)
  lv.setUint32(0, 0x04034b50, true)
  lv.setUint16(4, 20, true)
  lv.setUint32(14, crc, true)
  lv.setUint32(18, content.length, true)
  lv.setUint32(22, content.length, true)
  lv.setUint16(26, nameBytes.length, true)
  lfh.set(nameBytes, 30)

  const cdh = new Uint8Array(46 + nameBytes.length)
  const cv = new DataView(cdh.buffer)
  cv.setUint32(0, 0x02014b50, true)
  cv.setUint16(4, (3 << 8) | 20, true) // UNIX creator
  cv.setUint16(6, 20, true)
  cv.setUint32(16, crc, true)
  cv.setUint32(20, content.length, true)
  cv.setUint32(24, content.length, true)
  cv.setUint16(28, nameBytes.length, true)
  cv.setUint32(38, (0o120777 << 16) >>> 0, true) // S_IFLNK
  cv.setUint32(42, 0, true)
  cdh.set(nameBytes, 46)

  const cdOffset = lfh.length + content.length
  const eocd = new Uint8Array(22)
  const ev = new DataView(eocd.buffer)
  ev.setUint32(0, 0x06054b50, true)
  ev.setUint16(8, 1, true)
  ev.setUint16(10, 1, true)
  ev.setUint32(12, cdh.length, true)
  ev.setUint32(16, cdOffset, true)

  const out = new Uint8Array(cdOffset + cdh.length + eocd.length)
  out.set(lfh, 0)
  out.set(content, lfh.length)
  out.set(cdh, cdOffset)
  out.set(eocd, cdOffset + cdh.length)
  return Buffer.from(out)
}

test.describe('DOCX', () => {
  test('offline local DOCX export in browser with zero network requests + server D1-D5 roundtrip', async ({ page }) => {
    const baseUrl = process.env.E2E_BASE_URL
    const setupRes = await fetch(`${baseUrl}/e2e/setup`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: 'DOCX E2E' }),
    })
    expect(setupRes.ok).toBe(true)
    const { node_id: nodeId } = await setupRes.json()

    await page.goto(`${baseUrl}/?mode=docx&node=${nodeId}&user=1`)

    // Wait for local export preview & Worker readiness
    const localCard = page.locator('.vaulter-local-docx-export')
    await expect(localCard).toHaveAttribute('data-export-blocked', 'false')

    // Block all network requests and verify local export succeeds offline
    const networkHits = []
    await page.route('**/*', (route) => {
      networkHits.push(route.request().url())
      return route.abort()
    })

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      localCard.locator('[data-action="export-docx"]').click(),
    ])
    const downloadPath = await download.path()
    expect(downloadPath).toBeTruthy()
    expect(fs.statSync(downloadPath).size).toBeGreaterThan(1000)
    expect(networkHits).toEqual([])
    await expect(page.locator('[data-testid="local-export-status"]')).toHaveText('exported')

    // Unblock network for server-side Exchange roundtrips
    await page.unroute('**/*')

    const exchangeCard = page.locator('.vaulter-docx-exchange')
    await exchangeCard.locator('[data-action="start-export"]').click()
    await expect(exchangeCard).toHaveAttribute('data-job-state', 'completed')

    const corpusCases = [
      { file: 'd1-body-table.docx', profile: 'standard' },
      { file: 'd2-sections.docx', profile: 'standard' },
      { file: 'd3-discussions.docx', profile: 'discussions' },
      { file: 'd4-revisions.docx', profile: 'revisions' },
      { file: 'd5-rich-layout.docx', profile: 'revisions' },
    ]

    for (const item of corpusCases) {
      await exchangeCard.locator('[data-control="profile"]').selectOption(item.profile)
      await exchangeCard.locator('[data-control="file"]').setInputFiles(path.join(corpusDir, item.file))
      await exchangeCard.locator('[data-action="upload-import"]').click()
      await expect(exchangeCard).toHaveAttribute('data-job-state', 'preview_ready')
      await exchangeCard.locator('[data-control="confirm-apply"]').check()
      await exchangeCard.locator('[data-action="apply-import"]').click()
      await expect(exchangeCard).toHaveAttribute('data-job-state', 'applied')
    }
  })
})

test.describe('FILES', () => {
  test('rejects zip-bomb, S_IFLNK symlink, and malformed XML on upload', async ({ page }) => {
    const baseUrl = process.env.E2E_BASE_URL
    const setupRes = await fetch(`${baseUrl}/e2e/setup`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: 'FILES Security E2E' }),
    })
    expect(setupRes.ok).toBe(true)
    const { node_id: nodeId } = await setupRes.json()

    await page.goto(`${baseUrl}/?mode=files&node=${nodeId}&user=1`)
    const exchangeCard = page.locator('.vaulter-docx-exchange')

    // 1. Zip-bomb (6 MiB zeros compressed -> ratio > 100)
    const zipBombBytes = Buffer.from(
      zipSync({
        '[Content_Types].xml': new TextEncoder().encode('<Types/>'),
        'word/document.xml': new Uint8Array(6 * 1024 * 1024),
      }),
    )
    await exchangeCard.locator('[data-control="file"]').setInputFiles({
      name: 'zip-bomb.docx',
      mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      buffer: zipBombBytes,
    })
    await exchangeCard.locator('[data-action="upload-import"]').click()
    await expect(page.locator('[data-testid="exchange-error-code"], [data-status="error-code"]').first()).toContainText('exchange_zip_limit_exceeded')

    // 2. UNIX S_IFLNK symlink entry
    await exchangeCard.locator('[data-control="file"]').setInputFiles({
      name: 'symlink.docx',
      mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      buffer: buildSymlinkZip(),
    })
    await exchangeCard.locator('[data-action="upload-import"]').click()
    await expect(page.locator('[data-testid="exchange-error-code"], [data-status="error-code"]').first()).toContainText('exchange_unsafe_archive')

    // 3. Malformed XML
    const malformedBytes = Buffer.from(
      zipSync({
        '[Content_Types].xml': new TextEncoder().encode('<Types/>'),
        '_rels/.rels': new TextEncoder().encode('<Relationships/>'),
        'word/document.xml': new TextEncoder().encode('<w:document><w:body><w:p>unclosed'),
      }),
    )
    await exchangeCard.locator('[data-control="file"]').setInputFiles({
      name: 'malformed.docx',
      mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      buffer: malformedBytes,
    })
    await exchangeCard.locator('[data-action="upload-import"]').click()
    await expect(page.locator('[data-testid="exchange-error-code"], [data-status="error-code"]').first()).toContainText('exchange_unsafe_archive')
  })
})
