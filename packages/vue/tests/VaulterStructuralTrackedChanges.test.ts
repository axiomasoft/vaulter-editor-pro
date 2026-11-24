import { Editor, Extension } from '@tiptap/core'
import { Node as ProseMirrorNode } from '@tiptap/pm/model'
import { TableKit } from '@tiptap/extension-table'
import StarterKit from '@tiptap/starter-kit'
import { ySyncPlugin, yUndoPlugin } from '@tiptap/y-tiptap'
import { afterEach, expect, it, vi } from 'vitest'
import * as Y from 'yjs'
import {
  applySuggestionDecision,
  DEFAULT_REVIEW_CAPABILITIES,
  projectTrackedChanges,
  REVIEW_TRACKED_PLUGIN_KEY,
  VaulterTrackedChanges,
} from '../src'
import type { SuggestionCapabilities, TrackedStructuralSuggestion, TrackedTextSuggestion } from '../src'

const editors: Editor[] = []
const documents: Y.Doc[] = []

afterEach(() => {
  editors.splice(0).forEach(editor => editor.destroy())
  documents.splice(0).forEach(doc => doc.destroy())
})

const RichBlockId = Extension.create({
  name: 'testRichBlockId',
  addGlobalAttributes() {
    return [
      {
        types: ['paragraph', 'heading'],
        attributes: {
          blockId: {
            default: null,
            parseHTML: element => element.getAttribute('data-block-id'),
            renderHTML: attributes => (attributes.blockId ? { 'data-block-id': attributes.blockId } : {}),
          },
        },
      },
    ]
  },
})

function openStructuralEditor(options: {
  author: () => string
  content?: string
  ydoc?: Y.Doc
  capabilities?: () => SuggestionCapabilities
}) {
  let idCounter = 1
  const onSuggestions = vi.fn<(suggestions: readonly TrackedTextSuggestion[]) => void>()
  const onStructuralSuggestions = vi.fn<(suggestions: readonly TrackedStructuralSuggestion[]) => void>()
  const onUnsupportedEdit = vi.fn()
  const onConflict = vi.fn()

  const collaboration = options.ydoc
    ? [
        Extension.create({
          name: 'testCollaboration',
          addProseMirrorPlugins: () => [
            ySyncPlugin(options.ydoc!.getXmlFragment('default')),
            yUndoPlugin(),
          ],
        }),
      ]
    : []

  const editor = new Editor({
    element: document.createElement('div'),
    extensions: [
      StarterKit.configure({
        undoRedo: options.ydoc ? false : undefined,
      }),
      TableKit,
      RichBlockId,
      VaulterTrackedChanges.configure({
        author: options.author,
        capabilities: options.capabilities ?? (() => DEFAULT_REVIEW_CAPABILITIES),
        createId: () => `id_${idCounter++}`,
        onSuggestions,
        onStructuralSuggestions,
        onUnsupportedEdit,
        onConflict,
      }),
      ...collaboration,
    ],
    content: options.content ?? '<p data-block-id="p1">Hello world</p>',
  })

  editors.push(editor)
  return { editor, onSuggestions, onStructuralSuggestions, onUnsupportedEdit, onConflict }
}

