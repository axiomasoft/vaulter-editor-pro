import { createHash } from 'node:crypto'
import { lstat, readFile, writeFile } from 'node:fs/promises'
import { exportDocx, importDocx, nodeZipReader, setDefaultZipReader } from './node'
import { DocxProfileBlockedError, LossReportBuilder } from './model/lossReport'
import { DOCX_PROFILE_IDS } from './model/profiles'
import type {
  DocxDiscussionInput,
  DocxProfileId,
  DocxProjectionMode,
  DocxSectionInput,
  LossReport,
  SourceMarker,
  TipTapDoc,
} from './model/types'
import {
  decorateDocumentForExport,
  extractCleanImportPayload,
  fromIntermediate,
  type ExportSuggestionInput,
} from './tiptap/adapter'
import { DocxSecurityError, type ZipQuotas } from './zip/guard'

setDefaultZipReader(nodeZipReader)

const DEFAULT_MAX_STDIN_BYTES = 128 * 1024 * 1024

export interface DocxCliRequest {
  version: 1
  request_id: string
  operation: 'import' | 'export'
  profile_id?: string
  projection?: DocxProjectionMode
  docx_b64?: string
  docx_path?: string
  output_path?: string
  document?: TipTapDoc
  sections?: DocxSectionInput[]
  discussions?: DocxDiscussionInput[]
  revisions?: ExportSuggestionInput[]
  title?: string | null
  creator?: string | null
  source_marker?: SourceMarker | null
  quotas?: ZipQuotas
  options?: {
    enableDiscussions?: boolean
    enableRevisions?: boolean
    requireFormattingRevisions?: boolean
    preserveSectionNodes?: boolean
    stripSectionAndReviewMetadata?: boolean
    bodyOnly?: boolean
  }
}

export interface DocxCliErrorResponse {
  version: 1
  request_id: string
  ok: false
  error: {
    code: string
    message: string
    report?: LossReport
  }
}

export interface DocxCliSuccessResponse {
  version: 1
  request_id: string
  ok: true
  operation: 'import' | 'export'
  profile: DocxProfileId
  blocked: boolean
  source_sha256?: string
  docx_b64?: string
  docx_path?: string
  docx_sha256?: string
  document?: TipTapDoc
  sections?: Array<Record<string, unknown>>
  discussions?: Array<Record<string, unknown>>
  revisions?: Array<Record<string, unknown>>
  sections_count?: number
  discussions_count?: number
  report: LossReport
  semantic: unknown
}

function sha256Hex(bytes: Uint8Array): string {
  return createHash('sha256').update(bytes).digest('hex')
}

function resolveProfile(raw: unknown): DocxProfileId {
  if (typeof raw === 'string' && (DOCX_PROFILE_IDS as readonly string[]).includes(raw)) {
    return raw as DocxProfileId
  }
  throw new DocxCliInputError('invalid_profile', `Unsupported DOCX profile "${String(raw)}".`)
}

class DocxCliInputError extends Error {
  readonly code: string

  constructor(code: string, message: string) {
    super(message)
    this.code = code
  }
}

function errorPayload(
  requestId: string,
  code: string,
  message: string,
  report?: LossReport,
): DocxCliErrorResponse {
  return {
    version: 1,
    request_id: requestId,
    ok: false,
    error: {
      code,
      message,
      ...(report ? { report } : {}),
    },
  }
}

