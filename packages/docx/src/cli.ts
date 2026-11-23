import { createHash } from 'node:crypto'
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
import { fromIntermediate } from './tiptap/adapter'
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
  document?: TipTapDoc
  sections?: DocxSectionInput[]
  discussions?: DocxDiscussionInput[]
  title?: string | null
  creator?: string | null
  source_marker?: SourceMarker | null
  quotas?: ZipQuotas
  options?: {
    enableDiscussions?: boolean
    enableRevisions?: boolean
    requireFormattingRevisions?: boolean
    preserveSectionNodes?: boolean
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
  docx_sha256?: string
  document?: TipTapDoc
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
      if (typeof parsed.docx_b64 !== 'string' || parsed.docx_b64.length === 0) {
        throw new DocxCliInputError('invalid_request', 'Missing docx_b64 for import operation.')
      }
      const bytes = Buffer.from(parsed.docx_b64, 'base64')
      const result = await importDocx(bytes, {
        profile,
        quotas: parsed.quotas,
        sourceMarker: parsed.source_marker ?? null,
        zipReader: nodeZipReader,
        enableDiscussions: opts.enableDiscussions ?? true,
        enableRevisions: opts.enableRevisions ?? true,
        requireFormattingRevisions: opts.requireFormattingRevisions,
      })

      const tiptap = fromIntermediate(result.model, {
        preserveSectionNodes: opts.preserveSectionNodes ?? false,
      })
      const report = new LossReportBuilder()
      report.merge(result.report.diagnostics)
      const hasSectionMetadata = tiptap.sections.length > 1 || tiptap.sections.some(section =>
        section.pageSize || section.margins || section.titlePg ||
        Object.values(section.headers).some(Boolean) || Object.values(section.footers).some(Boolean),
      )
      if (hasSectionMetadata) {
        report.warn(
          'body_only_sections', 'sections', 'document', 'dropped',
          'The import response carries the body only. Section layout, headers, and footers are not retained.',
        )
      }
      if (tiptap.discussions.length > 0) {
        report.warn(
          'body_only_discussions', 'discussions', 'document', 'dropped',
          'The import response does not retain discussion threads.',
        )
      }

      const response: DocxCliSuccessResponse = {
        version: 1,
        request_id: reqId,
        ok: true,
        operation: 'import',
        profile,
        blocked: result.blocked,
        source_sha256: sha256Hex(bytes),
        document: tiptap.document,
        sections_count: tiptap.sections.length,
        discussions_count: tiptap.discussions.length,
        report: report.build(profile, result.report.source_marker),
        semantic: result.semantic,
      }
      return JSON.stringify(response)
    }

    if (parsed.operation === 'export') {
      if (!parsed.document || parsed.document.type !== 'doc') {
        throw new DocxCliInputError('invalid_request', 'Missing TipTap document for export operation.')
      }

      const result = await exportDocx(
        {
          document: parsed.document,
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

async function runCli(): Promise<void> {
  const maxBytes = DEFAULT_MAX_STDIN_BYTES
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