it('tracks mark_change (bold & link) and projects original, proposed, and review cleanly', () => {
  const { editor, onStructuralSuggestions } = openStructuralEditor({
    author: () => 'alice',
    content: '<p data-block-id="p1">Alpha Beta</p>',
  })

  editor.commands.setTextSelection({ from: 1, to: 6 })
  editor.commands.toggleBold()

  const boldSugg = onStructuralSuggestions.mock.calls[0]?.[0][0]
  expect(boldSugg).toMatchObject({
    kind: 'mark_change',
    blockId: 'p1',
    from: 0,
    to: 5,
    author: 'alice',
    conflicted: false,
    payload: { markName: 'bold', operation: 'added', quoteText: 'Alpha' },
  })

  const originalDoc = projectTrackedChanges(editor.state.doc, 'original')
  const proposedDoc = projectTrackedChanges(editor.state.doc, 'proposed')
  expect(originalDoc.textContent).toBe('Alpha Beta')
  expect(originalDoc.firstChild?.firstChild?.marks ?? []).toHaveLength(0)
  expect(proposedDoc.firstChild?.firstChild?.marks.some(m => m.type.name === 'bold')).toBe(true)

  expect(applySuggestionDecision(editor, 'reject', { ids: [boldSugg.suggestionId] })).toBe(true)
  expect(editor.state.doc.firstChild?.firstChild?.marks ?? []).toHaveLength(0)

  // Add safe https link then accept it
  editor.commands.setTextSelection({ from: 7, to: 11 })
  editor.commands.setLink({ href: 'https://example.com' })
  const linkSugg = onStructuralSuggestions.mock.calls[1]?.[0][0]
  expect(linkSugg.payload).toMatchObject({ markName: 'link', operation: 'added' })
  expect(applySuggestionDecision(editor, 'accept', { ids: [linkSugg.suggestionId] })).toBe(true)
  const linkMarks = editor.state.doc.firstChild?.lastChild?.marks ?? []
  expect(linkMarks.some(m => m.type.name === 'link' && m.attrs.href === 'https://example.com')).toBe(true)
  expect(linkMarks.some(m => m.type.name === 'reviewSuggestion')).toBe(false)
})

it('tracks attr_change (heading level) and auto-cleans when reverted to old value by same author', () => {
  const { editor, onStructuralSuggestions } = openStructuralEditor({
    author: () => 'alice',
    content: '<h1 data-block-id="h1">Section Title</h1>',
  })

  editor.commands.setTextSelection(3)
  editor.commands.updateAttributes('heading', { level: 3 })

  const levelSugg = onStructuralSuggestions.mock.calls[0]?.[0][0]
  expect(levelSugg).toMatchObject({
    kind: 'attr_change',
    blockId: 'h1',
    author: 'alice',
    payload: { nodeType: 'heading', attr: 'level', oldValue: 1, newValue: 3 },
  })

  expect(projectTrackedChanges(editor.state.doc, 'original').firstChild?.attrs.level).toBe(1)
  expect(projectTrackedChanges(editor.state.doc, 'proposed').firstChild?.attrs.level).toBe(3)

  // Returning to level 1 by same author clears the suggestion automatically
  editor.commands.updateAttributes('heading', { level: 1 })
  expect(REVIEW_TRACKED_PLUGIN_KEY.getState(editor.state)?.structural).toHaveLength(0)

  // Change again to level 2 and explicitly reject
  editor.commands.updateAttributes('heading', { level: 2 })
  const secondLevelSugg = REVIEW_TRACKED_PLUGIN_KEY.getState(editor.state)?.structural[0]
  expect(secondLevelSugg?.payload.newValue).toBe(2)
  expect(applySuggestionDecision(editor, 'reject', { ids: [secondLevelSugg!.suggestionId] })).toBe(true)
  expect(editor.state.doc.firstChild?.attrs.level).toBe(1)
})

