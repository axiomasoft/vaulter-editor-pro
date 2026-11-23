import {
  computed,
  onScopeDispose,
  ref,
  shallowRef,
  toValue,
  type ComputedRef,
  type MaybeRefOrGetter,
  type Ref,
  type ShallowRef,
} from 'vue'
import type { DocxProfileId, LossReport } from '@vaulter/editor-pro-docx/browser'
import { useReviewClient, type ReviewApiError, type ReviewClient } from '../api/reviewClient'
import type {
  ApplyExchangeReceipt,
  ExchangeJob,
  RegisterExchangeJobPayload,
} from '../types/review'

export interface UseDocxExchangeOptions {
  nodeId: MaybeRefOrGetter<string>
  client?: ReviewClient
  initialJobId?: string | null
  storageKey?: string
  pollIntervalMs?: number
  defaultProfile?: DocxProfileId
}

export interface UseDocxExchangeReturn {
  job: ShallowRef<ExchangeJob | null>
  jobId: Ref<string | null>
  profile: Ref<DocxProfileId>
  loading: Ref<boolean>
  polling: Ref<boolean>
  applying: Ref<boolean>
  applyConfirmed: Ref<boolean>
  applyReceipt: ShallowRef<ApplyExchangeReceipt | null>
  error: ShallowRef<ReviewApiError | Error | null>
  diagnosticsReport: ComputedRef<LossReport | null>
  previewDocument: ComputedRef<Record<string, unknown> | null>
  canApply: ComputedRef<boolean>
  canDownload: ComputedRef<boolean>
  uploadFile: (
    file: File | Blob,
    options?: {
      requestKey?: string
      profileId?: DocxProfileId
      sourceGeneration?: number | null
      sourcePosition?: number | null
      filename?: string
    },
  ) => Promise<ExchangeJob>
  registerImport: (input: Omit<RegisterExchangeJobPayload, 'kind'>) => Promise<ExchangeJob>
  registerExport: (input: Omit<RegisterExchangeJobPayload, 'kind'>) => Promise<ExchangeJob>
  reconnect: (targetJobId?: string | null) => Promise<ExchangeJob | null>
  refreshStatus: () => Promise<ExchangeJob | null>
  applyImport: (options?: { applyCommandId?: string }) => Promise<ApplyExchangeReceipt>
  downloadExport: () => Promise<Blob>
  stopPolling: () => void
  resetApplyConfirmation: () => void
}

function makeOpaqueKey(prefix: string): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return `${prefix}-${globalThis.crypto.randomUUID()}`
  }
  return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

function readStoredJobId(storageKey?: string): string | null {
  if (!storageKey || typeof window === 'undefined') {
    return null
  }
  try {
    return window.sessionStorage?.getItem(storageKey) ?? null
  } catch {
    return null
  }
}

function writeStoredJobId(storageKey: string | undefined, value: string | null): void {
  if (!storageKey || typeof window === 'undefined') {
    return
  }
  try {
    if (value) {
      window.sessionStorage?.setItem(storageKey, value)
    } else {
      window.sessionStorage?.removeItem(storageKey)
    }
  } catch {
    // storage disabled cleanly
  }
}

function isLossReport(raw: unknown): raw is LossReport {
  return (
    typeof raw === 'object' &&
    raw !== null &&
    'summary' in raw &&
    'diagnostics' in raw &&
    Array.isArray((raw as LossReport).diagnostics)
  )
}

