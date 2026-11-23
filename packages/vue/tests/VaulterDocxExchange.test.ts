import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createReviewClient, ReviewApiError } from '../src/api/reviewClient'
import VaulterDocxExchange from '../src/components/VaulterDocxExchange.vue'
import type { ApplyExchangeReceipt, ExchangeJob } from '../src/types/review'

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function makeJob(overrides: Partial<ExchangeJob> = {}): ExchangeJob {
  return {
    id: '01950000-0000-7000-8000-000000000001',
    document_id: '01950000-0000-7000-8000-000000000010',
    request_key: 'imp-ui-1',
    kind: 'import',
    format: 'docx',
    profile_id: 'discussions',
    state: 'preview_ready',
    source_blob_id: '01950000-0000-7000-8000-000000000020',
    source_revision_id: null,
    source_generation: 2,
    source_position: 5,
    output_blob_id: '01950000-0000-7000-8000-000000000030',
    apply_command_id: null,
    apply_receipt_id: null,
    diagnostics_report: {
      schema: 'vaulter.docx.loss-report/v1',
      profile: 'discussions',
      source_marker: null,
      blocked: false,
      blocking_reasons: [],
      diagnostics: [
        {
          code: 'theme_color_approximated',
          severity: 'warning',
          feature: 'styles',
          location: 'word/styles.xml',
          action: 'degraded',
          message: 'Custom theme accent mapped to fallback highlight.',
        },
      ],
      summary: { info: 0, warning: 1, error: 0 },
    },
    error_code: null,
    preview: {
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_1' },
          content: [{ type: 'text', text: 'Imported heading clause' }],
        },
      ],
    },
    created_at: '2026-11-23T10:00:00Z',
    updated_at: '2026-11-23T10:00:01Z',
    finished_at: null,
    ...overrides,
  }
}