it('tracks block_split (structural and insertion) with distinct blockIds and block_join', () => {
  const { editor, onStructuralSuggestions } = openStructuralEditor({
    author: () => 'alice',
    content: '<p data-block-id="p1">Hello world</p>',
  })

  // Structural split in middle of block
  editor.commands.setTextSelection(6)
  editor.commands.splitBlock()

  const splitSugg = onStructuralSuggestions.mock.calls[0]?.[0][0]
  expect(splitSugg).toMatchObject({
    kind: 'block_split',
    blockId: 'p1',
    payload: { splitKind: 'structural', offsetUtf16: 5 },
  })
  const newBlockId = splitSugg.payload.newBlockId as string
  expect(newBlockId).toBeTruthy()
  expect(newBlockId).not.toBe('p1')
  expect(editor.state.doc.childCount).toBe(2)
  expect(editor.state.doc.child(1).attrs.blockId).toBe(newBlockId)

  const original = projectTrackedChanges(editor.state.doc, 'original')
  expect(original.childCount).toBe(1)
  expect(original.child(0).textContent).toBe('Hello world')

  // Reject rejoins back cleanly
  expect(applySuggestionDecision(editor, 'reject', { ids: [splitSugg.suggestionId] })).toBe(true)
  expect(editor.state.doc.childCount).toBe(1)
  expect(editor.state.doc.child(0).textContent).toBe('Hello world')

  // Split at end of paragraph -> 'insertion'
  editor.commands.setTextSelection(12)
  editor.commands.splitBlock()
  const insSplit = onStructuralSuggestions.mock.calls[1]?.[0][0]
  expect(insSplit.payload.splitKind).toBe('insertion')
  expect(applySuggestionDecision(editor, 'accept', { ids: [insSplit.suggestionId] })).toBe(true)

  // Now join second paragraph back onto p1 via ProseMirror join step
  const joinPos = editor.state.doc.child(0).nodeSize
  editor.view.dispatch(editor.state.tr.join(joinPos))
  const joinSugg = onStructuralSuggestions.mock.calls[2]?.[0][0]
  expect(joinSugg).toMatchObject({
    kind: 'block_join',
    blockId: 'p1',
    payload: { secondBlockId: insSplit.payload.newBlockId, joinOffset: 11 },
  })
  expect(projectTrackedChanges(editor.state.doc, 'original').childCount).toBe(2)
  expect(applySuggestionDecision(editor, 'reject', { ids: [joinSugg.suggestionId] })).toBe(true)
  expect(editor.state.doc.childCount).toBe(2)
  expect(editor.state.doc.child(1).attrs.blockId).toBe(insSplit.payload.newBlockId)
})

it('tracks list_change and textual table_change with fresh cell blockIds', () => {
  const { editor, onStructuralSuggestions } = openStructuralEditor({
    author: () => 'alice',
    content: '<p data-block-id="p1">First item</p><p data-block-id="p2">Table anchor</p>',
  })

  // Wrap p1 in bullet list -> toggle to ordered list -> reject both cleanly
  editor.commands.setTextSelection(3)
  editor.commands.toggleBulletList()
  const wrapSugg = onStructuralSuggestions.mock.calls[0]?.[0][0]
  expect(wrapSugg).toMatchObject({ kind: 'list_change', blockId: 'p1', payload: { listOp: 'wrap_bullet' } })

  editor.commands.toggleOrderedList()
  const toggleSugg = onStructuralSuggestions.mock.calls[1]?.[0][0]
  expect(toggleSugg).toMatchObject({ kind: 'list_change', blockId: 'p1', payload: { listOp: 'toggle_type', listType: 'orderedList' } })
  expect(projectTrackedChanges(editor.state.doc, 'original').firstChild?.type.name).toBe('paragraph')

  expect(applySuggestionDecision(editor, 'reject', { ids: [wrapSugg.suggestionId, toggleSugg.suggestionId] })).toBe(true)
  expect(editor.state.doc.firstChild?.type.name).toBe('paragraph')

  // Insert 2x2 textual table and add a row
  editor.commands.setTextSelection(1)
  editor.commands.insertTable({ rows: 2, cols: 2, withHeaderRow: false })
  const insertTableSugg = onStructuralSuggestions.mock.calls[2]?.[0][0]
  expect(insertTableSugg.kind).toBe('table_change')
  expect(insertTableSugg.payload.tableOp).toBe('insert_table')
  const cellIds = insertTableSugg.payload.cellBlockIds as string[]
  expect(new Set(cellIds).size).toBe(4)

  // Cursor inside first cell -> add row after
  editor.commands.setTextSelection(5)
  editor.commands.addRowAfter()
  const addRowSugg = onStructuralSuggestions.mock.calls[3]?.[0][0]
  expect(addRowSugg.payload.tableOp).toBe('insert_row')
  expect(editor.state.doc.firstChild?.childCount).toBe(3)
  expect(applySuggestionDecision(editor, 'reject', { ids: [addRowSugg.suggestionId] })).toBe(true)
  expect(editor.state.doc.firstChild?.childCount).toBe(2)
})

