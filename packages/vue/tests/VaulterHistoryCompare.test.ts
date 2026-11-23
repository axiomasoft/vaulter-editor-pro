import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import * as Y from 'yjs'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { ySyncPlugin, prosemirrorToYXmlFragment } from '@tiptap/y-tiptap'
import { BlockId } from '@vaulter/vue'
import VaulterHistoryCompare from '../src/components/VaulterHistoryCompare.vue'

describe('VaulterHistoryCompare (REVIEW-HISTORY)', () => {
  it('previews a rich-text image through the default schema', () => {
    const wrapper = mount(VaulterHistoryCompare, {
      props: {
        mode: 'preview',
        fromContent: {
          type: 'doc',
          content: [
            { type: 'image', attrs: { src: 'https://vaulter.test/revision.png', alt: 'Revision image', width: 240 } },
            { type: 'image', attrs: { src: 'data:image/png;base64,AA==' } },
          ],
        },
      },
    })

    expect(wrapper.findAll('img')).toHaveLength(1)
    expect(wrapper.get('img').attributes()).toMatchObject({
      src: 'https://vaulter.test/revision.png', alt: 'Revision image', width: '240',
    })
    wrapper.unmount()
  })

  it('renders isolated read-only structural compare with marks & attrs while emitting zero live updates', () => {
    const versionH = {
      type: 'doc',
      content: [
        { type: 'heading', attrs: { blockId: 'blk_h', level: 1 }, content: [{ type: 'text', text: 'Clause' }] },
        { type: 'paragraph', attrs: { blockId: 'blk_p' }, content: [{ type: 'text', text: 'Pay 15 USD' }] },
      ],
    }

    const versionHPlus1 = {
      type: 'doc',
      content: [
        { type: 'heading', attrs: { blockId: 'blk_h', level: 2 }, content: [{ type: 'text', text: 'Clause' }] },
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_p' },
          content: [
            { type: 'text', text: 'Pay ' },
            { type: 'text', text: '15', marks: [{ type: 'bold' }] },
            { type: 'text', text: ' USD today' },
          ],
        },
      ],
    }

    // Separate active live editor on H+1 connected to Y.Doc:
    const ydoc = new Y.Doc()
    const fragment = ydoc.getXmlFragment('default')
    const seed = new Editor({ extensions: [StarterKit, BlockId], content: versionHPlus1 })
    prosemirrorToYXmlFragment(seed.state.doc, fragment)
    seed.destroy()

    const liveOnUpdate = vi.fn()
    const ydocOnUpdate = vi.fn()
    ydoc.on('update', ydocOnUpdate)

    const liveEditor = new Editor({
      extensions: [StarterKit, BlockId],
      onUpdate: liveOnUpdate,
    })
    liveEditor.registerPlugin(ySyncPlugin(fragment))
    liveOnUpdate.mockClear()
    ydocOnUpdate.mockClear()

    // Mount isolated compare component over H vs H+1.
    const wrapper = mount(VaulterHistoryCompare, {
      props: {
        fromContent: versionH,
        toContent: versionHPlus1,
        mode: 'compare',
      },
    })

    const vm = wrapper.vm as unknown as {
      view: import('@tiptap/pm/view').EditorView | null
      insertCount: number
      markChangeCount: number
      attrChangeCount: number
    }

    expect(vm.view).not.toBeNull()
    expect(vm.view!.editable).toBe(false)
    expect(vm.insertCount).toBeGreaterThanOrEqual(1)
    expect(vm.markChangeCount).toBeGreaterThanOrEqual(1)
    expect(vm.attrChangeCount).toBeGreaterThanOrEqual(1)

    expect(wrapper.find('[data-diff-kind="attr_change"]').exists()).toBe(true)
    expect(wrapper.find('[data-diff-kind="mark_change"]').exists()).toBe(true)
    expect(wrapper.find('[data-diff-kind="insert"]').exists()).toBe(true)

    // Attempting to dispatch a transaction inside the read-only isolated view must be a no-op!
    const beforeSize = vm.view!.state.doc.content.size
    vm.view!.dispatch(vm.view!.state.tr.insertText('HACK', 1))
    expect(vm.view!.state.doc.content.size).toBe(beforeSize)

    // Strictly ZERO updates propagated to live editor or Y.Doc!
    expect(liveOnUpdate).not.toHaveBeenCalled()
    expect(ydocOnUpdate).not.toHaveBeenCalled()
    expect(liveEditor.getText()).toContain('Pay 15 USD today')

    wrapper.unmount()
    expect(liveOnUpdate).not.toHaveBeenCalled()
    expect(ydocOnUpdate).not.toHaveBeenCalled()

    liveEditor.destroy()
    ydoc.destroy()
  })

  it('supports isolated preview mode, scrubs unsafe javascript URLs, and highlights table cell attr diffs', () => {
    const legacyUnsafeDoc = {
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_link' },
          content: [
            {
              type: 'text',
              text: 'unsafe link',
              marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }],
            },
            {
              type: 'text',
              text: ' safe link',
              marks: [{ type: 'link', attrs: { href: 'https://vaulter.test/docs' } }],
            },
          ],
        },
        {
          type: 'table',
          attrs: { blockId: 'blk_tbl' },
          content: [
            {
              type: 'tableRow',
              content: [
                {
                  type: 'tableCell',
                  attrs: { colwidth: [100], align: 'left' },
                  content: [{ type: 'paragraph', content: [{ type: 'text', text: 'cell' }] }],
                },
              ],
            },
          ],
        },
      ],
    }

    const previewWrapper = mount(VaulterHistoryCompare, {
      props: {
        fromContent: legacyUnsafeDoc,
        mode: 'preview',
      },
    })

    const previewHtml = previewWrapper.html()
    expect(previewHtml).not.toContain('javascript:')
    expect(previewHtml).toContain('https://vaulter.test/docs')
    expect((previewWrapper.vm as unknown as { view: { editable: boolean } }).view.editable).toBe(false)
    previewWrapper.unmount()

    const resizedTableDoc = {
      type: 'doc',
      content: [
        legacyUnsafeDoc.content[0],
        {
          type: 'table',
          attrs: { blockId: 'blk_tbl' },
          content: [
            {
              type: 'tableRow',
              content: [
                {
                  type: 'tableCell',
                  attrs: { colwidth: [240], align: 'right' },
                  content: [{ type: 'paragraph', content: [{ type: 'text', text: 'cell' }] }],
                },
              ],
            },
          ],
        },
      ],
    }

    const compareWrapper = mount(VaulterHistoryCompare, {
      props: {
        fromContent: legacyUnsafeDoc,
        toContent: resizedTableDoc,
        mode: 'compare',
      },
    })
    const vm = compareWrapper.vm as unknown as { attrChangeCount: number }
    expect(vm.attrChangeCount).toBe(2)
    expect(compareWrapper.find('[data-diff-kind="attr_change"][data-attr="colwidth"]').exists()).toBe(true)
    expect(compareWrapper.find('[data-diff-kind="attr_change"][data-attr="align"]').exists()).toBe(true)
    compareWrapper.unmount()
  })
})