export async function handleExchangeCliRequest(rawJson: string): Promise<string> {
  let reqId = ''
  try {
    const parsed = JSON.parse(rawJson) as Partial<DocxCliRequest>
    if (!parsed || typeof parsed !== 'object' || parsed.version !== 1 || typeof parsed.request_id !== 'string') {
      throw new DocxCliInputError('invalid_request', 'Invalid exchange CLI request envelope.')
    }
    reqId = parsed.request_id
    const profile = resolveProfile(parsed.profile_id ?? 'discussions')
    const opts = parsed.options ?? {}

    if (parsed.operation === 'import') {
      let bytes: Buffer
      if (typeof parsed.docx_path === 'string' && parsed.docx_path.length > 0) {
        let st: Awaited<ReturnType<typeof lstat>>
        try {
          st = await lstat(parsed.docx_path)
        } catch {
          throw new DocxCliInputError('invalid_request', 'Missing or unreadable docx_path for import operation.')
        }
        if (st.isSymbolicLink() || !st.isFile()) {
          throw new DocxCliInputError('invalid_request', 'docx_path must be a regular non-symlink file.')
        }
        if (st.size > DEFAULT_MAX_STDIN_BYTES) {
          throw new DocxCliInputError('limit_exceeded', `docx_path exceeded ${DEFAULT_MAX_STDIN_BYTES} bytes.`)
        }
        bytes = await readFile(parsed.docx_path)
      } else if (typeof parsed.docx_b64 === 'string' && parsed.docx_b64.length > 0) {
        bytes = Buffer.from(parsed.docx_b64, 'base64')
      } else {
        throw new DocxCliInputError('invalid_request', 'Missing docx_path or docx_b64 for import operation.')
      }
      const result = await importDocx(bytes, {
        profile,
        quotas: parsed.quotas,
        sourceMarker: parsed.source_marker ?? null,
        zipReader: nodeZipReader,
        enableDiscussions: opts.enableDiscussions ?? true,
        enableRevisions: opts.enableRevisions ?? true,
        requireFormattingRevisions: opts.requireFormattingRevisions,
      })

      const extracted = extractCleanImportPayload(result.model)
      const tiptap = opts.stripSectionAndReviewMetadata
        ? null
        : fromIntermediate(result.model, {
            preserveSectionNodes: opts.preserveSectionNodes ?? false,
          })
      const report = new LossReportBuilder()
      report.merge(result.report.diagnostics)

      if (opts.bodyOnly) {
        const hasSectionMetadata = result.model.sections.length > 1 || result.model.sections.some(section =>
          section.pageSize || section.margins || section.titlePg ||
          Object.values(section.headers).some(Boolean) || Object.values(section.footers).some(Boolean),
        )
        if (hasSectionMetadata) {
          report.warn(
            'body_only_sections', 'sections', 'document', 'dropped',
            'The import response carries the body only. Section layout, headers, and footers are not retained.',
          )
        }
        if (result.model.discussions.length > 0) {
          report.warn(
            'body_only_discussions', 'discussions', 'document', 'dropped',
            'The import response does not retain discussion threads.',
          )
        }
      }

      const response: DocxCliSuccessResponse = {
        version: 1,
        request_id: reqId,
        ok: true,
        operation: 'import',
        profile,
        blocked: result.blocked,
        source_sha256: sha256Hex(bytes),
        document: tiptap ? tiptap.document : extracted.document,
        sections: extracted.sections,
        discussions: extracted.discussions,
        revisions: extracted.revisions,
        sections_count: extracted.sections.length,
        discussions_count: extracted.discussions.length,
        report: report.build(profile, result.report.source_marker),
        semantic: result.semantic,
      }
      return JSON.stringify(response)
    }

    if (parsed.operation === 'export') {
      if (!parsed.document || parsed.document.type !== 'doc') {
        throw new DocxCliInputError('invalid_request', 'Missing TipTap document for export operation.')
      }

      const effectiveDoc = Array.isArray(parsed.revisions) && parsed.revisions.length > 0
        ? decorateDocumentForExport(parsed.document, parsed.revisions, parsed.projection ?? 'original')
        : parsed.document

      const result = await exportDocx(
        {
          document: effectiveDoc,
          sections: parsed.sections,
          discussions: parsed.discussions,
          sourceMarker: parsed.source_marker ?? null,
          title: parsed.title ?? null,
          creator: parsed.creator ?? null,
        },
        {
          profile,
          projection: parsed.projection,
          sourceMarker: parsed.source_marker ?? null,
          enableDiscussions: opts.enableDiscussions ?? true,
          enableRevisions: opts.enableRevisions,
          requireFormattingRevisions: opts.requireFormattingRevisions,
        },
      )

      if (result.blocked || result.binary === null) {
        const response: DocxCliSuccessResponse = {
          version: 1,
          request_id: reqId,
          ok: true,
          operation: 'export',
          profile,
          blocked: true,
          report: result.report,
          semantic: result.semantic,
        }
        return JSON.stringify(response)
      }

      if (typeof parsed.output_path === 'string' && parsed.output_path.length > 0) {
        let existing: Awaited<ReturnType<typeof lstat>> | null = null
        try {
          existing = await lstat(parsed.output_path)
        } catch {
          existing = null
        }
        if (existing !== null && (existing.isSymbolicLink() || !existing.isFile())) {
          throw new DocxCliInputError('invalid_request', 'output_path must be a regular non-symlink file.')
        }
        await writeFile(parsed.output_path, result.binary, { mode: 0o600 })
        const response: DocxCliSuccessResponse = {
          version: 1,
          request_id: reqId,
          ok: true,
          operation: 'export',
          profile,
          blocked: false,
          docx_path: parsed.output_path,
          docx_sha256: sha256Hex(result.binary),
          report: result.report,
          semantic: result.semantic,
        }
        return JSON.stringify(response)
      }

      const response: DocxCliSuccessResponse = {
        version: 1,
        request_id: reqId,
        ok: true,
        operation: 'export',
        profile,
        blocked: false,
        docx_b64: Buffer.from(result.binary).toString('base64'),
        docx_sha256: sha256Hex(result.binary),
        report: result.report,
        semantic: result.semantic,
      }
      return JSON.stringify(response)
    }

    throw new DocxCliInputError('invalid_request', `Unsupported operation "${String(parsed.operation)}".`)
  } catch (err) {
    if (err instanceof DocxSecurityError) {
      return JSON.stringify(errorPayload(reqId, err.code, err.message))
    }
    if (err instanceof DocxProfileBlockedError) {
      return JSON.stringify(errorPayload(reqId, 'profile_blocked', err.message, err.report))
    }
    if (err instanceof DocxCliInputError) {
      return JSON.stringify(errorPayload(reqId, err.code, err.message))
    }
    const message = err instanceof Error ? err.message : String(err)
    return JSON.stringify(errorPayload(reqId, 'runtime_failed', message))
  }
}

