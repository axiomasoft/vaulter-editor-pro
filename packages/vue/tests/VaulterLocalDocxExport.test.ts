import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive, shallowReactive } from 'vue'
import {
  browserZipReader,
  compareSemanticReports,
  exportDocx as exportDocxBrowser,
  importDocx as importDocxBrowser,
  type DocxWorkerRequest,
} from '@vaulter/editor-pro-docx/browser'
import { exportDocx as exportDocxNode } from '@vaulter/editor-pro-docx/node'
import VaulterLocalDocxExport from '../src/components/VaulterLocalDocxExport.vue'
import { attachDocxWorkerListener } from '../src/docx/localExportWorker'
import type { AnchoredComment, RevisionMeta } from '../src/types/review'
import { CORPUS_D1, CORPUS_D2, CORPUS_D3, CORPUS_D4 } from '../../../tests/corpus/docx/corpus'

function createMockWorkerFactory() {
  return (): Worker => {
    const listeners = new Map<string, Set<EventListener>>()
    const workerListeners = new Map<string, Set<EventListener>>()

    const dispatchToMain = (data: unknown) => {
      const evt = new MessageEvent('message', { data })
      for (const fn of listeners.get('message') ?? []) {
        fn(evt)
      }
    }

    attachDocxWorkerListener({
      addEventListener(_type, listener) {
        const set = workerListeners.get('message') ?? new Set()
        set.add(listener as EventListener)
        workerListeners.set('message', set)
      },
      postMessage(message) {
        queueMicrotask(() => dispatchToMain(message))
      },
    })

    return {
      addEventListener(type: string, listener: EventListenerOrEventListenerObject) {
        const fn = typeof listener === 'function' ? listener : listener.handleEvent.bind(listener)
        const set = listeners.get(type) ?? new Set()
        set.add(fn)
        listeners.set(type, set)
      },
      removeEventListener(type: string, listener: EventListenerOrEventListenerObject) {
        const fn = typeof listener === 'function' ? listener : listener.handleEvent.bind(listener)
        listeners.get(type)?.delete(fn)
      },
      postMessage(msg: DocxWorkerRequest) {
        const evt = new MessageEvent('message', { data: structuredClone(msg) })
        for (const fn of workerListeners.get('message') ?? []) {
          fn(evt)
        }
      },
      terminate: vi.fn(),
      onmessage: null,
      onmessageerror: null,
      onerror: null,
      dispatchEvent: () => true,
    } as unknown as Worker
  }
}

const CONFIRMED_REVISION: RevisionMeta = {
  kind: 'revision',
  id: 'rev_confirmed_10',
  document_id: 'doc_1',
  node_id: 'node_1',
  type: 'rich_text',
  number: 10,
  schema_version: 2,
  state_version: 42,
  collab_epoch: 3,
  content_hash: 'sha256:confirmed-d1-d3',
}

