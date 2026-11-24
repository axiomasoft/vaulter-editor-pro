import { computed, getCurrentScope, onScopeDispose, ref, shallowRef, toRaw, toValue, watch, type ComputedRef, type MaybeRefOrGetter, type Ref, type ShallowRef } from 'vue'
import {
  DOCX_PROFILE_IDS,
  exportDocx,
  getProfileCapabilities,
  previewDocxExport,
  type AnchoredCommentLike,
  type DocxDiscussionInput,
  type DocxProfileId,
  type DocxProjectionMode,
  type DocxSectionInput,
  type DocxWorkerRequest,
  type DocxWorkerResponse,
  type ExportDocxResult,
  type IntermediateDocument,
  type LossReport,
  type ProfileCapabilities,
  type SemanticReport,
  type SourceMarker,
  type TipTapDoc,
} from '@vaulter/editor-pro-docx/browser'
import type { AnchoredComment, RevisionMeta } from '../types/review'

export type LocalDocxExportErrorCode =
  | 'unconfirmed_version'
  | 'access_denied'
  | 'invalid_document'
  | 'profile_blocked'
  | 'worker_error'

export class LocalDocxExportError extends Error {
  readonly code: LocalDocxExportErrorCode
  readonly report?: LossReport

  constructor(code: LocalDocxExportErrorCode, message: string, report?: LossReport) {
    super(message)
    this.name = 'LocalDocxExportError'
    this.code = code
    this.report = report
  }
}

export interface LocalDocxSource {
  /** Confirmed document TipTap JSON content already loaded in memory (never fetched here). */
  content: TipTapDoc | Record<string, unknown> | null
  /** Optional confirmed revision metadata for source_marker provenance. */
  revision?: RevisionMeta | null
  /** Optional range discussions loaded alongside the confirmed version. */
  discussions?: Array<AnchoredComment | DocxDiscussionInput>
  /** Optional multi-section layout metadata. */
  sections?: DocxSectionInput[]
  title?: string | null
  creator?: string | null
  /** Whether the version is confirmed by the server/host (default true when content is provided). */
  confirmed?: boolean
  /** Whether the current actor retains read/export access (default true). */
  accessible?: boolean
  filename?: string
}

export interface LocalDocxExportOutput {
  blob: Blob
  bytes: Uint8Array
  filename: string
  mimeType: string
  report: LossReport
  model: IntermediateDocument
  semantic: SemanticReport
}

export interface UseLocalDocxExportOptions {
  source: MaybeRefOrGetter<LocalDocxSource>
  defaultProfile?: DocxProfileId
  defaultProjection?: DocxProjectionMode
  enableDiscussions?: boolean
  enableRevisions?: boolean
  requireFormattingRevisions?: boolean
  /** Optional Web Worker factory for running browser conversion off the main thread. */
  workerFactory?: () => Worker
  autoPreview?: boolean
}

export interface UseLocalDocxExportReturn {
  profile: Ref<DocxProfileId>
  projection: Ref<DocxProjectionMode>
  availableProfiles: ComputedRef<ProfileCapabilities[]>
  sourceMarker: ComputedRef<SourceMarker | null>
  isConfirmedAndAccessible: ComputedRef<boolean>
  previewReport: ShallowRef<LossReport | null>
  previewSemantic: ShallowRef<SemanticReport | null>
  lastResult: ShallowRef<LocalDocxExportOutput | null>
  exporting: Ref<boolean>
  error: ShallowRef<LocalDocxExportError | null>
  preview: () => Promise<LossReport | null>
  exportLocal: () => Promise<LocalDocxExportOutput>
  download: () => Promise<LocalDocxExportOutput>
}

export const DOCX_MIME_TYPE =
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document'

function sanitizeFilename(raw: string | null | undefined, revision?: RevisionMeta | null): string {
  const base = (raw ?? (revision?.id ? `document-${revision.id}` : 'document'))
    .replace(/[^A-Za-z0-9._-]+/g, '-')
    .replace(/^-+|-+$/g, '')
  const normalized = base || 'document'
  return normalized.toLowerCase().endsWith('.docx') ? normalized : `${normalized}.docx`
}

function buildSourceMarker(
  revision: RevisionMeta | null | undefined,
  projection: DocxProjectionMode,
): SourceMarker | null {
  if (!revision) {
    return { projection }
  }
  return {
    nodeId: revision.node_id,
    documentId: revision.document_id,
    revisionId: revision.id,
    generation: revision.collab_epoch,
    position: revision.state_version,
    schemaVersion: revision.schema_version,
    contentHash: revision.content_hash,
    projection,
  }
}

function assertConfirmedAccessible(source: LocalDocxSource): asserts source is LocalDocxSource & {
  content: TipTapDoc
} {
  if (source.accessible === false) {
    throw new LocalDocxExportError(
      'access_denied',
      'Access to this document version is revoked or unavailable.',
    )
  }
  if (source.confirmed === false) {
    throw new LocalDocxExportError(
      'unconfirmed_version',
      'Only a confirmed version can be exported locally.',
    )
  }
  if (!source.content || source.content.type !== 'doc') {
    throw new LocalDocxExportError(
      'invalid_document',
      'Confirmed TipTap document JSON is missing or invalid.',
    )
  }
}