it('rejects unsupported edits before mutating Yjs doc (0 Yjs updates emitted)', () => {
  const ydoc = new Y.Doc()
  documents.push(ydoc)
  const { editor, onUnsupportedEdit, onStructuralSuggestions } = openStructuralEditor({
    author: () => 'alice',
    content: '',
    ydoc,
  })

  // Seed initial doc untracked with paragraph + textual table
  editor.view.dispatch(
    editor.state.tr
      .setMeta('vaulter:untracked', true)
      .insert(0, editor.schema.node('paragraph', { blockId: 'p1' }, editor.schema.text('Paragraph text'))),
  )
  editor.commands.setTextSelection(1)
  // Temporarily test with disabled node_type_change and invalid mutations after tracking starts
  const updates: number[] = []
  ydoc.on('update', update => {
    updates.push(update.byteLength)
  })
  const beforeDoc = editor.state.doc

  // 1. node_type_change: paragraph -> heading (node_type_change_types: [] in v1)
  editor.commands.setTextSelection(3)
  editor.commands.toggleHeading({ level: 2 })
  expect(updates).toHaveLength(0)
  expect(editor.state.doc).toBe(beforeDoc)
  expect(onUnsupportedEdit).toHaveBeenCalledTimes(1)

  // 2. blockquote & codeBlock disallowed in v1
  editor.commands.toggleBlockquote()
  editor.commands.toggleCodeBlock()
  expect(updates).toHaveLength(0)
  expect(editor.state.doc).toBe(beforeDoc)
  expect(onUnsupportedEdit).toHaveBeenCalledTimes(3)

  // 3. Immutable blockId AttrStep disallowed
  editor.view.dispatch(editor.state.tr.setNodeAttribute(0, 'blockId', 'hacked_id'))
  expect(updates).toHaveLength(0)
  expect(editor.state.doc).toBe(beforeDoc)
  expect(onUnsupportedEdit).toHaveBeenCalledTimes(4)

  // 4. Unsafe javascript: link href disallowed (test direct ProseMirror AddMarkStep bypass)
  const linkMarkType = editor.schema.marks.link
  editor.view.dispatch(editor.state.tr.addMark(1, 10, linkMarkType.create({ href: 'javascript:alert(1)' })))
  expect(updates).toHaveLength(0)
  expect(editor.state.doc).toBe(beforeDoc)
  expect(onUnsupportedEdit).toHaveBeenCalledTimes(5)

  // 5. Disallowed block structure inside table (codeBlock inside tableCell) rejected BEFORE write
  const badTable = editor.schema.node('table', undefined, [
    editor.schema.node('tableRow', undefined, [
      editor.schema.node('tableCell', undefined, [
        editor.schema.node('codeBlock', undefined, editor.schema.text('code')),
      ]),
    ]),
  ])
  editor.view.dispatch(editor.state.tr.insert(editor.state.doc.content.size, badTable))
  expect(updates).toHaveLength(0)
  expect(editor.state.doc).toBe(beforeDoc)
  expect(onUnsupportedEdit).toHaveBeenCalledTimes(6)
  expect(onStructuralSuggestions).not.toHaveBeenCalled()
})

