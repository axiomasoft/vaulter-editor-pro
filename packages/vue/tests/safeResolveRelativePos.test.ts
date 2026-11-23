import { describe, expect, it, vi } from 'vitest'
import * as Y from 'yjs'
import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import {
  ySyncPlugin,
  ySyncPluginKey,
  prosemirrorToYXmlFragment,
} from '@tiptap/y-tiptap'
import { BlockId } from '@vaulter/vue'
import {
  buildYjsRelativePayload,
  decodeRelativePositionBase64,
  resolveYjsRangeAnchor,
  safeResolveRelativePos,
} from '../src/yjs/safeResolveRelativePos'

function setupCollabEditor(initialJson: Record<string, unknown>) {
  const ydoc = new Y.Doc()
  const fragment = ydoc.getXmlFragment('default')

  const baseEditor = new Editor({
    extensions: [StarterKit, BlockId],
    content: initialJson,
  })
  prosemirrorToYXmlFragment(baseEditor.state.doc, fragment)
  baseEditor.destroy()

  const editor = new Editor({
    extensions: [
      StarterKit,
      BlockId,
    ],
  })
  const plugin = ySyncPlugin(fragment)
  editor.registerPlugin(plugin)

  const getMapping = (): Map<unknown, unknown> => {
    const syncState = ySyncPluginKey.getState(editor.state)
    if (!syncState) {
      throw new Error('Missing ySyncPlugin state')
    }
    return syncState.binding.mapping as unknown as Map<unknown, unknown>
  }

  return { editor, ydoc, fragment, getMapping }
}

