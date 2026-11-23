import type { Editor } from '@tiptap/core'
import type { Mark, Node } from '@tiptap/pm/model'
import { Transform } from '@tiptap/pm/transform'
import {
  getDocSnapshot,
  registerDocSnapshot,
  revertStructuralRecord,
  type TrackedStructuralRecord,
} from './structuralReverter'
import type { StructuralSuggestionKind, SuggestionKind } from '../types/review'

export const REVIEW_SUGGESTION_MARK = 'reviewSuggestion'
export const UNTRACKED_TRANSACTION = 'vaulter:untracked'
export const REVIEW_DECISION_META = 'vaulter:reviewDecision'

export interface SuggestionMarker {
  suggestionId: string
  kind: 'insert' | 'delete' | 'mark_change'
  parentId: string | null
  groupId?: string | null
  author?: string | null
  markName?: string | null
  markAttrs?: Record<string, unknown> | null
  operation?: 'added' | 'removed' | null
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
  author?: string
  conflicted?: boolean
  conflictReason?: string | null
  conflictWithId?: string | null
}

export interface TrackedStructuralSuggestion {
  suggestionId: string
  groupId: string
  parentId: string | null
  kind: StructuralSuggestionKind
  blockId: string
  from: number
  to: number
  author: string
  conflicted: boolean
  conflictReason: string | null
  conflictWithId: string | null
  payload: Record<string, unknown>
}

export type TrackedSuggestion = TrackedTextSuggestion | TrackedStructuralSuggestion

export interface TrackedSuggestionSelection {
  ids: Iterable<string>
  groups?: Iterable<string>
  suggestions?: Iterable<Pick<TrackedSuggestion, 'suggestionId' | 'groupId'>>
}

export type TrackedProjection = 'original' | 'proposed' | 'review'

export function suggestionMarker(mark: Mark): SuggestionMarker | null {
  const { suggestionId, kind, parentId, groupId, author, markName, markAttrs, operation } = mark.attrs
  if (mark.type.name !== REVIEW_SUGGESTION_MARK || typeof suggestionId !== 'string') {
    return null
  }
  if (kind !== 'insert' && kind !== 'delete' && kind !== 'mark_change') {
    return null
  }
  return {
    suggestionId,
    kind,
    parentId: typeof parentId === 'string' ? parentId : null,
    groupId: typeof groupId === 'string' ? groupId : null,
    author: typeof author === 'string' ? author : null,
    markName: typeof markName === 'string' ? markName : null,
    markAttrs: markAttrs && typeof markAttrs === 'object' ? (markAttrs as Record<string, unknown>) : null,
    operation: operation === 'added' || operation === 'removed' ? operation : null,
  }
}

export function isTextSuggestion(s: TrackedSuggestion): s is TrackedTextSuggestion {
  return s.kind === 'insert' || s.kind === 'delete' || s.kind === 'replace'
}

export function isStructuralSuggestion(s: TrackedSuggestion): s is TrackedStructuralSuggestion {
  return !isTextSuggestion(s)
}

export function structuralSuggestionKindList(): readonly SuggestionKind[] {
  return ['insert', 'delete', 'replace', 'mark_change', 'attr_change', 'block_split', 'block_join', 'list_change', 'table_change']
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
  const schema = transform.doc.type.schema
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
      if (marker.kind === 'mark_change') {
        if (reject.has(marker.suggestionId)) {
          transform.removeMark(from, to, mark)
          const targetMarkType = marker.markName ? schema.marks[marker.markName] : undefined
          if (targetMarkType) {
            if (marker.operation === 'added') {
              transform.removeMark(from, to, targetMarkType)
            } else if (marker.operation === 'removed') {
              transform.addMark(from, to, targetMarkType.create(marker.markAttrs ?? undefined))
            }
          }
        } else if (accept.has(marker.suggestionId)) {
          transform.removeMark(from, to, mark)
        }
        continue
      }

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
        transform.addMark(from, to, mark.type.create({ ...mark.attrs, parentId }))
      }
    }
  }
}

