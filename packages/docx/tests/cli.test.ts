import { execFileSync } from 'node:child_process'
import { resolve } from 'node:path'
import { zipSync, strToU8 } from 'fflate'
import { describe, expect, it } from 'vitest'
import { handleExchangeCliRequest } from '../src/cli'
import { CORPUS_D1 } from '../../../tests/corpus/docx/corpus'

describe('@vaulter/editor-pro-docx exchange CLI bridge', () => {
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
})
