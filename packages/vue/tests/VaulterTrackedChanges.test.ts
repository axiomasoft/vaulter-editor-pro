import { Editor, Extension } from '@tiptap/core'
import { TextSelection } from '@tiptap/pm/state'
import { redo, undo } from '@tiptap/pm/history'
import StarterKit from '@tiptap/starter-kit'
import { yDocToProsemirrorJSON, ySyncPlugin, ySyncPluginKey, yUndoPlugin, yUndoPluginKey } from '@tiptap/y-tiptap'
import { afterEach, expect, it, vi } from 'vitest'
import * as Y from 'yjs'
import { applySuggestionDecision, projectTrackedChanges, VaulterTrackedChanges } from '../src'
import type { TrackedTextSuggestion } from '../src'

const editors: Editor[] = []
const documents: Y.Doc[] = []

afterEach(() => {
  editors.splice(0).forEach(editor => editor.destroy())
  documents.splice(0).forEach(doc => doc.destroy())
  vi.unstubAllGlobals()
})

const BlockId = Extension.create({
  name: 'testBlockId',
  addGlobalAttributes() {
    return [{ types: ['paragraph'], attributes: { blockId: { default: 'text-block' } } }]
  },
})

function openEditor(author: string, content = 'red 🦊 blue', ydoc?: Y.Doc) {
  const onSuggestions = vi.fn<(suggestions: readonly TrackedTextSuggestion[]) => void>()
  const collaboration = ydoc
    ? [Extension.create({
        name: 'testCollaboration',
        addProseMirrorPlugins: () => [ySyncPlugin(ydoc.getXmlFragment('default')), yUndoPlugin()],
      })]
    : []
  const editor = new Editor({
    element: document.createElement('div'),
    extensions: [
      StarterKit.configure({ undoRedo: ydoc ? false : undefined }),
      BlockId,
      VaulterTrackedChanges.configure({ author: () => author, onSuggestions }),
      ...collaboration,
    ],
    content: `<p>${content}</p>`,
  })
  editors.push(editor)
  return { editor, onSuggestions }
}

function replace(editor: Editor, from: number, to: number, value: string) {
  editor.view.dispatch(editor.state.tr.insertText(value, from, to))
}

it('keeps text in three pure projections and selects replacement pairs as one suggestion', () => {
  const { editor, onSuggestions } = openEditor('one')
  replace(editor, 1, 4, 'green')
  const [[draft]] = onSuggestions.mock.calls[0]
  expect(draft).toMatchObject({ blockId: 'text-block', kind: 'replace', before: 'red', after: 'green', from: 0, to: 3 })
  expect(projectTrackedChanges(editor.state.doc, 'original').textContent).toBe('red 🦊 blue')
  expect(projectTrackedChanges(editor.state.doc, 'review')).toBe(editor.state.doc)
  expect(projectTrackedChanges(editor.state.doc, 'proposed', { groups: [draft.groupId], suggestions: [draft], ids: [] }).textContent)
    .toBe('green 🦊 blue')
  expect(projectTrackedChanges(editor.state.doc, 'proposed', { ids: [] }).textContent).toBe('red 🦊 blue')
  expect(editor.state.doc.textContent).toBe('redgreen 🦊 blue')
})