describe('VaulterDocxExchange UI & Reconnect Workflow (10.2)', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    window.sessionStorage.clear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('uploads DOCX, polls until preview_ready, shows loss report, requires explicit confirmation before apply, and reconnects safely', async () => {
    const queuedJob = makeJob({ state: 'queued', preview: null, diagnostics_report: null })
    const previewJob = makeJob({ state: 'preview_ready' })
    const receipt: ApplyExchangeReceipt = {
      job_id: previewJob.id,
      document_id: previewJob.document_id,
      state: 'applied',
      apply_command_id: 'apply-cmd-1',
      apply_receipt_id: '01950000-0000-7000-8000-000000000099',
      write_mode: 'snapshot',
      generation: 2,
      position: 6,
      replayed: false,
      pending_commit: false,
    }

    const calls: Array<{ url: string; method: string; body?: unknown }> = []
    let statusPolls = 0

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const url = String(input)
      const method = init?.method ?? 'GET'
      calls.push({ url, method, body: init?.body })

      if (method === 'POST' && url.endsWith('/documents/node-1/exchange-jobs')) {
        expect(init?.body).toBeInstanceOf(FormData)
        return jsonResponse(202, { data: queuedJob })
      }
      if (method === 'GET' && url.includes(`/documents/node-1/exchange-jobs/${previewJob.id}?`)) {
        statusPolls += 1
        return jsonResponse(200, { data: previewJob })
      }
      if (method === 'POST' && url.endsWith(`/documents/node-1/exchange-jobs/${previewJob.id}/apply`)) {
        return jsonResponse(200, { data: receipt })
      }

      return jsonResponse(404, { code: 'not_found' })
    })

    const client = createReviewClient({ fetch: fetchMock as unknown as typeof fetch })
    const wrapper = mount(VaulterDocxExchange, {
      props: {
        nodeId: 'node-1',
        client,
        storageKey: 'exchange-node-1',
        pollIntervalMs: 50,
        currentGeneration: 2,
        currentPosition: 5,
      },
    })

    const file = new File([new Uint8Array([0x50, 0x4b, 0x03, 0x04])], 'contract.docx', {
      type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    })
    const inputEl = wrapper.get('[data-control="file"]').element as HTMLInputElement
    Object.defineProperty(inputEl, 'files', { value: [file], configurable: true })
    await wrapper.get('[data-control="file"]').trigger('change')

    await wrapper.get('[data-action="upload-import"]').trigger('click')
    await flushPromises()
    expect(wrapper.attributes('data-job-state')).toBe('queued')
    expect(window.sessionStorage.getItem('exchange-node-1')).toBe(previewJob.id)

    // Advance poll timer -> preview_ready renders diagnostics & preview
    await vi.advanceTimersByTimeAsync(60)
    await flushPromises()
    expect(statusPolls).toBe(1)
    expect(wrapper.attributes('data-job-state')).toBe('preview_ready')
    expect(wrapper.get('[data-summary="warning"]').text()).toContain('Warnings: 1')
    expect(wrapper.get('[data-diagnostic-code="theme_color_approximated"]').text()).toContain('theme_color_approximated')
    expect(wrapper.get('[data-preview-ready="true"]').text()).toContain('1 top-level blocks')

    // Apply button MUST stay disabled until user explicitly confirms
    const applyBtn = wrapper.get('[data-action="apply-import"]')
    expect((applyBtn.element as HTMLButtonElement).disabled).toBe(true)
    expect(await wrapper.vm.apply()).toBeNull()
    expect(wrapper.get('[data-error="true"]').text()).toMatch(/Explicit user confirmation/)
    expect(calls.filter((c) => c.url.endsWith('/apply'))).toHaveLength(0)

    // Check confirmation checkbox and apply
    await wrapper.get('[data-control="confirm-apply"]').setValue(true)
    expect((wrapper.get('[data-action="apply-import"]').element as HTMLButtonElement).disabled).toBe(false)
    await wrapper.get('[data-action="apply-import"]').trigger('click')
    await flushPromises()

    expect(wrapper.attributes('data-job-state')).toBe('applied')
    expect(wrapper.get('[data-applied="true"]').text()).toContain(receipt.apply_receipt_id!)

    // Simulate reconnect (new mounted component instance restoring jobId from sessionStorage)
    const reconnectedWrapper = mount(VaulterDocxExchange, {
      props: {
        nodeId: 'node-1',
        client,
        storageKey: 'exchange-node-1',
        autoReconnect: true,
      },
    })
    await flushPromises()
    expect(reconnectedWrapper.attributes('data-job-id')).toBe(previewJob.id)
    expect(reconnectedWrapper.attributes('data-job-state')).toBe('preview_ready')
    expect(reconnectedWrapper.get('[data-summary="warning"]').text()).toContain('Warnings: 1')
  })

  it('blocks apply on blocked loss report and surfaces stale head conflict errors on apply', async () => {
    const blockedJob = makeJob({
      diagnostics_report: {
        schema: 'vaulter.docx.loss-report/v1',
        profile: 'strict-ooxml',
        source_marker: null,
        blocked: true,
        blocking_reasons: ['unsupported_revision_style: w:rPrChange blocked in strict-ooxml'],
        diagnostics: [
          {
            code: 'unsupported_revision_style',
            severity: 'error',
            feature: 'revisions',
            location: 'word/document.xml',
            action: 'blocked',
            message: 'Native formatting revision blocked.',
          },
        ],
        summary: { info: 0, warning: 0, error: 1 },
      },
    })

    const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const url = String(input)
      if ((init?.method ?? 'GET') === 'GET' && url.includes(`/documents/node-1/exchange-jobs/${blockedJob.id}`)) {
        return jsonResponse(200, { data: blockedJob })
      }
      if ((init?.method ?? 'GET') === 'POST' && url.endsWith('/apply')) {
        return jsonResponse(409, {
          code: 'exchange_stale_preview',
          title: 'Import preview is stale for current document head',
          detail: 'Document head changed after preview was generated.',
        })
      }
      return jsonResponse(404, { code: 'not_found' })
    })

    const client = createReviewClient({ fetch: fetchMock as unknown as typeof fetch })
    const blockedWrapper = mount(VaulterDocxExchange, {
      props: { nodeId: 'node-1', client, initialJobId: blockedJob.id },
    })
    await flushPromises()

    expect(blockedWrapper.get('[role="alert"]').text()).toContain('Blocked:')
    expect((blockedWrapper.get('[data-action="apply-import"]').element as HTMLButtonElement).disabled).toBe(true)

    // Non-blocked preview encountering 409 stale head on apply
    blockedJob.diagnostics_report = {
      schema: 'vaulter.docx.loss-report/v1',
      profile: 'discussions',
      source_marker: null,
      blocked: false,
      blocking_reasons: [],
      diagnostics: [],
      summary: { info: 0, warning: 0, error: 0 },
    }
    const staleWrapper = mount(VaulterDocxExchange, {
      props: { nodeId: 'node-1', client, initialJobId: blockedJob.id },
    })
    await flushPromises()
    await staleWrapper.get('[data-control="confirm-apply"]').setValue(true)
    await staleWrapper.get('[data-action="apply-import"]').trigger('click')
    await flushPromises()

    expect(staleWrapper.get('[data-error="true"]').text()).toContain('Document head changed')
    expect(staleWrapper.vm.error).toBeInstanceOf(ReviewApiError)
  })
})