async function readStdinBounded(maxBytes: number): Promise<string> {
  const chunks: Buffer[] = []
  let total = 0
  for await (const chunk of process.stdin) {
    const buf = Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk))
    total += buf.length
    if (total > maxBytes) {
      throw new DocxCliInputError('limit_exceeded', `CLI stdin payload exceeded ${maxBytes} bytes.`)
    }
    chunks.push(buf)
  }
  return Buffer.concat(chunks, total).toString('utf8')
}

async function runPersistentCli(maxBytes: number): Promise<void> {
  let buffered = ''
  for await (const chunk of process.stdin) {
    buffered += Buffer.isBuffer(chunk) ? chunk.toString('utf8') : String(chunk)
    if (Buffer.byteLength(buffered, 'utf8') > maxBytes) {
      process.stdout.write(`${JSON.stringify(errorPayload('', 'limit_exceeded', `CLI stdin payload exceeded ${maxBytes} bytes.`))}\n`)
      return
    }
    let newlineIdx = buffered.indexOf('\n')
    while (newlineIdx !== -1) {
      const line = buffered.slice(0, newlineIdx).trim()
      buffered = buffered.slice(newlineIdx + 1)
      if (line.length > 0) {
        try {
          const output = await handleExchangeCliRequest(line)
          process.stdout.write(`${output}\n`)
        } catch (err) {
          const message = err instanceof Error ? err.message : String(err)
          const code = err instanceof DocxCliInputError ? err.code : 'runtime_failed'
          process.stdout.write(`${JSON.stringify(errorPayload('', code, message))}\n`)
        }
      }
      newlineIdx = buffered.indexOf('\n')
    }
  }
  const trailing = buffered.trim()
  if (trailing.length > 0) {
    try {
      const output = await handleExchangeCliRequest(trailing)
      process.stdout.write(`${output}\n`)
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err)
      const code = err instanceof DocxCliInputError ? err.code : 'runtime_failed'
      process.stdout.write(`${JSON.stringify(errorPayload('', code, message))}\n`)
    }
  }
}

async function runCli(): Promise<void> {
  const maxBytes = DEFAULT_MAX_STDIN_BYTES
  if (process.argv.includes('--persistent')) {
    await runPersistentCli(maxBytes)
    return
  }
  try {
    const input = await readStdinBounded(maxBytes)
    const output = await handleExchangeCliRequest(input)
    process.stdout.write(`${output}\n`)
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err)
    const code = err instanceof DocxCliInputError ? err.code : 'runtime_failed'
    process.stdout.write(`${JSON.stringify(errorPayload('', code, message))}\n`)
  }
}

const entryArg = process.argv[1] ?? ''
if (typeof entryArg === 'string' && /(?:^|[\\/])cli\.(?:[mc]?js|ts)$/.test(entryArg)) {
  void runCli()
}