function collectAllSuggestionIds(doc: Node, structural: readonly TrackedStructuralRecord[]): Map<string, string | null> {
  const parents = markerParents(doc)
  for (const rec of structural) {
    if (!parents.has(rec.suggestionId)) {
      parents.set(rec.suggestionId, rec.parentId)
    }
  }
  return parents
}

/** Project a review document (`original` | `proposed` | `review`) without changing the live editor. */
export function projectTrackedChanges(
  doc: Node,
  mode: TrackedProjection,
  selection?: TrackedSuggestionSelection,
): Node {
  if (mode === 'review') return doc

  const snapshot = getDocSnapshot(doc)
  const structural = snapshot?.structural ?? []
  const parents = collectAllSuggestionIds(doc, structural)

  const accept = mode === 'original'
    ? new Set<string>()
    : selection ? select(selection) : new Set(parents.keys())
  const reject = new Set([...parents.keys()].filter(id => !accept.has(id)))

  const projection = new Transform(doc)
  changeDocument(projection, accept, reject, parents)

  // Revert unaccepted non-mark structural records in reverse chronological order.
  for (let i = structural.length - 1; i >= 0; i--) {
    const rec = structural[i]
    if (reject.has(rec.suggestionId) && rec.kind !== 'mark_change') {
      revertStructuralRecord(projection, rec)
    }
  }

  return projection.doc
}

/** Update live markers and structural records after authoritative confirmation (or local accept/reject). */
export function applySuggestionDecision(
  editor: Editor,
  decision: 'accept' | 'reject',
  selection: TrackedSuggestionSelection,
): boolean {
  const snapshot = getDocSnapshot(editor.state.doc)
  const structural = snapshot?.structural ?? []
  const textMeta = snapshot?.textMeta ?? {}
  const parents = collectAllSuggestionIds(editor.state.doc, structural)

  const ids = select(selection)
  if (decision === 'accept') {
    for (const rec of structural) {
      if (ids.has(rec.suggestionId) && rec.conflicted) {
        return false
      }
    }
    for (const [id, meta] of Object.entries(textMeta)) {
      if (ids.has(id) && meta.conflicted) {
        return false
      }
    }
  }

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

  const structuralMatched = structural.filter(rec => ids.has(rec.suggestionId))
  if (decision === 'reject') {
    for (let i = structural.length - 1; i >= 0; i--) {
      const rec = structural[i]
      if (ids.has(rec.suggestionId) && rec.kind !== 'mark_change' && !revertStructuralRecord(transaction, rec)) {
        return false
      }
    }
  }

  const nextStructural = structural
    .filter(rec => !ids.has(rec.suggestionId))
    .map(rec => (decision === 'reject' && rec.conflictWithId && ids.has(rec.conflictWithId)
      ? { ...rec, conflicted: false, conflictReason: null, conflictWithId: null }
      : rec))
  const nextTextMeta: Record<string, typeof textMeta[string]> = {}
  for (const [id, meta] of Object.entries(textMeta)) {
    if (!ids.has(id)) {
      nextTextMeta[id] = decision === 'reject' && meta.conflictWithId && ids.has(meta.conflictWithId)
        ? { ...meta, conflicted: false, conflictReason: null, conflictWithId: null }
        : meta
    }
  }

  const stateChanged = structuralMatched.length > 0 || transaction.docChanged
  if (!stateChanged) return false

  transaction
    .setMeta(UNTRACKED_TRANSACTION, true)
    .setMeta('addToHistory', false)
    .setMeta(REVIEW_DECISION_META, {
      decision,
      ids: [...ids],
      structural: nextStructural,
      textMeta: nextTextMeta,
    })

  registerDocSnapshot(transaction.doc, {
    structural: nextStructural,
    textMeta: nextTextMeta,
  })
  editor.view.dispatch(transaction)
  return true
}
