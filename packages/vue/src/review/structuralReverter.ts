import { Fragment, type Node as ProseMirrorNode, type Schema, Slice } from '@tiptap/pm/model'
import { liftTarget, ReplaceAroundStep, ReplaceStep, Step, findWrapping } from '@tiptap/pm/transform'
import type { Transform } from '@tiptap/pm/transform'
import type { StructuralSuggestionKind, SuggestionCapabilities } from '../types/review'

export interface TrackedStructuralRecord {
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

export interface TextSuggestionMetadata {
  author: string
  groupId: string
  blockId: string
  conflicted?: boolean
  conflictReason?: string | null
  conflictWithId?: string | null
}

export interface TrackedDocSnapshotState {
  structural: readonly TrackedStructuralRecord[]
  textMeta: Readonly<Record<string, TextSuggestionMetadata>>
}

const docSnapshotRegistry = new WeakMap<ProseMirrorNode, TrackedDocSnapshotState>()

export function registerDocSnapshot(doc: ProseMirrorNode, state: TrackedDocSnapshotState): void {
  docSnapshotRegistry.set(doc, state)
}

export function getDocSnapshot(doc: ProseMirrorNode): TrackedDocSnapshotState | undefined {
  return docSnapshotRegistry.get(doc)
}

export function findBlockPosById(doc: ProseMirrorNode, blockId: string): { pos: number; node: ProseMirrorNode } | null {
  let hit: { pos: number; node: ProseMirrorNode } | null = null
  doc.descendants((node, pos) => {
    if (hit !== null) return false
    if (node.attrs && node.attrs.blockId === blockId) {
      hit = { pos, node }
      return false
    }
    return undefined
  })
  return hit
}

export function nodeContainsBlockId(node: ProseMirrorNode, blockId: string): boolean {
  if (node.attrs && node.attrs.blockId === blockId) {
    return true
  }
  let found = false
  node.descendants(child => {
    if (child.attrs && child.attrs.blockId === blockId) {
      found = true
      return false
    }
    return undefined
  })
  return found
}

export function findAncestorByType(
  doc: ProseMirrorNode,
  posInside: number,
  types: readonly string[],
): { pos: number; node: ProseMirrorNode } | null {
  const $pos = doc.resolve(Math.max(0, Math.min(posInside, doc.content.size)))
  for (let depth = $pos.depth; depth >= 1; depth--) {
    const node = $pos.node(depth)
    if (types.includes(node.type.name)) {
      return { pos: $pos.before(depth), node }
    }
  }
  return null
}

export function extractBlockIdsFromNode(node: ProseMirrorNode): string[] {
  const ids: string[] = []
  node.descendants(child => {
    if (typeof child.attrs?.blockId === 'string' && child.attrs.blockId !== '') {
      ids.push(child.attrs.blockId)
    }
  })
  return ids
}

export function isTableNodeTextual(tableNode: ProseMirrorNode, allowedMarks: readonly string[] | null = null): boolean {
  if (tableNode.type.name !== 'table') {
    return false
  }
  for (let r = 0; r < tableNode.childCount; r++) {
    const row = tableNode.child(r)
    if (row.type.name !== 'tableRow') return false
    for (let c = 0; c < row.childCount; c++) {
      const cell = row.child(c)
      if (cell.type.name !== 'tableCell' && cell.type.name !== 'tableHeader') return false
      for (let b = 0; b < cell.childCount; b++) {
        const block = cell.child(b)
        if (block.type.name !== 'paragraph' && block.type.name !== 'heading') return false
        for (let i = 0; i < block.childCount; i++) {
          const inline = block.child(i)
          if (!inline.isText && inline.type.name !== 'hardBreak') return false
          if (allowedMarks) {
            for (const mark of inline.marks) {
              if (mark.type.name !== 'reviewSuggestion' && !allowedMarks.includes(mark.type.name)) {
                return false
              }
            }
          }
        }
      }
    }
  }
  return true
}

export function isSafeLinkHref(href: unknown): boolean {
  if (typeof href !== 'string') return false
  const stripped = href.replace(/[\u0000-\u001F\u007F]/g, '').trim()
  if (!stripped || /[\u202A-\u202E\u2066-\u2069]/.test(stripped)) return false
  const match = /^([a-z][a-z0-9+.-]*):/i.exec(stripped)
  if (!match) return false
  const scheme = match[1].toLowerCase()
  return scheme === 'http' || scheme === 'https' || scheme === 'mailto'
}

export function isDangerousAttrValue(value: unknown): boolean {
  if (Array.isArray(value)) return value.some(isDangerousAttrValue)
  if (typeof value !== 'string') return false
  const norm = value.replace(/[\u0000-\u001F\u007F]/g, '').trim().toLowerCase()
  return norm.startsWith('javascript:') || norm.startsWith('data:') || norm.startsWith('vbscript:')
}

export function isCapabilityAllowed(
  kind: string,
  capabilities: SuggestionCapabilities,
): boolean {
  return (capabilities.supported_kinds as readonly string[]).includes(kind)
}

function shiftStepJson(raw: Record<string, unknown>, delta: number): Record<string, unknown> {
  if (delta === 0) return raw
  const shifted: Record<string, unknown> = { ...raw }
  for (const key of ['from', 'to', 'gapFrom', 'gapTo', 'pos'] as const) {
    if (typeof shifted[key] === 'number') {
      shifted[key] = (shifted[key] as number) + delta
    }
  }
  return shifted
}

function textOffsetToPmOffset(block: ProseMirrorNode, textOffset: number): number {
  const clamped = Math.max(0, textOffset)
  let seen = 0
  let pm = 0
  for (let i = 0; i < block.childCount; i++) {
    const child = block.child(i)
    if (child.isText) {
      const len = child.text?.length ?? 0
      if (seen + len >= clamped) {
        return pm + (clamped - seen)
      }
      seen += len
      pm += child.nodeSize
    } else {
      if (seen >= clamped) return pm
      seen += 1
      pm += child.nodeSize
    }
  }
  return block.content.size
}

function revertAttrChange(tr: Transform, record: TrackedStructuralRecord): boolean {
  const nodeType = String(record.payload.nodeType ?? '')
  const attr = String(record.payload.attr ?? '')
  const oldValue = record.payload.oldValue ?? null
  const anchor = findBlockPosById(tr.doc, record.blockId)
  if (!anchor) return false

  if (nodeType === 'paragraph' || nodeType === 'heading') {
    if (anchor.node.type.name !== nodeType) return false
    tr.setNodeMarkup(anchor.pos, undefined, { ...anchor.node.attrs, [attr]: oldValue })
    return true
  }

  if (nodeType === 'orderedList') {
    const listHit = findAncestorByType(tr.doc, anchor.pos + 1, ['orderedList'])
    if (!listHit) return false
    tr.setNodeMarkup(listHit.pos, undefined, { ...listHit.node.attrs, [attr]: oldValue })
    return true
  }

  if (nodeType === 'tableCell' || nodeType === 'tableHeader') {
    const tableHit = findAncestorByType(tr.doc, anchor.pos + 1, ['table'])
    if (!tableHit) return false
    const rowIdx = typeof record.payload.rowIndex === 'number' ? record.payload.rowIndex : null
    const colIdx = typeof record.payload.colIndex === 'number' ? record.payload.colIndex : null
    if (rowIdx !== null && colIdx !== null && rowIdx < tableHit.node.childCount) {
      let pos = tableHit.pos + 1
      for (let r = 0; r < tableHit.node.childCount; r++) {
        const row = tableHit.node.child(r)
        if (r === rowIdx) {
          let cPos = pos + 1
          for (let c = 0; c < row.childCount; c++) {
            const cell = row.child(c)
            if (c === colIdx && cell.type.name === nodeType) {
              tr.setNodeMarkup(cPos, undefined, { ...cell.attrs, [attr]: oldValue })
              return true
            }
            cPos += cell.nodeSize
          }
          break
        }
        pos += row.nodeSize
      }
    }
    const cellHit = findAncestorByType(tr.doc, anchor.pos + 1, ['tableCell', 'tableHeader'])
    if (!cellHit) return false
    tr.setNodeMarkup(cellHit.pos, undefined, { ...cellHit.node.attrs, [attr]: oldValue })
    return true
  }

  if (nodeType === 'link') {
    const linkMark = tr.doc.type.schema.marks.link
    if (!linkMark) return false
    const from = anchor.pos + 1 + record.from
    const to = anchor.pos + 1 + record.to
    let changed = false
    tr.doc.nodesBetween(from, Math.min(to, tr.doc.content.size), (node, pos) => {
      if (!node.isText) return
      const existing = node.marks.find(m => m.type === linkMark)
      if (!existing) return
      const spanFrom = Math.max(from, pos)
      const spanTo = Math.min(to, pos + node.nodeSize)
      if (spanFrom >= spanTo) return
      tr.removeMark(spanFrom, spanTo, existing)
      tr.addMark(spanFrom, spanTo, linkMark.create({ ...existing.attrs, [attr]: oldValue }))
      changed = true
    })
    return changed
  }

  return false
}

function revertBlockSplit(tr: Transform, record: TrackedStructuralRecord): boolean {
  const splitKind = String(record.payload.splitKind ?? 'structural')
  const newBlockId = String(record.payload.newBlockId ?? '')
  const newHit = newBlockId ? findBlockPosById(tr.doc, newBlockId) : null
  if (!newHit) return false

  if (splitKind === 'insertion') {
    tr.delete(newHit.pos, newHit.pos + newHit.node.nodeSize)
    return true
  }

  const origHit = findBlockPosById(tr.doc, record.blockId)
  if (!origHit) return false
  const leftEnd = origHit.pos + origHit.node.nodeSize
  if (leftEnd === newHit.pos) {
    tr.join(leftEnd)
    return true
  }
  return false
}

function revertBlockJoin(tr: Transform, record: TrackedStructuralRecord, schema: Schema): boolean {
  const anchor = findBlockPosById(tr.doc, record.blockId)
  if (!anchor) return false
  const secondBlockId = String(record.payload.secondBlockId ?? '')
  const secondNodeType = String(record.payload.secondNodeType ?? anchor.node.type.name)
  const secondAttrs = (record.payload.secondAttrs as Record<string, unknown> | undefined) ?? {}
  const joinOffset = typeof record.payload.joinOffset === 'number' ? record.payload.joinOffset : 0

  const splitLocal = textOffsetToPmOffset(anchor.node, joinOffset)
  const splitPos = anchor.pos + 1 + splitLocal
  const nodeType = schema.nodes[secondNodeType] ?? anchor.node.type
  const nextAttrs = { ...secondAttrs, blockId: secondBlockId }
  tr.split(splitPos, 1, [{ type: nodeType, attrs: nextAttrs }])
  return true
}

function revertListChange(tr: Transform, record: TrackedStructuralRecord, schema: Schema): boolean {
  const listOp = String(record.payload.listOp ?? '')
  const anchor = findBlockPosById(tr.doc, record.blockId)
  if (!anchor) return false

  if (listOp === 'toggle_type') {
    const prevType = String(record.payload.previousListType ?? 'bulletList')
    const prevAttrs = (record.payload.previousListAttrs as Record<string, unknown> | undefined) ?? null
    const listHit = findAncestorByType(tr.doc, anchor.pos + 1, ['bulletList', 'orderedList'])
    if (!listHit || !schema.nodes[prevType]) return false
    tr.setNodeMarkup(listHit.pos, schema.nodes[prevType], prevAttrs)
    return true
  }

  if (listOp === 'wrap_bullet' || listOp === 'wrap_ordered') {
    const $from = tr.doc.resolve(anchor.pos + 1)
    const range = $from.blockRange()
    if (!range) return false
    const targetDepth = liftTarget(range)
    if (targetDepth !== null && targetDepth !== undefined) {
      tr.lift(range, targetDepth)
      return true
    }
    return false
  }

  if (listOp === 'sink_item') {
    const $from = tr.doc.resolve(anchor.pos + 1)
    const range = $from.blockRange()
    if (!range) return false
    const targetDepth = liftTarget(range)
    if (targetDepth !== null && targetDepth !== undefined) {
      tr.lift(range, targetDepth)
      return true
    }
    return false
  }

  if (listOp === 'split_item') {
    const newBlockId = String(record.payload.newBlockId ?? '')
    const secondHit = newBlockId ? findBlockPosById(tr.doc, newBlockId) : null
    if (!secondHit) return false
    const firstItem = findAncestorByType(tr.doc, anchor.pos + 1, ['listItem'])
    const secondItem = findAncestorByType(tr.doc, secondHit.pos + 1, ['listItem'])
    if (!firstItem || !secondItem) return false
    if (firstItem.pos + firstItem.node.nodeSize === secondItem.pos) {
      tr.join(secondItem.pos, 2)
      return true
    }
    return false
  }

  if (listOp === 'lift') {
    const prevType = String(record.payload.previousListType ?? 'bulletList')
    const prevAttrs = (record.payload.previousListAttrs as Record<string, unknown> | undefined) ?? undefined
    const listType = schema.nodes[prevType]
    if (!listType) return false
    const $from = tr.doc.resolve(anchor.pos + 1)
    const range = $from.blockRange()
    if (!range) return false
    const wrapping = findWrapping(range, listType, prevAttrs)
    if (!wrapping) return false
    tr.wrap(range, wrapping)
    return true
  }

  return false
}

function revertTableChange(tr: Transform, record: TrackedStructuralRecord, schema: Schema): boolean {
  const tableOp = String(record.payload.tableOp ?? '')
  const insertedIds = Array.isArray(record.payload.cellBlockIds)
    ? (record.payload.cellBlockIds as unknown[]).filter((x): x is string => typeof x === 'string')
    : []

  if (tableOp === 'insert_table') {
    for (const cid of insertedIds) {
      const hit = findBlockPosById(tr.doc, cid)
      if (!hit) continue
      const tableHit = findAncestorByType(tr.doc, hit.pos + 1, ['table'])
      if (tableHit) {
        tr.delete(tableHit.pos, tableHit.pos + tableHit.node.nodeSize)
        return true
      }
    }
    return false
  }

  const anchor = findBlockPosById(tr.doc, record.blockId)
  const tableHit = anchor ? findAncestorByType(tr.doc, anchor.pos + 1, ['table']) : null
  if (!tableHit) return false

  if (tableOp === 'insert_row' && insertedIds.length > 0) {
    const cidHit = findBlockPosById(tr.doc, insertedIds[0])
    const rowHit = cidHit ? findAncestorByType(tr.doc, cidHit.pos + 1, ['tableRow']) : null
    if (rowHit) {
      tr.delete(rowHit.pos, rowHit.pos + rowHit.node.nodeSize)
      return true
    }
  }

  if (tableOp === 'insert_column' && insertedIds.length > 0) {
    const cellsToDelete: { pos: number; size: number }[] = []
    for (const cid of insertedIds) {
      const cidHit = findBlockPosById(tr.doc, cid)
      const cellHit = cidHit ? findAncestorByType(tr.doc, cidHit.pos + 1, ['tableCell', 'tableHeader']) : null
      if (cellHit) cellsToDelete.push({ pos: cellHit.pos, size: cellHit.node.nodeSize })
    }
    if (cellsToDelete.length > 0) {
      cellsToDelete.sort((a, b) => b.pos - a.pos)
      for (const target of cellsToDelete) {
        tr.delete(target.pos, target.pos + target.size)
      }
      return true
    }
  }

  if ((tableOp === 'toggle_header_row' || tableOp === 'toggle_header_cell') && Array.isArray(record.payload.inverseSteps)) {
    // Prefer inverse steps anchored to table pos delta
  }

  const rawSteps = record.payload.inverseSteps
  const origTablePos = typeof record.payload.tablePosAfter === 'number' ? record.payload.tablePosAfter : tableHit.pos
  const delta = tableHit.pos - origTablePos
  if (Array.isArray(rawSteps) && rawSteps.length > 0) {
    for (const raw of rawSteps) {
      if (!raw || typeof raw !== 'object') continue
      const step = Step.fromJSON(schema, shiftStepJson(raw as Record<string, unknown>, delta))
      const res = tr.maybeStep(step)
      if (res.failed) return false
    }
    return true
  }

  return false
}

export function revertStructuralRecord(tr: Transform, record: TrackedStructuralRecord): boolean {
  const schema = tr.doc.type.schema
  switch (record.kind) {
    case 'attr_change':
      return revertAttrChange(tr, record)
    case 'block_split':
      return revertBlockSplit(tr, record)
    case 'block_join':
      return revertBlockJoin(tr, record, schema)
    case 'list_change':
      return revertListChange(tr, record, schema)
    case 'table_change':
      return revertTableChange(tr, record, schema)
    default:
      return false
  }
}

export function serializeInverseSteps(baseDoc: ProseMirrorNode, steps: readonly Step[]): Record<string, unknown>[] {
  const inverse: Record<string, unknown>[] = []
  let runningDoc = baseDoc
  const tempSteps: Step[] = []
  for (const step of steps) {
    const res = step.apply(runningDoc)
    if (res.doc) {
      tempSteps.push(step.invert(runningDoc))
      runningDoc = res.doc
    }
  }
  for (let i = tempSteps.length - 1; i >= 0; i--) {
    inverse.push(tempSteps[i].toJSON() as Record<string, unknown>)
  }
  return inverse
}

export function rangesOverlap(fromA: number, toA: number, fromB: number, toB: number): boolean {
  return (fromA < toB && fromB < toA) || (fromA === toA && fromB === toB && fromA === fromB)
}

export function suggestionsOverlap(
  a: { suggestionId: string; parentId: string | null; kind: string; blockId: string; from: number; to: number; payload?: Record<string, unknown> },
  b: { suggestionId: string; parentId: string | null; kind: string; blockId: string; from: number; to: number; payload?: Record<string, unknown> },
): boolean {
  if (a.suggestionId === b.suggestionId) return false
  if (a.parentId === b.suggestionId || b.parentId === a.suggestionId) return false

  const isInline = (kind: string) => kind === 'insert' || kind === 'delete' || kind === 'replace' || kind === 'mark_change'
  const aBlocks = [a.blockId, ...(typeof a.payload?.secondBlockId === 'string' ? [a.payload.secondBlockId] : [])]
  const bBlocks = [b.blockId, ...(typeof b.payload?.secondBlockId === 'string' ? [b.payload.secondBlockId] : [])]

  const sharesBlock = aBlocks.some(id => bBlocks.includes(id))
  if (!sharesBlock) {
    if (a.kind === 'table_change' && b.kind === 'table_change') {
      const aRow = a.payload?.rowIndex
      const bRow = b.payload?.rowIndex
      const aCol = a.payload?.colIndex
      const bCol = b.payload?.colIndex
      if (aRow !== undefined && bRow !== undefined && aRow === bRow && aCol !== undefined && bCol !== undefined && aCol === bCol) {
        return true
      }
    }
    return false
  }

  if (isInline(a.kind) && isInline(b.kind)) {
    return rangesOverlap(a.from, a.to, b.from, b.to)
  }
  if (a.kind === 'attr_change' && b.kind === 'attr_change') {
    if (a.payload?.nodeType !== b.payload?.nodeType || a.payload?.attr !== b.payload?.attr) return false
    if (a.payload?.nodeType === 'link') return rangesOverlap(a.from, a.to, b.from, b.to)
    return true
  }
  if ((isInline(a.kind) && b.kind === 'attr_change') || (isInline(b.kind) && a.kind === 'attr_change')) {
    const attrItem = a.kind === 'attr_change' ? a : b
    const inlineItem = attrItem === a ? b : a
    if (attrItem.payload?.nodeType === 'link') {
      return rangesOverlap(inlineItem.from, inlineItem.to, attrItem.from, attrItem.to)
    }
    return false
  }
  if (a.kind === 'block_join' || b.kind === 'block_join') {
    const joinItem = a.kind === 'block_join' ? a : b
    const other = joinItem === a ? b : a
    const secondId = joinItem.payload?.secondBlockId
    if (typeof secondId === 'string' && other.blockId === secondId) return true
    if (isInline(other.kind) && other.blockId === joinItem.blockId && typeof joinItem.payload?.joinOffset === 'number') {
      return other.to > joinItem.payload.joinOffset || (other.from === other.to && other.from >= joinItem.payload.joinOffset)
    }
    return true
  }
  if (a.kind === 'block_split' || b.kind === 'block_split') {
    const split = a.kind === 'block_split' ? a : b
    const other = split === a ? b : a
    if (isInline(other.kind)) {
      if (split.payload?.splitKind === 'insertion') return false
      return other.to > split.from || (other.from === other.to && other.from >= split.from)
    }
    if (other.kind === 'attr_change') return false
    return true
  }

  return true
}

export { Slice, Fragment, ReplaceStep, ReplaceAroundStep }