it('shares structural records with peers and restores them when the document is reopened', async () => {
  const aliceDoc = new Y.Doc()
  const bobDoc = new Y.Doc()
  const reopenedDoc = new Y.Doc()
  documents.push(aliceDoc, bobDoc, reopenedDoc)
  const { editor: alice, onStructuralSuggestions } = openStructuralEditor({ author: () => 'alice', ydoc: aliceDoc })
  alice.view.dispatch(alice.state.tr
    .setMeta('vaulter:untracked', true)
    .replaceWith(0, alice.state.doc.content.size, alice.schema.node('heading', {
      blockId: 'title', level: 1,
    }, alice.schema.text('Shared title'))))
  Y.applyUpdate(bobDoc, Y.encodeStateAsUpdate(aliceDoc))
  const { editor: bob } = openStructuralEditor({ author: () => 'bob', ydoc: bobDoc })
  aliceDoc.on('update', bytes => Y.applyUpdate(bobDoc, bytes))
  bobDoc.on('update', bytes => Y.applyUpdate(aliceDoc, bytes))

  const updates = vi.fn()
  aliceDoc.on('update', updates)
  alice.commands.setTextSelection(2)
  alice.commands.setHeading({ level: 2 })
  const registered = onStructuralSuggestions.mock.calls[0]?.[0][0]
  expect(registered).toMatchObject({ kind: 'attr_change', blockId: 'title', author: 'alice' })
  expect(updates).toHaveBeenCalledTimes(1)
  expect(bob.state.doc.firstChild?.attrs.level).toBe(2)
  expect(projectTrackedChanges(bob.state.doc, 'original').firstChild?.attrs.level).toBe(1)

  Y.applyUpdate(reopenedDoc, Y.encodeStateAsUpdate(aliceDoc))
  const { editor: reopened } = openStructuralEditor({ author: () => 'carol', ydoc: reopenedDoc })
  await Promise.resolve()
  expect(projectTrackedChanges(reopened.state.doc, 'original').firstChild?.attrs.level).toBe(1)
  expect(REVIEW_TRACKED_PLUGIN_KEY.getState(reopened.state)?.structural[0]).toMatchObject({ author: 'alice' })

  expect(applySuggestionDecision(bob, 'reject', { ids: [registered!.suggestionId] })).toBe(true)
  expect(alice.state.doc.firstChild?.attrs.level).toBe(1)
  expect(REVIEW_TRACKED_PLUGIN_KEY.getState(alice.state)?.structural).toEqual([])
})

it('anchors inserted tables to an original block and restores a deleted first row', () => {
  const { editor, onStructuralSuggestions } = openStructuralEditor({
    author: () => 'alice',
    content: '<p data-block-id="anchor">Above</p>',
  })
  editor.commands.setTextSelection(6)
  editor.commands.insertTable({ rows: 2, cols: 1, withHeaderRow: true })
  const inserted = onStructuralSuggestions.mock.lastCall?.[0][0]
  expect(inserted).toMatchObject({
    kind: 'table_change', blockId: 'anchor', payload: { tableOp: 'insert_table', rows: 2, cols: 1, withHeaderRow: true },
  })
  expect(inserted?.payload.cellBlockIds).not.toContain('anchor')
  expect(applySuggestionDecision(editor, 'reject', { ids: [inserted!.suggestionId] })).toBe(true)

  const { editor: tableEditor, onStructuralSuggestions: onTable } = openStructuralEditor({
    author: () => 'alice',
    content: '<p data-block-id="before">Above</p><table><tbody>'
      + '<tr><td><p data-block-id="first">First</p></td></tr>'
      + '<tr><td><p data-block-id="second">Second</p></td></tr>'
      + '</tbody></table><p data-block-id="after"></p>',
  })
  const original = tableEditor.state.doc.toJSON()
  tableEditor.state.doc.descendants((node, pos) => {
    if (node.attrs.blockId === 'first') tableEditor.commands.setTextSelection(pos + 1)
  })
  tableEditor.commands.deleteRow()
  const deleted = onTable.mock.lastCall?.[0][0]
  expect(deleted).toMatchObject({ blockId: 'second', payload: { tableOp: 'delete_row', rowIndex: 0 } })
  expect(tableEditor.state.doc.textContent).toBe('AboveSecond')
  expect(projectTrackedChanges(tableEditor.state.doc, 'original').toJSON()).toEqual(original)
  expect(applySuggestionDecision(tableEditor, 'reject', { ids: [deleted!.suggestionId] })).toBe(true)
  expect(tableEditor.state.doc.toJSON()).toEqual(original)

  tableEditor.commands.deleteRow()
  const unrecoverable = onTable.mock.lastCall?.[0][0]
  const survivingBlock = tableEditor.state.doc.firstChild!.nodeSize + 3
  tableEditor.view.dispatch(tableEditor.state.tr
    .setMeta('vaulter:untracked', true)
    .setNodeAttribute(survivingBlock, 'blockId', 'changed'))
  const beforeRefusal = tableEditor.state.doc
  expect(applySuggestionDecision(tableEditor, 'reject', { ids: [unrecoverable!.suggestionId] })).toBe(false)
  expect(tableEditor.state.doc).toBe(beforeRefusal)
  expect(REVIEW_TRACKED_PLUGIN_KEY.getState(tableEditor.state)?.structural).toHaveLength(1)
})

