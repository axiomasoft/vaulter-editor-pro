import { Fragment, Slice } from '@tiptap/pm/model'
import type { Mark, Node } from '@tiptap/pm/model'
import { Mapping, ReplaceStep } from '@tiptap/pm/transform'
import { TextSelection } from '@tiptap/pm/state'
import type { EditorState, Transaction } from '@tiptap/pm/state'
import { REVIEW_SUGGESTION_MARK, suggestionMarker } from './trackedProjection'
import type { TrackedTextSuggestion } from './trackedProjection'

export interface TextRun {
  author: string
  id: string
  groupId: string
  parentId: string | null
  kind: TrackedTextSuggestion['kind']
}

function textOnly(fragment: Fragment): boolean {
  for (let index = 0; index < fragment.childCount; index++) {
    if (!fragment.child(index).isText) return false
  }
  return true
}

function insertions(node: Node | null): Mark[] {
  return node?.marks.filter(mark => suggestionMarker(mark)?.kind === 'insert') ?? []
}

function deepestInsertion(marks: Mark[]): string | null {
  const ancestors = new Set(marks.map(mark => mark.attrs.parentId))
  const deepest = marks.find(mark => !ancestors.has(mark.attrs.suggestionId))
  return deepest?.attrs.suggestionId ?? null
}

function touchesRun(fragment: Fragment, before: Node | null, after: Node | null, run: TextRun): boolean {
  const facet = run.kind === 'insert' ? 'insert' : 'delete'
  const hasMark = (node: Node | null) => node?.marks.some(mark => {
    const marker = suggestionMarker(mark)
    return marker?.suggestionId === run.id && marker.kind === facet
  }) ?? false
  if (!hasMark(before) && !hasMark(after)) return false
  for (let index = 0; index < fragment.childCount; index++) {
    if (hasMark(fragment.child(index))) return false
  }
  return true
}

/** Read one run in canonical block coordinates, or in its parent's inserted text. */
function describe(doc: Node, run: TextRun, blockId: string): TrackedTextSuggestion | null {
  let result: TrackedTextSuggestion | null = null
  doc.descendants(block => {
    if (!block.isTextblock || block.attrs.blockId !== blockId) return
    let offset = 0
    let from: number | undefined
    let to: number | undefined
    let before = ''
    let after = ''
    let disjoint = false
    block.forEach(node => {
      const markers = node.marks.flatMap(mark => {
        const marker = suggestionMarker(mark)
        return marker ? [marker] : []
      })
      const inScope = run.parentId === null
        ? !markers.some(marker => marker.kind === 'insert')
        : markers.some(marker => marker.kind === 'insert' && marker.suggestionId === run.parentId)
          && !markers.some(marker => marker.kind === 'insert' && marker.parentId === run.parentId)
      const facet = markers.find(marker => marker.suggestionId === run.id)
      if (facet?.kind === 'insert') {
        from ??= offset
        to ??= offset
        after += node.text ?? ''
      } else if (facet?.kind === 'delete' && inScope) {
        disjoint ||= to !== undefined && to !== offset
        from ??= offset
        to = offset + node.nodeSize
        before += node.text ?? ''
      }
      if (inScope) offset += node.nodeSize
    })
    const kind = before === '' ? 'insert' : after === '' ? 'delete' : 'replace'
    if (from !== undefined && to !== undefined && !disjoint && kind === run.kind && before !== after) {
      result = { suggestionId: run.id, groupId: run.groupId, parentId: run.parentId, kind, blockId, from, to, before, after }
    }
    return false
  })
  return result
}

