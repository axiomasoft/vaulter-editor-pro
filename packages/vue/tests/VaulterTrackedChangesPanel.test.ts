import { describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import VaulterTrackedChangesPanel from '../src/components/VaulterTrackedChangesPanel.vue'
import { useTrackedChanges } from '../src/composables/useTrackedChanges'
import type { ReviewClient } from '../src/api/reviewClient'
import { DEFAULT_REVIEW_CAPABILITIES, type CursorPage, type DocumentSuggestion, type SuggestionCapabilities } from '../src/types/review'

function makeSuggestion(overrides: Partial<DocumentSuggestion> = {}): DocumentSuggestion {
  return {
    id: 's_1',
    group_id: 'g_1',
    proposal_id: null,
    document_id: 'doc_1',
    generation: 0,
    base_position: 1,
    schema_version: 2,
    author_subject: 'user:1',
    kind: 'mark_change',
    target_block_id: 'b_1',
    target_selector: { schema: 1, block_id: 'b_1', from_utf16: 0, to_utf16: 4 },
    payload: { mark_name: 'bold', operation: 'added' },
    from: 0,
    to: 4,
    parent_id: null,
    conflicted: false,
    conflict_reason: null,
    conflict_with_id: null,
    before: '',
    after: '',
    state: 'open',
    version: 1,
    etag: '"suggestion.s_1.1"',
    receipt_id: null,
    decided_by_subject: null,
    decided_at: null,
    pending_commit: false,
    journal_command_id: null,
    journal_state: null,
    committed_position: null,
    ...overrides,
  }
}

function makeMockClient(initial: DocumentSuggestion[], capabilities: SuggestionCapabilities = DEFAULT_REVIEW_CAPABILITIES): ReviewClient {
  let store = [...initial]
  return {
    pins: { list: vi.fn(), pin: vi.fn(), unpin: vi.fn(), preview: vi.fn(), compare: vi.fn() },
    comments: { list: vi.fn(), add: vi.fn(), resolve: vi.fn(), reopen: vi.fn(), reanchor: vi.fn(), remove: vi.fn() },
    exchange: { register: vi.fn(), status: vi.fn(), fileUrl: vi.fn(), download: vi.fn(), apply: vi.fn() },
    suggestions: {
      capabilities: vi.fn().mockResolvedValue(capabilities),
      list: vi.fn().mockImplementation(async () => [...store]),
      save: vi.fn(),
      accept: vi.fn().mockImplementation(async (_node, id) => {
        const current = store.find(s => s.id === id)!
        const updated: DocumentSuggestion = { ...current, state: 'accepted', version: current.version + 1, receipt_id: 'rcpt-1' }
        store = store.map(s => (s.id === id ? updated : s))
        return updated
      }),
      reject: vi.fn().mockImplementation(async (_node, id) => {
        const current = store.find(s => s.id === id)!
        const updated: DocumentSuggestion = { ...current, state: 'rejected', version: current.version + 1, receipt_id: 'rcpt-2' }
        store = store.map(s => {
          if (s.id === id) return updated
          if (s.conflict_with_id === id) {
            return { ...s, version: s.version + 1, conflicted: false, conflict_reason: null, conflict_with_id: null }
          }
          return s
        })
        return updated
      }),
      acceptGroup: vi.fn().mockResolvedValue([]),
      rejectGroup: vi.fn().mockResolvedValue([]),
    },
  }
}

describe('VaulterTrackedChangesPanel', () => {
  it('loads history on demand and keeps an open group across pages actionable', async () => {
    const open = makeSuggestion({ id: 'open' })
    const other = makeSuggestion({ id: 'other' })
    const closed = makeSuggestion({ id: 'closed', state: 'accepted' })
    const client = makeMockClient([])
    const page = vi.fn().mockResolvedValueOnce({ data: [open], meta: { next_cursor: 'first' } })
      .mockResolvedValueOnce({ data: [open, other], meta: { next_cursor: null } })
      .mockResolvedValueOnce({ data: [other, closed], meta: { next_cursor: null } })
    client.suggestions!.page = page
    const wrapper = mount(VaulterTrackedChangesPanel, { props: { nodeId: 'doc_1', clientOptions: { client } } })
    await flushPromises()
    expect(client.suggestions!.list).not.toHaveBeenCalled()
    expect(wrapper.findAll('[data-suggestion-id]')).toHaveLength(2)
    await wrapper.find('[data-group-id="g_1"] .vaulter-tracked-changes__group-accept').trigger('click')
    expect(client.suggestions!.acceptGroup).toHaveBeenCalledWith('doc_1', 'g_1', expect.objectContaining({ expected_versions: { open: 1, other: 1 } }), undefined)
    await wrapper.find('[data-action="more-history"]').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('[data-suggestion-id]')).toHaveLength(3)
    expect(wrapper.find('[data-action="more-history"]').exists()).toBe(false)
  })

  it('does not fetch open pages for a superseded history request', async () => {
    const client = makeMockClient([])
    let finishFirst!: (result: CursorPage<DocumentSuggestion>) => void
    const firstPage = new Promise<CursorPage<DocumentSuggestion>>(resolve => { finishFirst = resolve })
    const page = vi.fn().mockReturnValueOnce(firstPage)
      .mockResolvedValueOnce({ data: [], meta: { next_cursor: null, per_page: 50 } })
    client.suggestions!.page = page
    const changes = useTrackedChanges('doc', { client, pageHistory: true })
    const outdated = changes.list('old')
    await changes.list('current')
    finishFirst({ data: [], meta: { next_cursor: 'unused', per_page: 50 } })
    await outdated
    expect(page).toHaveBeenCalledTimes(2)
    expect(changes.historyCursor.value).toBeNull()
  })

  it('hides unsupported node_type_change UI by capability and enforces conflict rules in actions', async () => {
    const s1 = makeSuggestion({ id: 's1', group_id: 'g1', author_subject: 'user:alice', kind: 'block_split' })
    const s2 = makeSuggestion({
      id: 's2',
      group_id: 'g2',
      author_subject: 'user:bob',
      kind: 'table_change',
      conflicted: true,
      conflict_with_id: 's1',
      conflict_reason: 'overlaps_block_split',
    })
    const client = makeMockClient([s1, s2])

    const wrapper = mount(VaulterTrackedChangesPanel, {
      props: {
        nodeId: 'doc_1',
        clientOptions: { client },
      },
    })
    await flushPromises()

    // v1 hides node_type_change UI because node_type_change_types is empty
    expect(wrapper.find('[data-capability-node-type-change]').exists()).toBe(false)
    expect(wrapper.find('[data-capability-table-mode="text_only"]').exists()).toBe(true)
    expect(wrapper.find('[data-capability-kind="block_split"]').exists()).toBe(true)

    // Preserves author_subject on conflicted suggestion and disables Accept while keeping Reject enabled
    const row2 = wrapper.find('[data-suggestion-id="s2"]')
    expect(row2.attributes('data-author')).toBe('user:bob')
    expect(row2.attributes('data-conflicted')).toBe('true')
    const s2AcceptBtn = row2.find('.vaulter-tracked-changes__accept')
    const s2RejectBtn = row2.find('.vaulter-tracked-changes__reject')
    expect((s2AcceptBtn.element as HTMLButtonElement).disabled).toBe(true)
    expect((s2RejectBtn.element as HTMLButtonElement).disabled).toBe(false)

    // Group g2 accept button is also disabled due to conflict
    const g2AcceptBtn = wrapper.find('[data-group-id="g2"] .vaulter-tracked-changes__group-accept')
    expect((g2AcceptBtn.element as HTMLButtonElement).disabled).toBe(true)

    // Rejecting s1 clears conflict on s2 and enables s2 Accept button
    await wrapper.find('[data-suggestion-id="s1"] .vaulter-tracked-changes__reject').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-suggestion-id="s2"]').attributes('data-conflicted')).toBe('false')
    expect((wrapper.find('[data-suggestion-id="s2"] .vaulter-tracked-changes__accept').element as HTMLButtonElement).disabled).toBe(false)
    expect(client.suggestions?.list).toHaveBeenCalledTimes(2)
    expect(client.suggestions?.reject).toHaveBeenCalledWith('doc_1', 's1', { generation: 0, expected_version: 1, command_id: null }, undefined)
    await wrapper.find('[data-suggestion-id="s2"] .vaulter-tracked-changes__accept').trigger('click')
    await flushPromises()
    expect(client.suggestions?.accept).toHaveBeenCalledWith('doc_1', 's2', { generation: 0, expected_version: 2, command_id: null }, undefined)

    // Switching projection emits projectionChange
    await wrapper.find('[data-projection="original"]').trigger('click')
    expect(wrapper.emitted('projectionChange')?.[0]).toEqual(['original'])
  })

  it('retains generation zero for both group decisions', async () => {
    const item = makeSuggestion()
    const client = makeMockClient([item])
    const wrapper = mount(VaulterTrackedChangesPanel, {
      props: { nodeId: 'doc_1', autoLoad: false, initialSuggestions: [item], clientOptions: { client } },
    })
    await wrapper.find('[data-group-id="g_1"] .vaulter-tracked-changes__group-accept').trigger('click')
    await wrapper.find('[data-group-id="g_1"] .vaulter-tracked-changes__group-reject').trigger('click')
    const payload = { generation: 0, expected_versions: { s_1: 1 }, command_id: null }
    expect(client.suggestions?.acceptGroup).toHaveBeenCalledWith('doc_1', 'g_1', payload, undefined)
    expect(client.suggestions?.rejectGroup).toHaveBeenCalledWith('doc_1', 'g_1', payload, undefined)
  })

  it('hides table capability chip when capabilities disallow table_change and shows pending_commit state', async () => {
    const customCaps: SuggestionCapabilities = {
      ...DEFAULT_REVIEW_CAPABILITIES,
      supported_kinds: ['insert', 'delete', 'replace', 'mark_change'],
      structural_kinds: ['mark_change'],
      node_type_change_types: ['paragraph->heading'],
    }
    const pendingItem = makeSuggestion({
      id: 'sp',
      pending_commit: true,
      journal_command_id: 'cmd-99',
      journal_state: 'pending',
    })
    const client = makeMockClient([pendingItem], customCaps)

    const wrapper = mount(VaulterTrackedChangesPanel, {
      props: {
        nodeId: 'doc_1',
        clientOptions: { client },
      },
    })
    await flushPromises()

    expect(wrapper.find('[data-capability-table-mode]').exists()).toBe(false)
    expect(wrapper.find('[data-capability-node-type-change="enabled"]').exists()).toBe(true)
    expect(wrapper.find('[data-pending-commit="true"]').exists()).toBe(true)
    expect((wrapper.find('[data-suggestion-id="sp"] .vaulter-tracked-changes__accept').element as HTMLButtonElement).disabled).toBe(true)
    expect((wrapper.find('[data-suggestion-id="sp"] .vaulter-tracked-changes__reject').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('resolves author_subject through a prop and the author slot without changing data-author', async () => {
    const item = makeSuggestion({ id: 's-named', author_subject: 'user:ada' })
    const client = makeMockClient([item])
    const resolved = mount(VaulterTrackedChangesPanel, {
      props: {
        nodeId: 'doc_1',
        clientOptions: { client },
        resolveAuthor: (authorSubject: string) => authorSubject.replace('user:', 'Name '),
      },
    })
    await flushPromises()
    const row = resolved.get('[data-suggestion-id="s-named"]')
    expect(row.attributes('data-author')).toBe('user:ada')
    expect(row.get('.vaulter-tracked-changes__author').text()).toBe('Name ada')

    const slotted = mount(VaulterTrackedChangesPanel, {
      props: {
        nodeId: 'doc_1',
        autoLoad: false,
        initialSuggestions: [item],
        clientOptions: { client },
      },
      slots: {
        author: `<template #author="{ label }"><em data-author-label>{{ label }}</em></template>`,
      },
    })
    await flushPromises()
    expect(slotted.get('[data-author-label]').text()).toBe('user:ada')
    expect(slotted.get('[data-suggestion-id="s-named"]').attributes('data-author')).toBe('user:ada')
  })

  it('supports bulk accept/reject, proposal_id filter, generation_changed section, and live overlayEvents', async () => {
    const s1 = makeSuggestion({ id: 's-1', proposal_id: 'prop-1', author_subject: 'user:1' })
    const s2 = makeSuggestion({ id: 's-2', proposal_id: 'prop-2', author_subject: 'user:2' })
    const stale = makeSuggestion({ id: 's-stale', generation_changed: true, author_subject: 'user:1' })

    let overlayHandler: ((e: { kind: 'review' | 'comments' }) => void) | null = null
    const overlay = {
      overlayEvents: (fn: (e: { kind: 'review' | 'comments' }) => void) => {
        overlayHandler = fn
        return () => { overlayHandler = null }
      },
      invalidateOverlay: vi.fn(),
    }

    const client = makeMockClient([s1, s2, stale])
    client.suggestions!.bulkAccept = vi.fn().mockResolvedValue([{ ...s1, state: 'accepted' }])
    client.suggestions!.bulkReject = vi.fn().mockResolvedValue([{ ...s2, state: 'rejected' }])
    client.suggestions!.reject = vi.fn().mockResolvedValue({ ...stale, state: 'rejected' })

    const wrapper = mount(VaulterTrackedChangesPanel, {
      props: {
        nodeId: 'doc_1',
        generation: 1,
        selectionRange: { from: 0, to: 10 },
        includeStaleGenerations: true,
        clientOptions: { client, overlay },
      },
    })
    await flushPromises()

    expect(wrapper.find('[data-proposal-id="prop-1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="stale-generation-suggestions"] [data-suggestion-id="s-stale"]').exists()).toBe(true)

    await wrapper.get('[data-testid="proposal-filter"]').setValue('prop-1')
    expect(wrapper.findAll('.vaulter-tracked-changes__item')).toHaveLength(1)

    await wrapper.get('[data-action="accept-all"]').trigger('click')
    await flushPromises()
    expect(client.suggestions!.bulkAccept).toHaveBeenCalledWith('doc_1', { generation: 1, scope: 'all', command_id: null }, undefined)
    expect(overlay.invalidateOverlay).toHaveBeenCalledWith('review')

    await wrapper.get('[data-action="accept-in-range"]').trigger('click')
    await flushPromises()
    expect(client.suggestions!.bulkAccept).toHaveBeenCalledWith('doc_1', { generation: 1, scope: 'in_range', from: 0, to: 10, command_id: null }, undefined)

    await wrapper.get('[data-action="reject-stale"]').trigger('click')
    await flushPromises()
    expect(client.suggestions!.reject).toHaveBeenCalledTimes(1)

    const listCallsBefore = (client.suggestions!.list as ReturnType<typeof vi.fn>).mock.calls.length
    ;(overlayHandler as ((e: { kind: 'review' | 'comments' }) => void) | null)?.({ kind: 'review' })
    await flushPromises()
    expect((client.suggestions!.list as ReturnType<typeof vi.fn>).mock.calls.length).toBeGreaterThan(listCallsBefore)

    wrapper.unmount()
  })
})