it('groups only adjacent edits of the same author and keeps parents when rejecting a child', () => {
  const ydoc = new Y.Doc()
  documents.push(ydoc)
  const first = openEditor('one', 'red 🦊 blue', ydoc)
  // Initialize the shared document explicitly without creating suggestions.
  first.editor.view.dispatch(first.editor.state.tr.insertText('red 🦊 blue', 1, first.editor.state.doc.content.size - 1)
    .setMeta('vaulter:untracked', true))
  const end = first.editor.state.doc.content.size - 1
  replace(first.editor, end, end, ' bag')
  replace(first.editor, end + 4, end + 4, 's')
  const [[outer], [extended]] = first.onSuggestions.mock.calls.map(call => call[0])
  expect(extended).toMatchObject({ suggestionId: outer.suggestionId, after: ' bags' })
  replace(first.editor, 1, 1, '!')
  expect(first.onSuggestions.mock.lastCall?.[0][0].suggestionId).not.toBe(outer.suggestionId)

  const remoteDoc = new Y.Doc()
  documents.push(remoteDoc)
  Y.applyUpdate(remoteDoc, Y.encodeStateAsUpdate(ydoc))
  ydoc.on('update', bytes => Y.applyUpdate(remoteDoc, bytes))
  remoteDoc.on('update', bytes => Y.applyUpdate(ydoc, bytes))
  const second = openEditor('two', '', remoteDoc)
  expect(second.onSuggestions).not.toHaveBeenCalled()
  replace(second.editor, end + 2, end + 3, 'X')
  const [[child]] = second.onSuggestions.mock.calls[0]
  expect(child.parentId).toBe(outer.suggestionId)
  expect(child).toMatchObject({ from: 1, to: 2, before: 'b', after: 'X' })
  expect(projectTrackedChanges(second.editor.state.doc, 'original').textContent).toBe('red 🦊 blue')
  expect(applySuggestionDecision(second.editor, 'reject', { ids: [child.suggestionId] })).toBe(true)
  expect(projectTrackedChanges(second.editor.state.doc, 'proposed').textContent).toBe('!red 🦊 blue bags')
  replace(second.editor, end + 2, end + 3, 'Z')
  expect(applySuggestionDecision(second.editor, 'reject', { ids: [outer.suggestionId] })).toBe(true)
  expect(projectTrackedChanges(second.editor.state.doc, 'proposed').textContent).toBe('!red 🦊 blue')
  expect(first.onSuggestions).toHaveBeenCalledTimes(3)
})

it.each([
  { from: 2, to: 3, before: 'X', after: '', proposed: 'aYZb' },
  { from: 4, to: 5, before: 'Z', after: '', proposed: 'aXYb' },
  { from: 2, to: 5, before: 'XYZ', after: '', proposed: 'ab' },
  { from: 4, to: 5, before: 'Z', after: '!', proposed: 'aXY!b' },
])('tracks an edit of "$before" to "$after" at a pending insertion boundary', ({ from, to, before, after, proposed }) => {
  const { editor, onSuggestions } = openEditor('one', 'ab')
  replace(editor, 2, 2, 'XYZ')
  const parent = onSuggestions.mock.lastCall?.[0][0]
  replace(editor, from, to, after)
  expect(onSuggestions).toHaveBeenCalledTimes(2)
  expect(onSuggestions.mock.lastCall?.[0][0]).toMatchObject({
    parentId: parent?.suggestionId,
    kind: after ? 'replace' : 'delete',
    from: from - 2,
    to: to - 2,
    before,
    after,
  })
  expect(projectTrackedChanges(editor.state.doc, 'original').textContent).toBe('ab')
  expect(projectTrackedChanges(editor.state.doc, 'proposed').textContent).toBe(proposed)
})

it('leaves a deletion spanning original and pending text unsupported', () => {
  const { editor, onSuggestions } = openEditor('one', 'ab')
  replace(editor, 2, 2, 'XYZ')
  const review = editor.state.doc
  replace(editor, 1, 3, '')
  expect(editor.state.doc).toBe(review)
  expect(onSuggestions).toHaveBeenCalledOnce()
})