export function trackText(
  state: EditorState,
  incoming: Transaction,
  author: string,
  previous: TextRun | null,
  createId: () => string,
): { transaction: Transaction; run: TextRun | null; suggestions: TrackedTextSuggestion[] } | null {
  const markType = state.schema.marks[REVIEW_SUGGESTION_MARK]
  const outgoing = state.tr
  const touched = new Map<string, { run: TextRun; blockId: string }>()
  let mapping = new Mapping()
  let run = previous
  let collapsedDeletion: number | null = null
  for (const step of incoming.steps) {
    if (!(step instanceof ReplaceStep) || step.slice.openStart || step.slice.openEnd || !textOnly(step.slice.content)) return null
    const from = mapping.map(step.from, 1)
    const to = step.from === step.to ? from : mapping.map(step.to, -1)
    if (to < from) return null
    const $from = outgoing.doc.resolve(from)
    const $to = outgoing.doc.resolve(to)
    const blockId = $from.parent.attrs.blockId
    if (!$from.sameParent($to) || !$from.parent.isTextblock || !$from.parent.type.allowsMarkType(markType)
      || typeof blockId !== 'string' || !blockId) return null
    const removed = outgoing.doc.slice(from, to).content
    if (!textOnly(removed)) return null
    const before = removed.textBetween(0, removed.size)
    const after = step.slice.content.textBetween(0, step.slice.content.size)
    const loneSurrogate = /[\uD800-\uDFFF]/u
    const lowSurrogate = /^[\uDC00-\uDFFF]/
    if (before === after || loneSurrogate.test(before) || loneSurrogate.test(after)
      || lowSurrogate.test($from.nodeAfter?.text ?? '') || lowSurrogate.test($to.nodeAfter?.text ?? '')) return null

    const rightInsertions = insertions($to.nodeAfter)
    const ancestors = removed.firstChild
      ? insertions(removed.firstChild)
      : insertions($from.nodeBefore).filter(mark => mark.isInSet(rightInsertions))
    const parentId = deepestInsertion(ancestors)
    // The entire selection must use the same parent-relative coordinates.
    for (let index = 1; index < removed.childCount; index++) {
      if (deepestInsertion(insertions(removed.child(index))) !== parentId) return null
    }
    const kind = from === to ? 'insert' : after === '' ? 'delete' : 'replace'
    if (!run || run.author !== author || run.kind !== kind || run.parentId !== parentId || kind === 'replace'
      || !touchesRun(removed, $from.nodeBefore, $to.nodeAfter, run)) {
      run = { author, id: createId(), groupId: createId(), parentId, kind }
    }
    touched.set(run.id, { run, blockId })

    const marker = { suggestionId: run.id, parentId }
    if (from !== to) {
      outgoing.addMark(from, to, markType.create({ ...marker, kind: 'delete' }))
    }
    const next = new Mapping()
    next.appendMap(step.getMap().invert())
    next.appendMapping(mapping)
    if (after !== '') {
      const inserted: Node[] = []
      const insertion = markType.create({ ...marker, kind: 'insert' })
      step.slice.content.forEach(node => {
        const marks = node.marks.filter(mark => !suggestionMarker(mark))
        inserted.push(node.mark(insertion.addToSet([...marks, ...ancestors])))
      })
      outgoing.step(new ReplaceStep(to, to, new Slice(Fragment.fromArray(inserted), 0, 0)))
      next.appendMap(outgoing.mapping.maps[outgoing.mapping.maps.length - 1], 0)
    }
    collapsedDeletion = after === '' ? step.from : null
    mapping = next
  }

  const suggestions: TrackedTextSuggestion[] = []
  for (const { run, blockId } of touched.values()) {
    const change = describe(outgoing.doc, run, blockId)
    if (!change) return null
    suggestions.push(change)
  }
  if (incoming.selection.empty && incoming.selection.from === collapsedDeletion) {
    const from = mapping.map(collapsedDeletion, -1)
    outgoing.setSelection(TextSelection.create(outgoing.doc, from))
  } else {
    outgoing.setSelection(incoming.selection.map(outgoing.doc, mapping))
  }
  if (incoming.storedMarksSet) outgoing.setStoredMarks(incoming.storedMarks)
  for (const key of ['addToHistory', 'composition', 'uiEvent', 'pointer']) {
    const value = incoming.getMeta(key)
    if (value !== undefined) outgoing.setMeta(key, value)
  }
  if (incoming.scrolledIntoView) outgoing.scrollIntoView()
  return { transaction: outgoing, run, suggestions }
}