describe('safeResolveRelativePos & Yjs range anchor lifecycle (COMMENTS / #8614)', () => {
  it('resolves "Xhello" after deleting "X" to pos === 1 even when upstream returns null (#8614)', () => {
    const { editor, ydoc, fragment, getMapping } = setupCollabEditor({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_first' },
          content: [{ type: 'text', text: 'Xhello world' }],
        },
      ],
    })

    // Highlight "hello": pos 2..7 inside paragraph ("X" is pos 1..2, "hello" is pos 2..7).
    const payload = buildYjsRelativePayload(ydoc, fragment, 2, 7, 'blk_first', getMapping())
    expect(payload).not.toBeNull()
    expect(payload!.start.assoc).toBe(0)
    expect(payload!.end.assoc).toBe(-1)

    // Delete leading "X" (pos 1..2). Now "hello" starts right at document pos === 1!
    editor.chain().deleteRange({ from: 1, to: 2 }).run()
    expect(editor.state.doc.textBetween(1, 6)).toBe('hello')

    // 1. Live @tiptap/y-tiptap resolution:
    const liveRes = resolveYjsRangeAnchor(
      ydoc,
      fragment,
      payload!,
      getMapping(),
      (from, to) => editor.state.doc.textBetween(from, to),
    )
    expect(liveRes.state).toBe('attached')
    expect(liveRes.from).toBe(1)
    expect(liveRes.to).toBe(6)
    expect(liveRes.quoteText).toBe('hello')

    // 2. Simulated #8614 upstream null return on pos === 1 -> fallback MUST recover pos = 1!
    const startRel = decodeRelativePositionBase64(payload!.start.rel)
    const resolveMock = vi.fn().mockReturnValueOnce(null)
    const safeStart = safeResolveRelativePos(ydoc, fragment, startRel, getMapping(), {
      resolveFn: resolveMock,
    })
    expect(resolveMock).toHaveBeenCalledTimes(1)
    expect(safeStart).toBe(1)

    editor.destroy()
    ydoc.destroy()
  })

  it('prevents boundary expansion when typing adjacent outside left (assoc=0) and right (assoc=-1)', () => {
    const { editor, ydoc, fragment, getMapping } = setupCollabEditor({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_edge' },
          content: [{ type: 'text', text: 'alpha target omega' }],
        },
      ],
    })

    // "alpha " = 6 chars -> "target" is pos 7..13.
    expect(editor.state.doc.textBetween(7, 13)).toBe('target')
    const payload = buildYjsRelativePayload(ydoc, fragment, 7, 13, 'blk_edge', getMapping())!
    expect(payload.start.assoc).toBe(0)
    expect(payload.end.assoc).toBe(-1)

    // Type "PRE" strictly at start edge (pos 7) -> because start.assoc = 0, range shifts to stay "target"!
    editor.chain().insertContentAt(7, 'PRE').run()
    const afterPre = resolveYjsRangeAnchor(
      ydoc,
      fragment,
      payload,
      getMapping(),
      (f, t) => editor.state.doc.textBetween(f, t),
    )
    expect(afterPre.state).toBe('attached')
    expect(afterPre.from).toBe(10)
    expect(afterPre.to).toBe(16)
    expect(afterPre.quoteText).toBe('target')

    // Type "POST" strictly at right edge (pos 16) -> because end.assoc = -1, range does NOT expand!
    editor.chain().insertContentAt(16, 'POST').run()
    const afterPost = resolveYjsRangeAnchor(
      ydoc,
      fragment,
      payload,
      getMapping(),
      (f, t) => editor.state.doc.textBetween(f, t),
    )
    expect(afterPost.state).toBe('attached')
    expect(afterPost.from).toBe(10)
    expect(afterPost.to).toBe(16)
    expect(afterPost.quoteText).toBe('target')

    editor.destroy()
    ydoc.destroy()
  })

  it('narrows on partial deletion and detaches when full range or block is deleted (never false-positive pos 1)', () => {
    const { editor, ydoc, fragment, getMapping } = setupCollabEditor({
      type: 'doc',
      content: [
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_one' },
          content: [{ type: 'text', text: 'first' }],
        },
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_two' },
          content: [{ type: 'text', text: 'second' }],
        },
      ],
    })

    // "first": pos 1..6. "second": opening tag at 7..8 -> text at 8..14.
    expect(editor.state.doc.textBetween(8, 14)).toBe('second')
    const secondPayload = buildYjsRelativePayload(ydoc, fragment, 8, 14, 'blk_two', getMapping())!

    // Deleting inside non-first block does NOT fall back to pos=1 even if resolveFn returns null!
    const secondStartRel = decodeRelativePositionBase64(secondPayload.start.rel)
    expect(
      safeResolveRelativePos(ydoc, fragment, secondStartRel, getMapping(), {
        resolveFn: () => null,
      }),
    ).toBeNull()

    // Partial delete: delete "sec" (pos 8..11) -> narrows cleanly to "ond".
    editor.chain().deleteRange({ from: 8, to: 11 }).run()
    const partial = resolveYjsRangeAnchor(
      ydoc,
      fragment,
      secondPayload,
      getMapping(),
      (f, t) => editor.state.doc.textBetween(f, t),
    )
    expect(partial.state).toBe('attached')
    expect(partial.quoteText).toBe('ond')

    // Full delete: delete remaining "ond" (pos 8..11) -> detached (left >= right / deleted item).
    editor.chain().deleteRange({ from: 8, to: 11 }).run()
    const fullDel = resolveYjsRangeAnchor(
      ydoc,
      fragment,
      secondPayload,
      getMapping(),
      (f, t) => editor.state.doc.textBetween(f, t),
    )
    expect(fullDel.state).toBe('detached')

    // Now test deleting first block completely:
    // 1. ProseMirror deleteRange over first block leaves range zero-length (`detached`):
    const firstPayload = buildYjsRelativePayload(ydoc, fragment, 1, 6, 'blk_one', getMapping())!
    editor.chain().deleteRange({ from: 1, to: 6 }).run()
    expect(resolveYjsRangeAnchor(ydoc, fragment, firstPayload, getMapping()).state).toBe('detached')

    // 2. Deleting the first Y.XmlElement itself in Yjs -> decoded.type._item.deleted === true -> safeResolveRelativePos strictly returns null (never false-positive pos 1)!
    fragment.delete(0, 1)
    const firstStartRel = decodeRelativePositionBase64(firstPayload.start.rel)
    expect(safeResolveRelativePos(ydoc, fragment, firstStartRel, getMapping())).toBeNull()
    expect(resolveYjsRangeAnchor(ydoc, fragment, firstPayload, getMapping()).state).toBe('detached')

    editor.destroy()
    ydoc.destroy()
  })
})