describe('VaulterLocalDocxExport & Offline Browser Parity (10.1-B)', () => {
  const originalFetch = globalThis.fetch
  let fetchTrap: ReturnType<typeof vi.fn>

  beforeEach(() => {
    fetchTrap = vi.fn(async () => {
      throw new Error('Network calls are strictly prohibited during local offline DOCX export!')
    })
    globalThis.fetch = fetchTrap as unknown as typeof fetch
  })

  afterEach(() => {
    globalThis.fetch = originalFetch
  })

  it('exports a confirmed version completely offline (fetch trap = 0) with Web Worker and matches Node semantic report', async () => {
    const anchoredComments: AnchoredComment[] = [
      {
        id: 'c-root',
        node_id: 'node_1',
        parent_id: null,
        author: { type: 'user', id: '7' },
        body: 'Verify table merge and link',
        legacy_anchor: null,
        anchor: {
          id: 'anc-1',
          comment_id: 'c-root',
          document_id: 'doc_1',
          generation: 3,
          source_position: 0,
          schema_version: 2,
          block_id: 'd1_p1',
          selector_kind: 'snapshot_utf16',
          selector_payload: { schema: 1, block_id: 'd1_p1', from_utf16: 0, to_utf16: 12 },
          quote_text: 'Plain clause',
          anchor_state: 'attached',
          version: 1,
          etag: '"anc.1"',
          created_at: '2026-11-23T00:00:00Z',
          updated_at: '2026-11-23T00:00:00Z',
        },
        resolved_at: null,
        resolved_by: null,
        version: 0,
        etag: '"c.1"',
        created_at: '2026-11-23T00:00:00Z',
      },
      {
        id: 'c-reply',
        node_id: 'node_1',
        parent_id: 'c-root',
        author: { type: 'user', id: '8' },
        body: 'Confirmed by legal',
        legacy_anchor: null,
        anchor: null,
        resolved_at: null,
        resolved_by: null,
        version: 0,
        etag: '"c.2"',
        created_at: '2026-11-23T00:05:00Z',
      },
    ]

    const sections = [{ ...CORPUS_D2.sections![0], blockCount: CORPUS_D1.document.content!.length }]
    const wrapper = mount(VaulterLocalDocxExport, {
      props: {
        content: shallowReactive({ ...CORPUS_D1.document, content: reactive(CORPUS_D1.document.content!) }),
        revision: CONFIRMED_REVISION,
        discussions: reactive(anchoredComments),
        sections: shallowReactive([{ ...sections[0], headers: reactive(sections[0].headers!) }]),
        title: 'Confirmed Agreement',
        confirmed: true,
        accessible: true,
        enableDiscussions: true,
        workerFactory: createMockWorkerFactory(),
      },
    })

    await flushPromises()
    expect(fetchTrap).not.toHaveBeenCalled()
    expect(wrapper.find('[data-status="confirmed"]').exists()).toBe(true)
    expect(wrapper.find('[data-export-blocked="false"]').exists()).toBe(true)

    // Switch profile to legacy-discussions and verify live preview warning updates
    await wrapper.find('[data-control="profile"]').setValue('legacy-discussions')
    await flushPromises()
    expect(wrapper.find('[data-diagnostic-code="LEGACY_COMMENTS_FLATTENED"]').exists()).toBe(true)

    // Switch back to full discussions profile and export via Web Worker
    await wrapper.find('[data-control="profile"]').setValue('discussions')
    await flushPromises()

    await wrapper.find('[data-action="export-docx"]').trigger('click')
    await flushPromises()

    expect(fetchTrap).toHaveBeenCalledTimes(0)
    const exportedEvents = wrapper.emitted('exported')
    expect(exportedEvents).toHaveLength(1)
    const browserOutput = exportedEvents![0][0] as {
      blob: Blob
      bytes: Uint8Array
      filename: string
      report: import('@vaulter/editor-pro-docx/browser').LossReport
      semantic: import('@vaulter/editor-pro-docx/browser').SemanticReport
    }
    expect(browserOutput.filename).toBe('Confirmed-Agreement.docx')
    expect(browserOutput.bytes.byteLength).toBeGreaterThan(1000)

    // Compare browser semantic report + loss report against direct Node export
    const nodeRes = await exportDocxNode(
      {
        document: CORPUS_D1.document,
        discussions: anchoredComments,
        sections,
        title: 'Confirmed Agreement',
        sourceMarker: {
          nodeId: CONFIRMED_REVISION.node_id,
          documentId: CONFIRMED_REVISION.document_id,
          revisionId: CONFIRMED_REVISION.id,
          generation: CONFIRMED_REVISION.collab_epoch,
          position: CONFIRMED_REVISION.state_version,
          schemaVersion: CONFIRMED_REVISION.schema_version,
          contentHash: CONFIRMED_REVISION.content_hash,
          projection: 'original',
        },
      },
      { profile: 'discussions', projection: 'original' },
    )

    expect(compareSemanticReports(browserOutput.semantic, nodeRes.semantic)).toEqual({
      equal: true,
      differences: [],
    })
    expect(browserOutput.report).toEqual(nodeRes.report)

    // Verify browser-generated bytes import cleanly in both browser and Node
    const importedBack = await importDocxBrowser(browserOutput.bytes, {
      profile: 'discussions',
      zipReader: browserZipReader,
    })
    expect(importedBack.semantic.discussions).toHaveLength(1)
    expect(importedBack.semantic.discussions[0].comments).toHaveLength(2)

    wrapper.unmount()
  })

  it('refuses local export for unconfirmed or access-revoked versions and blocks unsupported strict profiles in UI', async () => {
    const unconfirmed = mount(VaulterLocalDocxExport, {
      props: {
        content: CORPUS_D2.document,
        sections: CORPUS_D2.sections,
        confirmed: false,
        accessible: true,
      },
    })
    await flushPromises()
    expect(unconfirmed.find('[data-status="unavailable"]').text()).toContain('Unconfirmed')
    expect((unconfirmed.find('[data-action="export-docx"]').element as HTMLButtonElement).disabled).toBe(true)
    unconfirmed.unmount()

    const revoked = mount(VaulterLocalDocxExport, {
      props: {
        content: CORPUS_D3.document,
        confirmed: true,
        accessible: false,
      },
    })
    await flushPromises()
    expect(revoked.find('[data-status="unavailable"]').text()).toContain('Access revoked')
    expect((revoked.find('[data-action="export-docx"]').element as HTMLButtonElement).disabled).toBe(true)
    revoked.unmount()

    // Strict profile with native formatting revision (D4) blocks export and shows blocking diagnostics
    const blocked = mount(VaulterLocalDocxExport, {
      props: {
        content: CORPUS_D4.document,
        revision: CONFIRMED_REVISION,
        enableRevisions: true,
        defaultProfile: 'strict-ooxml',
        defaultProjection: 'review',
      },
    })
    await flushPromises()
    expect(blocked.find('[data-export-blocked="true"]').exists()).toBe(true)
    expect(blocked.find('[data-diagnostic-code="NATIVE_FORMATTING_REVISION_UNSUPPORTED"]').exists()).toBe(true)
    expect((blocked.find('[data-action="export-docx"]').element as HTMLButtonElement).disabled).toBe(true)
    expect(fetchTrap).toHaveBeenCalledTimes(0)
    blocked.unmount()

    // Direct browser export parity check on D2
    const bD2 = await exportDocxBrowser(
      { document: CORPUS_D2.document, sections: CORPUS_D2.sections, sourceMarker: CORPUS_D2.sourceMarker },
      { profile: 'standard' },
    )
    const nD2 = await exportDocxNode(
      { document: CORPUS_D2.document, sections: CORPUS_D2.sections, sourceMarker: CORPUS_D2.sourceMarker },
      { profile: 'standard' },
    )
    expect(compareSemanticReports(bD2.semantic, nD2.semantic).equal).toBe(true)
    expect(fetchTrap).toHaveBeenCalledTimes(0)
  })
})
