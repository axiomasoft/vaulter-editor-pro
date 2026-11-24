import { execFileSync, spawn } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { Packer } from 'docx'
import { strFromU8, unzipSync, zipSync, strToU8 } from 'fflate'
import { describe, expect, it, vi } from 'vitest'
import { exportDocx } from '../src/node'
import { handleExchangeCliRequest } from '../src/cli'
import { CORPUS_D1, CORPUS_D4 } from '../../../tests/corpus/docx/corpus'

describe('@vaulter/editor-pro-docx exchange CLI bridge', () => {
  it.each(['revisions', 'strict-ooxml'])('uses the %s profile revision default and honors an explicit opt-out', async (profile_id) => {
    const request = {
      version: 1,
      request_id: 'cli-revisions',
      operation: 'export',
      profile_id,
      document: { ...CORPUS_D4.document, content: CORPUS_D4.document.content?.slice(0, 1) },
    }
    const documentXml = async (options?: { enableRevisions: boolean }) => {
      const response = JSON.parse(await handleExchangeCliRequest(JSON.stringify({ ...request, options })))
      expect(response).toMatchObject({ ok: true, blocked: false })
      return strFromU8(unzipSync(Buffer.from(response.docx_b64, 'base64'))['word/document.xml'])
    }

    expect(await documentXml()).toMatch(/<w:ins\b/)
    expect(await documentXml()).toMatch(/<w:del\b/)
    expect(await documentXml({ enableRevisions: false })).not.toMatch(/<w:(?:ins|del)\b/)
  })

  it.each([
    ['d2-sections.docx', 'sections', 2],
    ['d3-discussions.docx', 'discussions', 3],
  ])('retains full %s metadata on import without body_only warnings', async (filename, field, expectedCount) => {
    const payload = readFileSync(resolve(__dirname, '../../../tests/corpus/docx', filename))
    const response = JSON.parse(await handleExchangeCliRequest(JSON.stringify({
      version: 1,
      request_id: 'cli-import-metadata',
      operation: 'import',
      profile_id: 'discussions',
      options: { stripSectionAndReviewMetadata: true },
      docx_b64: payload.toString('base64'),
    })))

    expect(response).toMatchObject({ ok: true, blocked: false })
    expect(response[field]).toHaveLength(expectedCount)
    expect(response.report.diagnostics.some((d: { code: string }) => d.code === 'body_only_sections' || d.code === 'body_only_discussions')).toBe(false)
  })

  it('extracts D4 tracked changes against clean baseline and re-exports original/proposed/review projections', async () => {
    const payload = readFileSync(resolve(__dirname, '../../../tests/corpus/docx/d4-revisions.docx'))
    const impRes = JSON.parse(await handleExchangeCliRequest(JSON.stringify({
      version: 1,
      request_id: 'cli-d4-imp',
      operation: 'import',
      profile_id: 'revisions',
      options: { stripSectionAndReviewMetadata: true },
      docx_b64: payload.toString('base64'),
    })))

    expect(impRes).toMatchObject({ ok: true, blocked: false })
    expect(impRes.revisions).toHaveLength(5)
    expect(JSON.stringify(impRes.document)).not.toContain('added clause')
    expect(JSON.stringify(impRes.document)).not.toContain('"suggestion"')

    const blockIds = ['blk_0', 'blk_1']
    const docWithIds = {
      ...impRes.document,
      content: impRes.document.content.map((n: Record<string, unknown>, idx: number) => ({
        ...n,
        attrs: { ...(n.attrs as Record<string, unknown> | undefined), blockId: blockIds[idx] },
      })),
    }
    const revisions = impRes.revisions.map((r: Record<string, unknown>) => ({
      ...r,
      target_block_id: blockIds[r.block_index as number],
      ...(typeof r.second_block_index === 'number'
        ? { payload: { ...(r.payload as Record<string, unknown> | undefined), second_block_id: blockIds[r.second_block_index] } }
        : {}),
    }))

    const reviewExp = JSON.parse(await handleExchangeCliRequest(JSON.stringify({
      version: 1,
      request_id: 'cli-d4-review',
      operation: 'export',
      profile_id: 'revisions',
      projection: 'review',
      document: docWithIds,
      revisions,
    })))
    expect(reviewExp).toMatchObject({ ok: true, blocked: false })
    const docXml = strFromU8(unzipSync(Buffer.from(reviewExp.docx_b64, 'base64'))['word/document.xml'])
    expect(docXml).toMatch(/<w:ins\b/)
    expect(docXml).toMatch(/<w:del\b/)
    expect(docXml).toMatch(/<w:rPrChange\b/)
  })

  it('roundtrips export -> import via handleExchangeCliRequest preserving D1 table merges and links', async () => {
    const exportReq = JSON.stringify({
      version: 1,
      request_id: 'cli-exp-1',
      operation: 'export',
      profile_id: 'standard',
      document: CORPUS_D1.document,
      source_marker: CORPUS_D1.sourceMarker,
    })

    const exportRes = JSON.parse(await handleExchangeCliRequest(exportReq)) as {
      ok: boolean
      docx_b64: string
      docx_sha256: string
      blocked: boolean
      report: { profile: string }
    }
    expect(exportRes.ok).toBe(true)
    expect(exportRes.blocked).toBe(false)
    expect(exportRes.docx_sha256).toMatch(/^[0-9a-f]{64}$/)
    expect(exportRes.report.profile).toBe('standard')

    const importReq = JSON.stringify({
      version: 1,
      request_id: 'cli-imp-1',
      operation: 'import',
      profile_id: 'standard',
      docx_b64: exportRes.docx_b64,
    })

    const importRes = JSON.parse(await handleExchangeCliRequest(importReq)) as {
      ok: boolean
      blocked: boolean
      document: { type: string; content: unknown[] }
      report: { source_marker: typeof CORPUS_D1.sourceMarker }
    }
    expect(importRes.ok).toBe(true)
    expect(importRes.blocked).toBe(false)
    expect(importRes.document.type).toBe('doc')
    expect(importRes.document.content.length).toBeGreaterThan(0)
    expect(importRes.report.source_marker).toEqual(CORPUS_D1.sourceMarker)
  })

  it('rejects traversal and XXE payloads safely when invoked as built Node CLI subprocess', () => {
    const cliDist = resolve(__dirname, '../dist/cli.js')
    const xxeZip = zipSync({
      '[Content_Types].xml': strToU8('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'),
      'word/document.xml': strToU8('<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY x SYSTEM "file:///etc/passwd">]><w:document/>'),
    })

    const stdout = execFileSync(process.execPath, [cliDist], {
      input: JSON.stringify({
        version: 1,
        request_id: 'cli-sec-1',
        operation: 'import',
        profile_id: 'discussions',
        docx_b64: Buffer.from(xxeZip).toString('base64'),
      }),
      encoding: 'utf8',
    })

    const parsed = JSON.parse(stdout.trim()) as {
      ok: boolean
      request_id: string
      error: { code: string }
    }
    expect(parsed.request_id).toBe('cli-sec-1')
    expect(parsed.ok).toBe(false)
    expect(parsed.error.code).toBe('xml_xxe_forbidden')
  })

  it('compresses ZIP once without Packer.toArrayBuffer roundtrip during exportDocx', async () => {
    const spy = vi.spyOn(Packer, 'toArrayBuffer')
    const exported = await exportDocx(
      { document: CORPUS_D1.document, sourceMarker: CORPUS_D1.sourceMarker },
      { profile: 'standard' },
    )
    expect(exported.blocked).toBe(false)
    expect(exported.binary).not.toBeNull()
    expect(spy).not.toHaveBeenCalled()
    spy.mockRestore()
  })

  it('supports docx_path / output_path without base64 and handles multiple requests in --persistent mode', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'vedx-cli-test-'))
    try {
      const outPath = join(dir, 'out.docx')
      const expRes = JSON.parse(await handleExchangeCliRequest(JSON.stringify({
        version: 1,
        request_id: 'cli-path-exp',
        operation: 'export',
        profile_id: 'standard',
        output_path: outPath,
        document: CORPUS_D1.document,
      }))) as { ok: boolean; docx_path?: string; docx_b64?: string; docx_sha256?: string }
      expect(expRes.ok).toBe(true)
      expect(expRes.docx_path).toBe(outPath)
      expect(expRes.docx_b64).toBeUndefined()
      expect(expRes.docx_sha256).toMatch(/^[0-9a-f]{64}$/)

      const impRes = JSON.parse(await handleExchangeCliRequest(JSON.stringify({
        version: 1,
        request_id: 'cli-path-imp',
        operation: 'import',
        profile_id: 'standard',
        docx_path: outPath,
      }))) as { ok: boolean; source_sha256?: string; document?: { type: string } }
      expect(impRes.ok).toBe(true)
      expect(impRes.source_sha256).toBe(expRes.docx_sha256)
      expect(impRes.document?.type).toBe('doc')

      const cliDist = resolve(__dirname, '../dist/cli.js')
      const inPath = join(dir, 'in.docx')
      writeFileSync(inPath, readFileSync(outPath))
      const child = spawn(process.execPath, [cliDist, '--persistent'], { stdio: ['pipe', 'pipe', 'pipe'] })
      const lines: string[] = []
      let buf = ''
      const done = new Promise<void>((resolveDone, rejectDone) => {
        child.stdout.on('data', (chunk: Buffer) => {
          buf += chunk.toString('utf8')
          let idx = buf.indexOf('\n')
          while (idx !== -1) {
            const line = buf.slice(0, idx).trim()
            buf = buf.slice(idx + 1)
            if (line) lines.push(line)
            idx = buf.indexOf('\n')
          }
          if (lines.length === 2) {
            child.stdin.end()
          }
        })
        child.on('error', rejectDone)
        child.on('close', (code) => {
          if (code === 0) resolveDone()
          else rejectDone(new Error(`Persistent CLI exited with code ${code}`))
        })
      })
      child.stdin.write(`${JSON.stringify({ version: 1, request_id: 'p-1', operation: 'import', profile_id: 'standard', docx_path: inPath })}\n`)
      child.stdin.write(`${JSON.stringify({ version: 1, request_id: 'p-2', operation: 'import', profile_id: 'standard', docx_path: inPath })}\n`)
      await done
      expect(lines).toHaveLength(2)
      expect(JSON.parse(lines[0])).toMatchObject({ ok: true, request_id: 'p-1' })
      expect(JSON.parse(lines[1])).toMatchObject({ ok: true, request_id: 'p-2' })
    } finally {
      rmSync(dir, { recursive: true, force: true })
    }
  })
})
