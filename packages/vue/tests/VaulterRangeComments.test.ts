import { describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import VaulterRangeComments from '../src/components/VaulterRangeComments.vue'
import type { ReviewClient } from '../src/api/reviewClient'
import type { AnchoredComment } from '../src/types/review'

function makeAnchoredComment(overrides: Partial<AnchoredComment> = {}): AnchoredComment {
  return {
    id: 'c1',
    node_id: 'node-1',
    parent_id: null,
    author: { type: 'user', id: '1' },
    body: 'Check amount',
    legacy_anchor: null,
    anchor: {
      id: 'a1',
      comment_id: 'c1',
      document_id: 'd1',
      generation: 1,
      source_position: 10,
      schema_version: 2,
      block_id: 'blk_1',
      selector_kind: 'snapshot_utf16',
      selector_payload: { schema: 1, block_id: 'blk_1', from_utf16: 0, to_utf16: 2 },
      quote_text: '15',
      anchor_state: 'attached',
      version: 1,
      etag: '"comment_anchor.a1.1"',
      created_at: '2026-11-23T00:00:00Z',
      updated_at: '2026-11-23T00:00:00Z',
    },
    resolved_at: null,
    resolved_by: null,
    version: 0,
    etag: '"comment.c1.0"',
    created_at: '2026-11-23T00:00:00Z',
    ...overrides,
  }
}

function makeMockClient(initial: AnchoredComment[]): ReviewClient {
  let store = [...initial]
  return {
    pins: {
      list: vi.fn().mockResolvedValue([]),
      pin: vi.fn(),
      unpin: vi.fn(),
      preview: vi.fn(),
      compare: vi.fn(),
    },
    exchange: {
      register: vi.fn(),
      status: vi.fn(),
      fileUrl: vi.fn().mockReturnValue('/api/vaulter/v1/documents/node-1/exchange-jobs/j1/file'),
      download: vi.fn(),
      apply: vi.fn(),
    },
    comments: {
      list: vi.fn().mockImplementation(async () => ({
        data: store,
        meta: { next_cursor: null, per_page: 50 },
      })),
      add: vi.fn().mockImplementation(async (_node, payload) => {
        const reply: AnchoredComment = {
          id: 'r-' + (store.length + 1),
          node_id: 'node-1',
          parent_id: payload.parent_id ?? null,
          author: { type: 'user', id: '2' },
          body: payload.body,
          legacy_anchor: null,
          anchor: null,
          resolved_at: null,
          resolved_by: null,
          version: 0,
          etag: `"comment.r-${store.length + 1}.0"`,
          created_at: '2026-11-23T00:01:00Z',
        }
        store = [...store, reply]
        return reply
      }),
      resolve: vi.fn().mockImplementation(async (_node, id) => {
        const current = store.find((c) => c.id === id)!
        const updated: AnchoredComment = {
          ...current,
          resolved_at: '2026-11-23T00:02:00Z',
          resolved_by: '1',
          version: current.version + 1,
          etag: `"comment.${id}.${current.version + 1}"`,
        }
        store = store.map((c) => (c.id === id ? updated : c))
        return updated
      }),
      reopen: vi.fn().mockImplementation(async (_node, id) => {
        const current = store.find((c) => c.id === id)!
        const updated: AnchoredComment = {
          ...current,
          resolved_at: null,
          resolved_by: null,
          version: current.version + 1,
          etag: `"comment.${id}.${current.version + 1}"`,
        }
        store = store.map((c) => (c.id === id ? updated : c))
        return updated
      }),
      reanchor: vi.fn(),
      remove: vi.fn(),
    },
  }
}

describe('VaulterRangeComments sidebar', () => {
  it('filters active/resolved/detached, preserves detached replies, indicates actuality, and performs resolve/reopen CAS', async () => {
    const active = makeAnchoredComment({ id: 'c-active', body: 'Active thread', etag: '"comment.c-active.0"' })
    const detached = makeAnchoredComment({
      id: 'c-detached',
      body: 'Detached thread',
      etag: '"comment.c-detached.0"',
      anchor: {
        ...makeAnchoredComment().anchor!,
        id: 'a-detached',
        comment_id: 'c-detached',
        anchor_state: 'detached',
        quote_text: 'deleted phrase',
      },
    })
    const detachedReply: AnchoredComment = {
      id: 'c-reply',
      node_id: 'node-1',
      parent_id: 'c-detached',
      author: { type: 'user', id: '2' },
      body: 'Preserved reply on detached thread',
      legacy_anchor: null,
      anchor: null,
      resolved_at: null,
      resolved_by: null,
      version: 0,
      etag: '"comment.c-reply.0"',
      created_at: '2026-11-23T00:00:30Z',
    }

    const client = makeMockClient([active, detached, detachedReply])

    const wrapper = mount(VaulterRangeComments, {
      props: {
        nodeId: 'node-1',
        actualityContext: { currentGeneration: 1 },
        clientOptions: { client },
      },
    })

    await flushPromises()

    // Counts: 1 active, 0 resolved, 1 detached.
    expect(wrapper.find('[data-filter="active"]').text()).toContain('(1)')
    expect(wrapper.find('[data-filter="resolved"]').text()).toContain('(0)')
    expect(wrapper.find('[data-filter="detached"]').text()).toContain('(1)')

    // Detached tab displays detached root + its preserved reply + Reanchor button!
    await wrapper.find('[data-filter="detached"]').trigger('click')
    expect(wrapper.findAll('.vaulter-range-comments__thread')).toHaveLength(1)
    expect(wrapper.text()).toContain('Preserved reply on detached thread')
    expect(wrapper.find('[data-action="reanchor"]').exists()).toBe(true)

    await wrapper.find('[data-action="reanchor"]').trigger('click')
    expect(wrapper.emitted('requestReanchor')?.[0]?.[0]).toMatchObject({ id: 'c-detached' })

    // Resolve active thread with CAS etag, then switch to resolved tab and reopen.
    await wrapper.find('[data-filter="active"]').trigger('click')
    expect(wrapper.find('[data-comment-id="c-active"] [data-status="current"]').exists()).toBe(true)
    await wrapper.find('[data-comment-id="c-active"] [data-action="resolve"]').trigger('click')
    await flushPromises()
    expect(client.comments.resolve).toHaveBeenCalledWith('node-1', 'c-active', '"comment.c-active.0"', undefined)

    await wrapper.find('[data-filter="resolved"]').trigger('click')
    expect(wrapper.findAll('.vaulter-range-comments__thread')).toHaveLength(1)

    await wrapper.find('[data-comment-id="c-active"] [data-action="reopen"]').trigger('click')
    await flushPromises()
    expect(client.comments.reopen).toHaveBeenCalledWith('node-1', 'c-active', '"comment.c-active.1"', undefined)

    await wrapper.find('[data-filter="active"]').trigger('click')
    expect(wrapper.find('[data-comment-id="c-active"]').exists()).toBe(true)

    // Submit reply via UI form and confirm child reply appears under root thread.
    const replyInput = wrapper.find('[data-comment-id="c-active"] .vaulter-range-comments__reply-input')
    await replyInput.setValue('Follow-up from sidebar')
    await wrapper.find('[data-comment-id="c-active"] .vaulter-range-comments__reply-form').trigger('submit')
    await flushPromises()
    expect(client.comments.add).toHaveBeenCalledWith('node-1', { body: 'Follow-up from sidebar', parent_id: 'c-active', anchor: null }, undefined)
    expect(wrapper.text()).toContain('Follow-up from sidebar')
    expect(wrapper.find('[data-comment-id="c-active"] .vaulter-range-comments__author').text()).toBe('user#1')

    wrapper.unmount()
  })

  it('resolves author names through a prop and replaces the label through the author slot', async () => {
    const comment = makeAnchoredComment({ id: 'c-named', body: 'Named author' })
    const client = makeMockClient([comment])
    const resolved = mount(VaulterRangeComments, {
      props: {
        nodeId: 'node-1',
        actualityContext: { currentGeneration: 1 },
        clientOptions: { client },
        resolveAuthor: (author: { type: string; id: string }) => `Ada ${author.id}`,
      },
    })
    await flushPromises()
    expect(resolved.get('.vaulter-range-comments__author').text()).toBe('Ada 1')

    const slotted = mount(VaulterRangeComments, {
      props: {
        nodeId: 'node-1',
        actualityContext: { currentGeneration: 1 },
        clientOptions: { client },
      },
      slots: {
        author: `<template #author="{ label }"><em data-author-label>{{ label }}</em></template>`,
      },
    })
    await flushPromises()
    expect(slotted.get('[data-author-label]').text()).toBe('user#1')
    expect(slotted.find('.vaulter-range-comments__author').text()).toBe('user#1')
  })

  it('evaluates stale_generation and shifted snapshot actuality indicators', async () => {
    const staleGen = makeAnchoredComment({
      id: 'c-old-gen',
      etag: '"comment.c-old-gen.0"',
      anchor: {
        ...makeAnchoredComment().anchor!,
        id: 'a-old',
        comment_id: 'c-old-gen',
        generation: 0,
        anchor_state: 'attached',
      },
    })
    const client = makeMockClient([staleGen])
    const wrapper = mount(VaulterRangeComments, {
      props: {
        nodeId: 'node-1',
        actualityContext: { currentGeneration: 1 },
        clientOptions: { client },
      },
    })
    await flushPromises()
    expect(wrapper.find('[data-comment-id="c-old-gen"] [data-status="stale_generation"]').exists()).toBe(true)
    expect(wrapper.find('[data-comment-id="c-old-gen"] [data-action="reanchor"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