it('publishes no raw IME or local text deltas, ignores replay, and delegates Yjs undo', () => {
  const ydoc = new Y.Doc()
  documents.push(ydoc)
  const { editor, onSuggestions } = openEditor('one', '', ydoc)
  editor.view.dispatch(editor.state.tr.insertText('word', 1, 1).setMeta('vaulter:untracked', true))
  yUndoPluginKey.getState(editor.state)?.undoManager.clear()
  const updates: unknown[] = []
  ydoc.on('update', () => updates.push(yDocToProsemirrorJSON(ydoc, 'default')))
  let frame: FrameRequestCallback | undefined
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    frame = callback
    return 1
  })
  editor.view.dom.dispatchEvent(new Event('compositionstart'))
  replace(editor, 2, 2, 'k')
  editor.view.dispatch(editor.state.tr.setSelection(TextSelection.create(editor.state.doc, 3)))
  replace(editor, 2, 3, 'か')
  expect(editor.state.doc.textContent).toBe('wかord')
  expect(updates).toEqual([])
  editor.view.dom.dispatchEvent(new Event('compositionend'))
  frame?.(0)
  expect(updates).toHaveLength(1)
  expect(JSON.stringify(updates[0])).toContain('reviewSuggestion')
  expect(onSuggestions).toHaveBeenCalledOnce()
  expect(projectTrackedChanges(editor.state.doc, 'original').textContent).toBe('word')
  expect(projectTrackedChanges(editor.state.doc, 'proposed').textContent).toBe('wかord')

  yUndoPluginKey.getState(editor.state)?.undoManager.undo()
  expect(editor.state.doc.textContent).toBe('word')
  expect(onSuggestions).toHaveBeenCalledOnce()
})

it('bypasses explicit keys and preserves ordinary ProseMirror history', () => {
  const { editor, onSuggestions } = openEditor('one', 'a🦊z')
  for (const key of [ySyncPluginKey, yUndoPluginKey, 'y-undo', 'history', 'vaulter:untracked']) {
    editor.view.dispatch(editor.state.tr.insertText('!', 1, 1).setMeta(key, false).setMeta('addToHistory', false))
  }
  expect(onSuggestions).not.toHaveBeenCalled()
  replace(editor, 7, 9, '')
  expect(projectTrackedChanges(editor.state.doc, 'proposed').textContent).toBe('!!!!!az')
  expect(undo(editor.state, editor.view.dispatch)).toBe(true)
  expect(projectTrackedChanges(editor.state.doc, 'proposed').textContent).toBe('!!!!!a🦊z')
  expect(redo(editor.state, editor.view.dispatch)).toBe(true)
  expect(projectTrackedChanges(editor.state.doc, 'proposed').textContent).toBe('!!!!!az')
  expect(onSuggestions).toHaveBeenCalledOnce()
})

it('tracks a composition replacement whose characters share a UTF-16 prefix', () => {
  const { editor, onSuggestions } = openEditor('one', 'a😀z')
  let frame: FrameRequestCallback | undefined
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    frame = callback
    return 1
  })
  editor.view.dom.dispatchEvent(new Event('compositionstart'))
  replace(editor, 2, 4, '😁')
  editor.view.dom.dispatchEvent(new Event('compositionend'))
  frame?.(0)
  expect(onSuggestions.mock.lastCall?.[0][0]).toMatchObject({ from: 1, to: 3, before: '😀', after: '😁' })
  expect(projectTrackedChanges(editor.state.doc, 'original').textContent).toBe('a😀z')
  expect(projectTrackedChanges(editor.state.doc, 'proposed').textContent).toBe('a😁z')
})

it('tracks multiple mapped steps and keeps a deletion caret before the retained text', () => {
  const { editor, onSuggestions } = openEditor('one', 'abcdef')
  const raw = editor.state.tr.insertText('R', 2, 4).insertText('S', 2, 2)
  editor.view.dispatch(raw)
  expect(projectTrackedChanges(editor.state.doc, 'proposed').textContent).toBe('aSRdef')
  expect(onSuggestions.mock.lastCall?.[0].map(change => change.kind)).toEqual(['replace', 'insert'])
  const deletion = editor.state.tr.delete(1, 2)
  deletion.setSelection(TextSelection.create(deletion.doc, 1))
  editor.view.dispatch(deletion)
  expect(editor.state.selection.from).toBe(1)
})