export function useDocxExchange(options: UseDocxExchangeOptions): UseDocxExchangeReturn {
  const client = options.client ?? useReviewClient()
  const pollIntervalMs = options.pollIntervalMs ?? 1200

  const profile = ref<DocxProfileId>(options.defaultProfile ?? 'discussions')
  const jobId = ref<string | null>(options.initialJobId ?? readStoredJobId(options.storageKey))
  const job = shallowRef<ExchangeJob | null>(null)
  const loading = ref(false)
  const polling = ref(false)
  const applying = ref(false)
  const applyConfirmed = ref(false)
  const applyReceipt = shallowRef<ApplyExchangeReceipt | null>(null)
  const error = shallowRef<ReviewApiError | Error | null>(null)

  const pendingApplyCommands = new Map<string, string>()
  let timer: ReturnType<typeof setTimeout> | null = null

  const diagnosticsReport = computed<LossReport | null>(() => {
    const raw = job.value?.diagnostics_report
    return isLossReport(raw) ? raw : null
  })

  const previewDocument = computed<Record<string, unknown> | null>(() => job.value?.preview ?? null)

  const canApply = computed<boolean>(() => {
    const current = job.value
    if (!current || current.kind !== 'import' || current.state !== 'preview_ready') {
      return false
    }
    if (diagnosticsReport.value?.blocked) {
      return false
    }
    return applyConfirmed.value && !applying.value
  })

  const canDownload = computed<boolean>(() => {
    const current = job.value
    return Boolean(current && current.kind === 'export' && current.state === 'completed' && !loading.value)
  })

  function stopPolling(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
    polling.value = false
  }

  function schedulePollIfActive(current: ExchangeJob | null): void {
    stopPolling()
    if (!current) {
      return
    }
    const active = current.state === 'queued' || current.state === 'running'
    const pendingJournal =
      current.state === 'preview_ready' && current.kind === 'import' && current.apply_command_id !== null
    if (!active && !pendingJournal) {
      return
    }
    polling.value = true
    timer = setTimeout(() => {
      void refreshStatus().catch(() => undefined)
    }, pollIntervalMs)
  }

  function updateJob(next: ExchangeJob): ExchangeJob {
    const changedJob = job.value?.id !== next.id
    job.value = next
    jobId.value = next.id
    writeStoredJobId(options.storageKey, next.id)
    if (changedJob) {
      applyConfirmed.value = false
      applyReceipt.value = null
    }
    if (next.state === 'applied' && next.apply_command_id && next.apply_receipt_id && !applyReceipt.value) {
      applyReceipt.value = {
        job_id: next.id,
        document_id: next.document_id,
        state: next.state,
        apply_command_id: next.apply_command_id,
        apply_receipt_id: next.apply_receipt_id,
        write_mode: 'snapshot',
        generation: next.source_generation ?? 0,
        position: next.source_position ?? 0,
        replayed: true,
        pending_commit: false,
      }
    }
    schedulePollIfActive(next)
    return next
  }

  async function refreshStatus(): Promise<ExchangeJob | null> {
    const id = jobId.value
    if (!id) {
      return null
    }
    loading.value = true
    error.value = null
    try {
      const fresh = await client.exchange.status(toValue(options.nodeId), id, true)
      return updateJob(fresh)
    } catch (caught) {
      stopPolling()
      error.value = caught as Error
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function reconnect(targetJobId?: string | null): Promise<ExchangeJob | null> {
    const id = targetJobId ?? jobId.value ?? readStoredJobId(options.storageKey)
    if (!id) {
      return null
    }
    jobId.value = id
    writeStoredJobId(options.storageKey, id)
    return refreshStatus()
  }

  async function uploadFile(
    file: File | Blob,
    opts?: {
      requestKey?: string
      profileId?: DocxProfileId
      sourceGeneration?: number | null
      sourcePosition?: number | null
      filename?: string
    },
  ): Promise<ExchangeJob> {
    loading.value = true
    error.value = null
    stopPolling()
    try {
      const form = new FormData()
      form.append('request_key', opts?.requestKey ?? makeOpaqueKey('imp-docx'))
      form.append('kind', 'import')
      form.append('format', 'docx')
      form.append('profile_id', opts?.profileId ?? profile.value)
      if (opts?.sourceGeneration !== undefined && opts.sourceGeneration !== null) {
        form.append('source_generation', String(opts.sourceGeneration))
      }
      if (opts?.sourcePosition !== undefined && opts.sourcePosition !== null) {
        form.append('source_position', String(opts.sourcePosition))
      }
      const name = opts?.filename ?? (file instanceof File ? file.name : 'upload.docx')
      form.append('file', file, name)

      const created = await client.exchange.register(toValue(options.nodeId), form)
      applyConfirmed.value = false
      applyReceipt.value = null
      return updateJob(created)
    } catch (caught) {
      error.value = caught as Error
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function registerImport(input: Omit<RegisterExchangeJobPayload, 'kind'>): Promise<ExchangeJob> {
    loading.value = true
    error.value = null
    stopPolling()
    try {
      const created = await client.exchange.register(toValue(options.nodeId), {
        profile_id: profile.value,
        format: 'docx',
        ...input,
        kind: 'import',
      })
      applyConfirmed.value = false
      applyReceipt.value = null
      return updateJob(created)
    } catch (caught) {
      error.value = caught as Error
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function registerExport(input: Omit<RegisterExchangeJobPayload, 'kind'>): Promise<ExchangeJob> {
    loading.value = true
    error.value = null
    stopPolling()
    try {
      const created = await client.exchange.register(toValue(options.nodeId), {
        profile_id: profile.value,
        format: 'docx',
        ...input,
        kind: 'export',
      })
      return updateJob(created)
    } catch (caught) {
      error.value = caught as Error
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function applyImport(opts?: { applyCommandId?: string }): Promise<ApplyExchangeReceipt> {
    const current = job.value
    if (!current || current.kind !== 'import') {
      const err = new Error('No import job is currently loaded.')
      error.value = err
      throw err
    }
    if (!applyConfirmed.value) {
      const err = new Error(
        'Explicit user confirmation is required before applying the imported DOCX preview.',
      )
      error.value = err
      throw err
    }
    if (diagnosticsReport.value?.blocked) {
      const err = new Error(
        'Cannot apply import because the DOCX profile reported blocking diagnostics.',
      )
      error.value = err
      throw err
    }

    const commandId =
      opts?.applyCommandId ??
      current.apply_command_id ??
      pendingApplyCommands.get(current.id) ??
      makeOpaqueKey('apply')
    pendingApplyCommands.set(current.id, commandId)

    applying.value = true
    error.value = null
    try {
      const receipt = await client.exchange.apply(toValue(options.nodeId), current.id, {
        apply_command_id: commandId,
        expected_generation: current.source_generation,
        expected_position: current.source_position,
      })
      applyReceipt.value = receipt
      job.value = {
        ...current,
        state: receipt.state,
        apply_command_id: receipt.apply_command_id,
        apply_receipt_id: receipt.apply_receipt_id,
      }
      if (receipt.pending_commit) {
        schedulePollIfActive(job.value)
      }
      return receipt
    } catch (caught) {
      error.value = caught as Error
      throw caught
    } finally {
      applying.value = false
    }
  }

  async function downloadExport(): Promise<Blob> {
    const current = job.value
    if (!current || current.kind !== 'export' || current.state !== 'completed') {
      throw new Error('Completed export job is required before downloading.')
    }
    loading.value = true
    error.value = null
    try {
      const blob = await client.exchange.download(toValue(options.nodeId), current.id)
      if (typeof document !== 'undefined' && typeof URL.createObjectURL === 'function') {
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `document-${current.id}.docx`
        link.style.display = 'none'
        document.body?.appendChild(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(url)
      }
      return blob
    } catch (caught) {
      error.value = caught as Error
      throw caught
    } finally {
      loading.value = false
    }
  }

  function resetApplyConfirmation(): void {
    applyConfirmed.value = false
  }

  onScopeDispose(() => {
    stopPolling()
  })

  return {
    job,
    jobId,
    profile,
    loading,
    polling,
    applying,
    applyConfirmed,
    applyReceipt,
    error,
    diagnosticsReport,
    previewDocument,
    canApply,
    canDownload,
    uploadFile,
    registerImport,
    registerExport,
    reconnect,
    refreshStatus,
    applyImport,
    downloadExport,
    stopPolling,
    resetApplyConfirmation,
  }
}