it('enforces overlap conflict policy v1 keeping 2nd suggestion open with conflict and original author', () => {
  let currentAuthor = 'alice'
  const { editor, onSuggestions, onStructuralSuggestions, onConflict } = openStructuralEditor({
    author: () => currentAuthor,
    content: '<p data-block-id="p1">Alpha Beta</p>',
  })

  // Alice creates structural block_split at offset 5
  editor.commands.setTextSelection(6)
  editor.commands.splitBlock()
  const splitSugg = onStructuralSuggestions.mock.calls[0]?.[0][0]
  expect(splitSugg.author).toBe('alice')
  expect(splitSugg.conflicted).toBe(false)

  // Bob inserts text at end of split left/right? Let's join back or test mark_change + overlapping delete by Bob!
  expect(applySuggestionDecision(editor, 'reject', { ids: [splitSugg.suggestionId] })).toBe(true)

  currentAuthor = 'alice'
  editor.commands.setTextSelection({ from: 1, to: 6 })
  editor.commands.toggleBold()
  const boldSugg = onStructuralSuggestions.mock.calls[1]?.[0][0]
  expect(boldSugg.author).toBe('alice')

  // Bob edits overlapping range [2..5] inside p1 ("lph")
  currentAuthor = 'bob'
  editor.view.dispatch(editor.state.tr.insertText('XXX', 2, 5))
  const bobSugg = onSuggestions.mock.lastCall?.[0][0]
  expect(bobSugg).toMatchObject({
    author: 'bob',
    conflicted: true,
    conflictWithId: boldSugg.suggestionId,
  })
  expect(onConflict).toHaveBeenCalledTimes(1)

  // Accept on conflicted suggestion is blocked prior to mutation
  expect(applySuggestionDecision(editor, 'accept', { ids: [bobSugg!.suggestionId] })).toBe(false)

  // Rejecting Alice's mark_change clears conflict on Bob's suggestion cleanly
  expect(applySuggestionDecision(editor, 'reject', { ids: [boldSugg.suggestionId] })).toBe(true)
  const snapshotAfterReject = REVIEW_TRACKED_PLUGIN_KEY.getState(editor.state)
  expect(snapshotAfterReject?.textMeta[bobSugg!.suggestionId]?.conflicted).toBe(false)
  expect(applySuggestionDecision(editor, 'accept', { ids: [bobSugg!.suggestionId] })).toBe(true)
})

it('avoids full-document descendants scans on trivial text edits and selection moves', () => {
  const { editor } = openStructuralEditor({
    author: () => 'alice',
    content: '<h1 data-block-id="h1">Title</h1><p data-block-id="p1">First</p><p data-block-id="p2">Second</p>',
  })
  editor.commands.setTextSelection(2)
  editor.commands.updateAttributes('heading', { level: 2 })
  expect(REVIEW_TRACKED_PLUGIN_KEY.getState(editor.state)?.structural).toHaveLength(1)

  const spy = vi.spyOn(ProseMirrorNode.prototype, 'descendants')
  try {
    // Untracked/selection-only and trivial inline text edits should not scan the full document
    editor.commands.setTextSelection(10)
    editor.view.dispatch(editor.state.tr.insertText('X', 10, 10).setMeta('vaulter:untracked', true))
    expect(spy).not.toHaveBeenCalled()
  } finally {
    spy.mockRestore()
  }
})