function unwrapWorkerPayload(value: unknown): unknown {
  // The worker payload is JSON-shaped, and toRaw only unwraps the current level.
  if (Array.isArray(value)) {
    return toRaw(value).map(unwrapWorkerPayload)
  }
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(toRaw(value)).map(([key, nested]) => [key, unwrapWorkerPayload(nested)]),
    )
  }
  return value
}

interface PendingWorkerRequest {
  kind: 'preview' | 'export'
  resolve: (value: Omit<ExportDocxResult, 'binary'> | ExportDocxResult | null) => void
  reject: (reason?: unknown) => void
}

export function useLocalDocxExport(options: UseLocalDocxExportOptions): UseLocalDocxExportReturn {
  const enableDiscussions = options.enableDiscussions ?? true
  const enableRevisions = options.enableRevisions ?? false
  const defaultProfile: DocxProfileId =
    options.defaultProfile ?? (enableDiscussions ? 'discussions' : 'standard')

  const profile = ref<DocxProfileId>(defaultProfile)
  const projection = ref<DocxProjectionMode>(
    options.defaultProjection ??
      getProfileCapabilities(defaultProfile, { enableDiscussions, enableRevisions }).defaultProjection,
  )

  const previewReport = shallowRef<LossReport | null>(null)
  const previewSemantic = shallowRef<SemanticReport | null>(null)
  const lastResult = shallowRef<LocalDocxExportOutput | null>(null)
  const exporting = ref(false)
  const error = shallowRef<LocalDocxExportError | null>(null)

  const availableProfiles = computed<ProfileCapabilities[]>(() => {
    const allowed: DocxProfileId[] = ['standard']
    if (enableDiscussions) {
      allowed.push('discussions', 'legacy-discussions')
    }
    if (enableRevisions) {
      allowed.push('revisions', 'strict-ooxml')
    } else if (!allowed.includes(profile.value) && DOCX_PROFILE_IDS.includes(profile.value)) {
      allowed.push(profile.value)
    }
    return allowed.map((id) =>
      getProfileCapabilities(id, {
        enableDiscussions,
        enableRevisions,
        requireFormattingRevisions: options.requireFormattingRevisions,
      }),
    )
  })

  const isConfirmedAndAccessible = computed<boolean>(() => {
    const src = toValue(options.source)
    return (
      src.accessible !== false &&
      src.confirmed !== false &&
      src.content !== null &&
      src.content.type === 'doc'
    )
  })

  const sourceMarker = computed<SourceMarker | null>(() => {
    const src = toValue(options.source)
    return buildSourceMarker(src.revision, projection.value)
  })

  let reqSeq = 0
  let previewSeq = 0
  let exportSeq = 0
  let worker: Worker | null = null
  let disposed = false
  let latestPreviewId: string | null = null
  let latestExportId: string | null = null
  const pending = new Map<string, PendingWorkerRequest>()

  const onWorkerMessage = (event: MessageEvent<DocxWorkerResponse>) => {
    const payload = event.data
    if (!payload) return
    const entry = pending.get(payload.id)
    if (!entry) return
    pending.delete(payload.id)
    if (payload.ok) {
      entry.resolve(payload.result)
    } else {
      entry.reject(
        new LocalDocxExportError(
          'worker_error',
          payload.error.message,
          payload.error.report as LossReport | undefined,
        ),
      )
    }
  }

  const teardownWorker = (reason?: string) => {
    const active = worker
    worker = null
    if (active) {
      active.removeEventListener('message', onWorkerMessage)
      active.removeEventListener('error', onWorkerError)
      active.terminate()
    }
    for (const [id, entry] of pending.entries()) {
      pending.delete(id)
      if (entry.kind === 'preview') {
        entry.resolve(null)
      } else {
        entry.reject(new LocalDocxExportError('worker_error', reason || 'DOCX worker failed'))
      }
    }
  }

  const onWorkerError = (err: ErrorEvent) => {
    teardownWorker(err.message || 'DOCX worker failed')
  }

  function ensureWorker(): Worker {
    if (!worker) {
      const created = options.workerFactory!()
      created.addEventListener('message', onWorkerMessage)
      created.addEventListener('error', onWorkerError)
      worker = created
    }
    return worker
  }

  function sendWorkerRequest(
    request: DocxWorkerRequest,
  ): Promise<Omit<ExportDocxResult, 'binary'> | ExportDocxResult | null> {
    if (disposed) {
      return request.kind === 'preview'
        ? Promise.resolve(null)
        : Promise.reject(new LocalDocxExportError('worker_error', 'DOCX export worker disposed.'))
    }
    if (request.kind === 'preview' && latestPreviewId !== null) {
      const prev = pending.get(latestPreviewId)
      if (prev) {
        pending.delete(latestPreviewId)
        prev.resolve(null)
      }
    } else if (request.kind === 'export' && latestExportId !== null) {
      const prev = pending.get(latestExportId)
      if (prev) {
        pending.delete(latestExportId)
        prev.reject(new LocalDocxExportError('worker_error', 'Superseded by a newer DOCX export request.'))
      }
    }
    if (request.kind === 'preview') {
      latestPreviewId = request.id
    } else {
      latestExportId = request.id
    }

    const activeWorker = ensureWorker()
    return new Promise((resolve, reject) => {
      pending.set(request.id, { kind: request.kind, resolve, reject })
      try {
        activeWorker.postMessage(unwrapWorkerPayload(request))
      } catch (err) {
        pending.delete(request.id)
        reject(err)
      }
    })
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      disposed = true
      teardownWorker('DOCX export worker disposed.')
    })
  }

  function buildPayload(src: LocalDocxSource & { content: TipTapDoc }) {
    const marker = buildSourceMarker(src.revision, projection.value)
    const exportInput = {
      document: src.content,
      sections: src.sections,
      discussions: src.discussions as Array<DocxDiscussionInput | AnchoredCommentLike> | undefined,
      sourceMarker: marker,
      title: src.title,
      creator: src.creator,
    }
    const exportOptions = {
      profile: profile.value,
      projection: projection.value,
      sourceMarker: marker,
      enableDiscussions,
      enableRevisions,
      requireFormattingRevisions: options.requireFormattingRevisions,
    }
    return { exportInput, exportOptions }
  }

  async function preview(): Promise<LossReport | null> {
    const seq = ++previewSeq
    const src = toValue(options.source)
    error.value = null
    try {
      assertConfirmedAccessible(src)
    } catch (caught) {
      previewReport.value = null
      previewSemantic.value = null
      error.value = caught as LocalDocxExportError
      return null
    }

    const { exportInput, exportOptions } = buildPayload(src)
    let result: Omit<ExportDocxResult, 'binary'> | null
    if (options.workerFactory) {
      result = await sendWorkerRequest({
        id: `preview-${++reqSeq}`,
        kind: 'preview',
        input: exportInput,
        options: exportOptions,
      })
    } else {
      result = previewDocxExport(exportInput, exportOptions)
    }

    if (!result || seq !== previewSeq || disposed) {
      return null
    }

    previewReport.value = result.report
    previewSemantic.value = result.semantic
    return result.report
  }

  async function exportLocal(): Promise<LocalDocxExportOutput> {
    const seq = ++exportSeq
    const src = toValue(options.source)
    error.value = null
    assertConfirmedAccessible(src)

    exporting.value = true
    try {
      const { exportInput, exportOptions } = buildPayload(src)
      let result: ExportDocxResult

      if (options.workerFactory) {
        const res = await sendWorkerRequest({
          id: `export-${++reqSeq}`,
          kind: 'export',
          input: exportInput,
          options: exportOptions,
        })
        if (!res) {
          throw new LocalDocxExportError('worker_error', 'DOCX export cancelled.')
        }
        result = res as ExportDocxResult
      } else {
        result = await exportDocx(exportInput, exportOptions)
      }

      previewReport.value = result.report
      previewSemantic.value = result.semantic

      if (result.blocked || result.binary === null) {
        const blockedError = new LocalDocxExportError(
          'profile_blocked',
          result.report.blocking_reasons.join('; ') || `DOCX profile "${profile.value}" blocked.`,
          result.report,
        )
        error.value = blockedError
        throw blockedError
      }

      const bytes = result.binary
      const blobBuffer = new Uint8Array(bytes.byteLength)
      blobBuffer.set(bytes)
      const blob = new Blob([blobBuffer.buffer], { type: DOCX_MIME_TYPE })
      const output: LocalDocxExportOutput = {
        blob,
        bytes,
        filename: sanitizeFilename(src.filename ?? src.title, src.revision),
        mimeType: DOCX_MIME_TYPE,
        report: result.report,
        model: result.model,
        semantic: result.semantic,
      }
      lastResult.value = output
      return output
    } catch (caught) {
      if (caught instanceof LocalDocxExportError) {
        error.value = caught
        throw caught
      }
      const wrapped = new LocalDocxExportError(
        'worker_error',
        caught instanceof Error ? caught.message : String(caught),
      )
      error.value = wrapped
      throw wrapped
    } finally {
      if (seq === exportSeq) {
        exporting.value = false
      }
    }
  }

  async function download(): Promise<LocalDocxExportOutput> {
    const output = await exportLocal()
    if (typeof document !== 'undefined' && typeof URL.createObjectURL === 'function') {
      const url = URL.createObjectURL(output.blob)
      const link = document.createElement('a')
      link.href = url
      link.download = output.filename
      link.style.display = 'none'
      document.body?.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    }
    return output
  }

  if (options.autoPreview !== false) {
    watch(
      () => [toValue(options.source), profile.value, projection.value],
      () => {
        void preview().catch(() => undefined)
      },
      { immediate: true },
    )
  }

  return {
    profile,
    projection,
    availableProfiles,
    sourceMarker,
    isConfirmedAndAccessible,
    previewReport,
    previewSemantic,
    lastResult,
    exporting,
    error,
    preview,
    exportLocal,
    download,
  }
}
