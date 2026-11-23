import type { Editor } from '@tiptap/core'
import type { Mark, Node } from '@tiptap/pm/model'
import { Transform } from '@tiptap/pm/transform'

export const REVIEW_SUGGESTION_MARK = 'reviewSuggestion'
export const UNTRACKED_TRANSACTION = 'vaulter:untracked'

export interface SuggestionMarker {
  suggestionId: string
  kind: 'insert' | 'delete'
  parentId: string | null
}

export interface TrackedTextSuggestion {
  suggestionId: string
  groupId: string
  parentId: string | null
  kind: 'insert' | 'delete' | 'replace'
  blockId: string
  from: number
  to: number
  before: string
  after: string
}

export interface TrackedSuggestionSelection {
  ids: Iterable<string>
  groups?: Iterable<string>
  suggestions?: Iterable<Pick<TrackedTextSuggestion, 'suggestionId' | 'groupId'>>
}

export type TrackedProjection = 'original' | 'proposed' | 'review'

export function suggestionMarker(mark: Mark): SuggestionMarker | null {
  const { suggestionId, kind, parentId } = mark.attrs
  if (mark.type.name !== REVIEW_SUGGESTION_MARK || typeof suggestionId !== 'string'
    || (kind !== 'insert' && kind !== 'delete')) {
    return null
  }
  return { suggestionId, kind, parentId: typeof parentId === 'string' ? parentId : null }
}

function select(selection: TrackedSuggestionSelection): Set<string> {
  const ids = new Set(selection.ids)
  const groups = new Set(selection.groups)
  for (const suggestion of selection.suggestions ?? []) {
    if (groups.has(suggestion.groupId)) ids.add(suggestion.suggestionId)
  }
  return ids
}

function markerParents(doc: Node): Map<string, string | null> {
  const parents = new Map<string, string | null>()
  doc.descendants(node => {
    for (const mark of node.marks) {
      const marker = suggestionMarker(mark)
      if (marker) parents.set(marker.suggestionId, marker.parentId)
    }
  })
  return parents
}

function changeDocument(
  transform: Transform,
  accept: ReadonlySet<string>,
  reject: ReadonlySet<string>,
  parents: ReadonlyMap<string, string | null>,
): void {
  const spans: { node: Node; from: number }[] = []
  transform.doc.descendants((node, from) => {
    if (node.isText && node.marks.some(suggestionMarker)) spans.push({ node, from })
  })
  for (const { node, from } of spans.reverse()) {
    const to = from + node.nodeSize
    const markers = node.marks.flatMap(mark => {
      const marker = suggestionMarker(mark)
      return marker ? [{ mark, marker }] : []
    })
    if (markers.some(({ marker }) =>
      (marker.kind === 'insert' && reject.has(marker.suggestionId))
      || (marker.kind === 'delete' && accept.has(marker.suggestionId)
        && !markers.some(({ marker: insertion }) => insertion.kind === 'insert'
          && insertion.suggestionId !== marker.parentId && !accept.has(insertion.suggestionId)
          && insertion.parentId === marker.parentId)),
    )) {
      transform.delete(from, to)
      continue
    }
    for (const { mark, marker } of markers) {
      if (accept.has(marker.suggestionId) || reject.has(marker.suggestionId)) {
        transform.removeMark(from, to, mark)
      } else if (marker.parentId && accept.has(marker.parentId)) {
        let parentId: string | null = marker.parentId
        const visited = new Set<string>()
        while (parentId && accept.has(parentId) && !visited.has(parentId)) {
          visited.add(parentId)
          parentId = parents.get(parentId) ?? null
        }
        transform.removeMark(from, to, mark)
        transform.addMark(from, to, mark.type.create({ ...marker, parentId }))
      }
    }
  }
}

/** Project a review document for display/export without changing the live editor. */
export function projectTrackedChanges(
  doc: Node,
  mode: TrackedProjection,
  selection?: TrackedSuggestionSelection,
): Node {
  if (mode === 'review') return doc

  const parents = markerParents(doc)
  const accept = mode === 'original'
    ? new Set<string>()
    : selection ? select(selection) : new Set(parents.keys())
  const reject = new Set([...parents.keys()].filter(id => !accept.has(id)))
  const projection = new Transform(doc)
  changeDocument(projection, accept, reject, parents)
  return projection.doc
}

/** Update live markers after the authoritative service confirms the decision. */
export function applySuggestionDecision(
  editor: Editor,
  decision: 'accept' | 'reject',
  selection: TrackedSuggestionSelection,
): boolean {
  const ids = select(selection)
  const parents = markerParents(editor.state.doc)
  if (decision === 'reject') {
    const children = new Map<string, string[]>()
    for (const [id, parent] of parents) {
      if (parent !== null) {
        const siblings = children.get(parent) ?? []
        siblings.push(id)
        children.set(parent, siblings)
      }
    }
    for (const id of ids) {
      for (const child of children.get(id) ?? []) ids.add(child)
    }
  }

  const transaction = editor.state.tr
  const empty = new Set<string>()
  changeDocument(transaction, decision === 'accept' ? ids : empty, decision === 'reject' ? ids : empty, parents)
  if (!transaction.docChanged) return false
  editor.view.dispatch(transaction.setMeta(UNTRACKED_TRANSACTION, true).setMeta('addToHistory', false))
  return true
}
