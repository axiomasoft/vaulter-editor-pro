import type { Mark, Node as ProseMirrorNode } from '@tiptap/pm/model'
import { Selection, TextSelection } from '@tiptap/pm/state'
import type { EditorState, Transaction } from '@tiptap/pm/state'
import {
  AddMarkStep,
  AttrStep,
  RemoveMarkStep,
  ReplaceAroundStep,
  ReplaceStep,
  Step,
  Transform,
} from '@tiptap/pm/transform'
import type { SuggestionCapabilities } from '../types/review'
import {
  extractBlockIdsFromNode,
  findAncestorByType,
  findBlockPosById,
  isCapabilityAllowed,
  isDangerousAttrValue,
  isSafeLinkHref,
  isTableNodeTextual,
  serializeInverseSteps,
  suggestionsOverlap,
  type TrackedStructuralRecord,
} from './structuralReverter'
import {
  REVIEW_SUGGESTION_MARK,
  suggestionMarker,
  type TrackedStructuralSuggestion,
  type TrackedSuggestion,
} from './trackedProjection'

export interface TrackStructureResult {
  transaction: Transaction
  added: TrackedStructuralSuggestion[]
  addedRecords: TrackedStructuralRecord[]
  updatedRecords: TrackedStructuralRecord[]
  removedIds: string[]
}

export type TrackStructureOutcome =
  | { status: 'handled'; result: TrackStructureResult }
  | { status: 'unsupported'; reason: string }
  | { status: 'not_structural' }

const ALLOWED_TEXTBLOCK_TYPES = new Set(['paragraph', 'heading'])
const SUPPORTED_NODE_TYPES = new Set([
  'doc',
  'text',
  'hardBreak',
  'paragraph',
  'heading',
  'bulletList',
  'orderedList',
  'listItem',
  'table',
  'tableRow',
  'tableCell',
  'tableHeader',
])

function isMarkChangeStep(step: Step): step is AddMarkStep | RemoveMarkStep {
  return step instanceof AddMarkStep || step instanceof RemoveMarkStep
}

function getLinkMarksAtRange(doc: ProseMirrorNode, from: number, to: number): Mark[] {
  const found: Mark[] = []
  doc.nodesBetween(from, Math.min(to, doc.content.size), node => {
    if (!node.isText) return
    for (const m of node.marks) {
      if (m.type.name === 'link') found.push(m)
    }
  })
  return found
}

function containsUnsupportedNodes(docOrFragment: { descendants?: ProseMirrorNode['descendants']; forEach?: ProseMirrorNode['content']['forEach'] }): boolean {
  let unsupported = false
  if ('descendants' in docOrFragment && typeof docOrFragment.descendants === 'function') {
    docOrFragment.descendants(node => {
      if (!SUPPORTED_NODE_TYPES.has(node.type.name)) {
        unsupported = true
        return false
      }
      return undefined
    })
  } else if ('forEach' in docOrFragment && typeof docOrFragment.forEach === 'function') {
    docOrFragment.forEach(node => {
      if (!SUPPORTED_NODE_TYPES.has(node.type.name)) {
        unsupported = true
        return
      }
      if (containsUnsupportedNodes(node)) unsupported = true
    })
  }
  return unsupported
}

function sliceContainsOnlyMarkSafeInlineText(doc: ProseMirrorNode, from: number, to: number): boolean {
  let ok = true
  doc.nodesBetween(from, Math.min(to, doc.content.size), (node, pos) => {
    if (!ok) return false
    if (node.isInline && !node.isText && Math.max(from, pos) < Math.min(to, pos + node.nodeSize)) {
      ok = false
      return false
    }
    return undefined
  })
  return ok
}

function canonicalBlockRange(
  block: ProseMirrorNode,
  blockStart: number,
  pmFrom: number,
  pmTo: number,
): { from: number; to: number; parentId: string | null; quoteText: string } | null {
  const localFrom = pmFrom - (blockStart + 1)
  const localTo = pmTo - (blockStart + 1)
  if (localFrom < 0 || localTo > block.content.size || localFrom >= localTo) return null

  // Determine parentId if inside a pending insertion run cleanly.
  let offset = 0
  let canonicalFrom: number | undefined
  let canonicalTo: number | undefined
  let quoteText = ''
  let detectedParent: string | null | undefined

  let pmOffset = 0
  block.forEach(child => {
    const childStart = pmOffset
    const childEnd = pmOffset + child.nodeSize
    pmOffset = childEnd

    const markers = child.marks.flatMap(m => {
      const marker = suggestionMarker(m)
      return marker ? [marker] : []
    })
    const insertMark = markers.find(m => m.kind === 'insert')

    const spanStart = Math.max(childStart, localFrom)
    const spanEnd = Math.min(childEnd, localTo)
    if (spanStart < spanEnd) {
      const parentForSpan = insertMark?.suggestionId ?? null
      if (detectedParent === undefined) {
        detectedParent = parentForSpan
      } else if (detectedParent !== parentForSpan) {
        detectedParent = '__MIXED__'
      }
      if (child.isText && child.text) {
        quoteText += child.text.slice(spanStart - childStart, spanEnd - childStart)
      }
      canonicalFrom ??= offset + (spanStart - childStart)
      canonicalTo = offset + (spanEnd - childStart)
    }

    if (!insertMark) {
      offset += child.nodeSize
    }
  })

  if (detectedParent === '__MIXED__' || canonicalFrom === undefined || canonicalTo === undefined) {
    return null
  }

  return {
    from: canonicalFrom,
    to: canonicalTo,
    parentId: detectedParent ?? null,
    quoteText,
  }
}

function blockTextLength(block: ProseMirrorNode, excludePendingInserts = true): number {
  let len = 0
  block.forEach(child => {
    if (excludePendingInserts && child.marks.some(m => suggestionMarker(m)?.kind === 'insert')) {
      return
    }
    if (child.isText) {
      len += child.text?.length ?? 0
    } else if (child.isInline) {
      len += 1
    }
  })
  return len
}

function firstAddressableBlockId(node: ProseMirrorNode): string | null {
  if (typeof node.attrs?.blockId === 'string' && node.attrs.blockId !== '') {
    return node.attrs.blockId
  }
  const ids = extractBlockIdsFromNode(node)
  return ids[0] ?? null
}

function locateTableCellCoords(
  table: ProseMirrorNode,
  targetBlockId: string,
): { rowIndex: number; colIndex: number } | null {
  for (let r = 0; r < table.childCount; r++) {
    const row = table.child(r)
    for (let c = 0; c < row.childCount; c++) {
      const cell = row.child(c)
      const ids = extractBlockIdsFromNode(cell)
      if (ids.includes(targetBlockId)) {
        return { rowIndex: r, colIndex: c }
      }
    }
  }
  return null
}

function findOverlapConflict(
  candidate: {
    suggestionId: string
    parentId: string | null
    kind: string
    blockId: string
    from: number
    to: number
    payload: Record<string, unknown>
  },
  author: string,
  existingSuggestions: readonly TrackedSuggestion[],
): { conflicted: boolean; conflictWithId: string | null; conflictReason: string | null } {
  for (const peer of existingSuggestions) {
    if (peer.suggestionId === candidate.suggestionId) continue
    if (candidate.parentId === peer.suggestionId || peer.parentId === candidate.suggestionId) continue
    const peerPayload = 'payload' in peer ? peer.payload : undefined
    if (suggestionsOverlap(candidate, {
      suggestionId: peer.suggestionId,
      parentId: peer.parentId,
      kind: peer.kind,
      blockId: peer.blockId,
      from: peer.from,
      to: peer.to,
      payload: peerPayload,
    })) {
      const peerAuthor = 'author' in peer ? peer.author : undefined
      if (peerAuthor !== undefined && peerAuthor === author && candidate.kind === peer.kind && candidate.kind === 'mark_change') {
        // Different mark types on same text span do not conflict with same author
        const sameMark = peerPayload?.markName === candidate.payload.markName
        if (!sameMark) continue
      }
      return {
        conflicted: true,
        conflictWithId: peer.suggestionId,
        conflictReason: `conflicts_with_${peer.kind}`,
      }
    }
  }
  return { conflicted: false, conflictWithId: null, conflictReason: null }
}

function copySelectionSafe(incoming: Transaction, outgoing: Transaction): void {
  const maxPos = outgoing.doc.content.size
  if (incoming.selection instanceof TextSelection) {
    const anchor = Math.max(0, Math.min(incoming.selection.anchor, maxPos))
    const head = Math.max(0, Math.min(incoming.selection.head, maxPos))
    outgoing.setSelection(TextSelection.between(outgoing.doc.resolve(anchor), outgoing.doc.resolve(head)))
    return
  }
  try {
    outgoing.setSelection(Selection.fromJSON(outgoing.doc, incoming.selection.toJSON()))
  } catch {
    const safePos = Math.max(0, Math.min(incoming.selection.from, maxPos))
    outgoing.setSelection(Selection.near(outgoing.doc.resolve(safePos)))
  }
}

function cloneMeta(incoming: Transaction, outgoing: Transaction): void {
  if (incoming.scrolledIntoView) outgoing.scrollIntoView()
  for (const key of ['addToHistory', 'uiEvent', 'pointer']) {
    const val = incoming.getMeta(key)
    if (val !== undefined) outgoing.setMeta(key, val)
  }
}

function applyStepsWithFreshCellIds(
  doc: ProseMirrorNode,
  steps: readonly Step[],
  createId: () => string,
): { tr: Transform; insertedCellBlockIds: string[] } | null {
  const tr = new Transform(doc)
  const insertedCellBlockIds: string[] = []

  for (const step of steps) {
    const res = tr.maybeStep(step)
    if (res.failed) return null
  }

  // Ensure every cell paragraph inside tables has a unique blockId distinct from existing ones before this tx.
  // Actually any duplicate blockId in tr.doc belonging to newly inserted cells gets a fresh ID.
  const seen = new Set<string>()
  const updates: { pos: number; node: ProseMirrorNode; newId: string }[] = []
  tr.doc.descendants((node, pos) => {
    if (!ALLOWED_TEXTBLOCK_TYPES.has(node.type.name)) return undefined
    const curId = typeof node.attrs?.blockId === 'string' ? node.attrs.blockId : ''
    if ((!curId || seen.has(curId)) && findAncestorByType(tr.doc, pos + 1, ['table'])) {
      const fresh = createId()
      seen.add(fresh)
      updates.push({ pos, node, newId: fresh })
    } else if (curId) {
      seen.add(curId)
    }
    return undefined
  })

  for (const u of updates) {
    tr.setNodeMarkup(u.pos, undefined, { ...u.node.attrs, blockId: u.newId })
    insertedCellBlockIds.push(u.newId)
  }

  return { tr, insertedCellBlockIds }
}

function buildRecordAndSuggestion(
  id: string,
  groupId: string,
  parentId: string | null,
  kind: TrackedStructuralRecord['kind'],
  blockId: string,
  from: number,
  to: number,
  author: string,
  payload: Record<string, unknown>,
  conflict: { conflicted: boolean; conflictWithId: string | null; conflictReason: string | null },
): { suggestion: TrackedStructuralSuggestion; record: TrackedStructuralRecord } {
  const record: TrackedStructuralRecord = {
    suggestionId: id,
    groupId,
    parentId,
    kind,
    blockId,
    from,
    to,
    author,
    conflicted: conflict.conflicted,
    conflictReason: conflict.conflictReason,
    conflictWithId: conflict.conflictWithId,
    payload,
  }
  const suggestion: TrackedStructuralSuggestion = { ...record }
  return { suggestion, record }
}

export function trackStructure(
  state: EditorState,
  incoming: Transaction,
  author: string,
  createId: () => string,
  capabilities: SuggestionCapabilities,
  currentRecords: readonly TrackedStructuralRecord[],
  existingSuggestions: readonly TrackedSuggestion[],
): TrackStructureOutcome {
  const steps = incoming.steps
  if (steps.length === 0) {
    return { status: 'not_structural' }
  }

  // First check whether any slice introduces disallowed schema nodes (blockquote, codeBlock, image, etc.)
  for (const step of steps) {
    if (step instanceof ReplaceStep || step instanceof ReplaceAroundStep) {
      if (containsUnsupportedNodes(step.slice.content)) {
        return { status: 'unsupported', reason: 'unsupported_node_type' }
      }
    }
  }

  // 1. Mark changes (AddMarkStep / RemoveMarkStep only)
  if (steps.every(isMarkChangeStep)) {
    const outgoing = state.tr
    const groupId = createId()
    const added: TrackedStructuralSuggestion[] = []
    const addedRecords: TrackedStructuralRecord[] = []
    const removedIds: string[] = []
    const reviewMarkType = state.schema.marks[REVIEW_SUGGESTION_MARK]

    // Detect link attribute replacement (`RemoveMarkStep('link')` + `AddMarkStep('link')` or `AddMarkStep('link')` over existing link)
    if (steps.every(s => s.mark.type.name === 'link')) {
      const addLinkStep = steps.find((s): s is AddMarkStep => s instanceof AddMarkStep)
      if (addLinkStep) {
        const href = addLinkStep.mark.attrs?.href
        if (!isSafeLinkHref(href)) {
          return { status: 'unsupported', reason: 'unsafe_link_href' }
        }
        const allowedLinkAttrs = capabilities.allowed_attributes.link ?? []
        for (const [k, v] of Object.entries(addLinkStep.mark.attrs ?? {})) {
          if (v !== null && v !== undefined && !allowedLinkAttrs.includes(k)) {
            return { status: 'unsupported', reason: `unsupported_link_attr:${k}` }
          }
          if (isDangerousAttrValue(v)) {
            return { status: 'unsupported', reason: `dangerous_link_attr:${k}` }
          }
        }
        const existingLinks = getLinkMarksAtRange(state.doc, addLinkStep.from, addLinkStep.to)
        const prevLink = existingLinks[0]
        const removeLinkStep = steps.find((s): s is RemoveMarkStep => s instanceof RemoveMarkStep)
        const oldAttrs = removeLinkStep?.mark.attrs ?? prevLink?.attrs
        if (oldAttrs && isCapabilityAllowed('attr_change', capabilities)) {
          const changedKeys = Object.keys({ ...oldAttrs, ...addLinkStep.mark.attrs }).filter(
            k => (oldAttrs[k] ?? null) !== (addLinkStep.mark.attrs?.[k] ?? null),
          )
          if (changedKeys.length === 1) {
            const attrKey = changedKeys[0]
            const $from = state.doc.resolve(addLinkStep.from)
            const $to = state.doc.resolve(addLinkStep.to)
            if ($from.sameParent($to) && ALLOWED_TEXTBLOCK_TYPES.has($from.parent.type.name)) {
              const blockId = typeof $from.parent.attrs.blockId === 'string' ? $from.parent.attrs.blockId : ''
              const canon = blockId ? canonicalBlockRange($from.parent, $from.before(), addLinkStep.from, addLinkStep.to) : null
              if (blockId && canon) {
                for (const s of steps) outgoing.step(s)
                cloneMeta(incoming, outgoing)
                copySelectionSafe(incoming, outgoing)
                const id = createId()
                const payload = {
                  nodeType: 'link',
                  attr: attrKey,
                  oldValue: oldAttrs[attrKey] ?? null,
                  newValue: addLinkStep.mark.attrs?.[attrKey] ?? null,
                }
                const conflict = findOverlapConflict(
                  { suggestionId: id, parentId: canon.parentId, kind: 'attr_change', blockId, from: canon.from, to: canon.to, payload },
                  author,
                  existingSuggestions,
                )
                const { suggestion, record } = buildRecordAndSuggestion(
                  id,
                  groupId,
                  canon.parentId,
                  'attr_change',
                  blockId,
                  canon.from,
                  canon.to,
                  author,
                  payload,
                  conflict,
                )
                return {
                  status: 'handled',
                  result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] },
                }
              }
            }
          }
        }
      }
    }

    if (!isCapabilityAllowed('mark_change', capabilities)) {
      return { status: 'unsupported', reason: 'unsupported_kind:mark_change' }
    }

    for (const step of steps) {
      const markName = step.mark.type.name
      if (markName === REVIEW_SUGGESTION_MARK) {
        return { status: 'not_structural' }
      }
      if (!capabilities.allowed_marks.includes(markName)) {
        return { status: 'unsupported', reason: `unsupported_mark:${markName}` }
      }
      if (markName === 'link') {
        if (step instanceof AddMarkStep && !isSafeLinkHref(step.mark.attrs?.href)) {
          return { status: 'unsupported', reason: 'unsafe_link_href' }
        }
        const allowedLinkAttrs = capabilities.allowed_attributes.link ?? []
        for (const [k, v] of Object.entries(step.mark.attrs ?? {})) {
          if (v !== null && v !== undefined && !allowedLinkAttrs.includes(k)) {
            return { status: 'unsupported', reason: `unsupported_link_attr:${k}` }
          }
          if (isDangerousAttrValue(v)) {
            return { status: 'unsupported', reason: `dangerous_link_attr:${k}` }
          }
        }
      } else {
        for (const v of Object.values(step.mark.attrs ?? {})) {
          if (isDangerousAttrValue(v)) {
            return { status: 'unsupported', reason: 'dangerous_mark_attr' }
          }
        }
      }

      const $from = state.doc.resolve(step.from)
      const $to = state.doc.resolve(step.to)
      if (!$from.sameParent($to) || !ALLOWED_TEXTBLOCK_TYPES.has($from.parent.type.name)) {
        return { status: 'unsupported', reason: 'unsupported_mark_target' }
      }
      const blockId = typeof $from.parent.attrs.blockId === 'string' ? $from.parent.attrs.blockId : ''
      if (!blockId) {
        return { status: 'unsupported', reason: 'missing_block_id' }
      }
      if (!sliceContainsOnlyMarkSafeInlineText(state.doc, step.from, step.to)) {
        return { status: 'unsupported', reason: 'unsupported_mark_inline_node' }
      }
      const canon = canonicalBlockRange($from.parent, $from.before(), step.from, step.to)
      if (!canon) {
        return { status: 'unsupported', reason: 'mixed_suggestion_boundary' }
      }

      const operation = step instanceof AddMarkStep ? 'added' : 'removed'
      const markAttrs = step.mark.attrs ? { ...step.mark.attrs } : {}

      // Check if an existing opposite mark_change by the same author covers the exact range -> cancel cleanly.
      const opposite = currentRecords.find(r =>
        r.kind === 'mark_change'
        && r.blockId === blockId
        && r.from === canon.from
        && r.to === canon.to
        && r.author === author
        && r.payload.markName === markName
        && r.payload.operation !== operation,
      )
      outgoing.step(step)
      if (opposite) {
        removedIds.push(opposite.suggestionId)
        outgoing.doc.nodesBetween(step.from, step.to, (node, pos) => {
          if (!node.isText) return
          const existingReviewMark = node.marks.find(m => m.type === reviewMarkType && m.attrs.suggestionId === opposite.suggestionId)
          if (existingReviewMark) {
            outgoing.removeMark(Math.max(step.from, pos), Math.min(step.to, pos + node.nodeSize), existingReviewMark)
          }
        })
        continue
      }

      const suggestionId = createId()
      const payload = {
        markName,
        markAttrs,
        operation,
        quoteText: canon.quoteText,
      }
      const conflict = findOverlapConflict(
        { suggestionId, parentId: canon.parentId, kind: 'mark_change', blockId, from: canon.from, to: canon.to, payload },
        author,
        existingSuggestions,
      )
      outgoing.addMark(step.from, step.to, reviewMarkType.create({
        suggestionId,
        groupId,
        parentId: canon.parentId,
        author,
        kind: 'mark_change',
        markName,
        markAttrs,
        operation,
      }))
      const { suggestion, record } = buildRecordAndSuggestion(
        suggestionId,
        groupId,
        canon.parentId,
        'mark_change',
        blockId,
        canon.from,
        canon.to,
        author,
        payload,
        conflict,
      )
      added.push(suggestion)
      addedRecords.push(record)
    }

    cloneMeta(incoming, outgoing)
    copySelectionSafe(incoming, outgoing)
    return {
      status: 'handled',
      result: { transaction: outgoing, added, addedRecords, updatedRecords: [], removedIds },
    }
  }

  // 2. Direct AttrStep attribute change
  if (steps.length === 1 && steps[0] instanceof AttrStep) {
    const step = steps[0]
    if (step.attr === 'blockId') {
      return { status: 'unsupported', reason: 'immutable_block_id' }
    }
    if (!isCapabilityAllowed('attr_change', capabilities)) {
      return { status: 'unsupported', reason: 'unsupported_kind:attr_change' }
    }
    const targetNode = state.doc.nodeAt(step.pos)
    if (!targetNode) {
      return { status: 'unsupported', reason: 'missing_target_node' }
    }
    const nodeType = targetNode.type.name
    const allowedAttrs = capabilities.allowed_attributes[nodeType]
    if (!allowedAttrs || !allowedAttrs.includes(step.attr)) {
      return { status: 'unsupported', reason: `unsupported_attr:${nodeType}.${step.attr}` }
    }
    if (isDangerousAttrValue(step.value)) {
      return { status: 'unsupported', reason: 'dangerous_attr_value' }
    }

    let extraPayload: Record<string, unknown> = {}
    if (nodeType === 'tableCell' || nodeType === 'tableHeader') {
      const tableHit = findAncestorByType(state.doc, step.pos + 1, ['table'])
      if (!tableHit || !isTableNodeTextual(tableHit.node, capabilities.allowed_marks)) {
        return { status: 'unsupported', reason: 'non_textual_table' }
      }
      const blockIdInside = firstAddressableBlockId(targetNode)
      if (!blockIdInside) return { status: 'unsupported', reason: 'missing_block_id' }
      const coords = locateTableCellCoords(tableHit.node, blockIdInside)
      if (coords) extraPayload = coords
    }
    const blockId = firstAddressableBlockId(targetNode)
    if (!blockId) {
      return { status: 'unsupported', reason: 'missing_block_id' }
    }

    const oldValue = targetNode.attrs?.[step.attr] ?? null
    const newValue = step.value ?? null
    const outgoing = state.tr
    outgoing.step(step)
    cloneMeta(incoming, outgoing)
    copySelectionSafe(incoming, outgoing)

    return finalizeAttrChange(
      outgoing,
      blockId,
      nodeType,
      step.attr,
      oldValue,
      newValue,
      extraPayload,
      author,
      createId,
      currentRecords,
      existingSuggestions,
    )
  }

  // 3. Single ReplaceAroundStep: attr_change (same node type), list toggle, list wrap, list sink, table header toggle, or node_type_change (rejected if disabled)
  if (steps.length === 1 && steps[0] instanceof ReplaceAroundStep) {
    const step = steps[0]
    const isSingleWrapperReplace = step.gapFrom === step.from + 1
      && step.gapTo === step.to - 1
      && step.insert === 1
      && step.slice.content.childCount === 1

    if (isSingleWrapperReplace) {
      const oldNode = state.doc.nodeAt(step.from)
      const newNode = step.slice.content.firstChild
      if (!oldNode || !newNode) {
        return { status: 'unsupported', reason: 'invalid_wrapper_step' }
      }
      const oldType = oldNode.type.name
      const newType = newNode.type.name

      // 3a. Same node type -> attr_change (setHeading / updateAttributes / setCellAttribute)
      if (oldType === newType) {
        if (!isCapabilityAllowed('attr_change', capabilities)) {
          return { status: 'unsupported', reason: 'unsupported_kind:attr_change' }
        }
        const changedKeys = Object.keys({ ...oldNode.attrs, ...newNode.attrs }).filter(
          k => (oldNode.attrs?.[k] ?? null) !== (newNode.attrs?.[k] ?? null),
        )
        if (changedKeys.includes('blockId')) {
          return { status: 'unsupported', reason: 'immutable_block_id' }
        }
        if (changedKeys.length !== 1) {
          return { status: 'unsupported', reason: 'unsupported_multi_attr_change' }
        }
        const attrKey = changedKeys[0]
        const allowedAttrs = capabilities.allowed_attributes[oldType]
        if (!allowedAttrs || !allowedAttrs.includes(attrKey)) {
          return { status: 'unsupported', reason: `unsupported_attr:${oldType}.${attrKey}` }
        }
        const newVal = newNode.attrs?.[attrKey] ?? null
        if (isDangerousAttrValue(newVal)) {
          return { status: 'unsupported', reason: 'dangerous_attr_value' }
        }
        let extraPayload: Record<string, unknown> = {}
        if (oldType === 'tableCell' || oldType === 'tableHeader') {
          const tableHit = findAncestorByType(state.doc, step.from + 1, ['table'])
          if (!tableHit || !isTableNodeTextual(tableHit.node, capabilities.allowed_marks)) {
            return { status: 'unsupported', reason: 'non_textual_table' }
          }
          const cellBlockId = firstAddressableBlockId(oldNode)
          if (!cellBlockId) return { status: 'unsupported', reason: 'missing_block_id' }
          const coords = locateTableCellCoords(tableHit.node, cellBlockId)
          if (coords) extraPayload = coords
        }
        const blockId = firstAddressableBlockId(oldNode)
        if (!blockId) return { status: 'unsupported', reason: 'missing_block_id' }

        const outgoing = state.tr
        outgoing.step(step)
        cloneMeta(incoming, outgoing)
        copySelectionSafe(incoming, outgoing)

        return finalizeAttrChange(
          outgoing,
          blockId,
          oldType,
          attrKey,
          oldNode.attrs?.[attrKey] ?? null,
          newVal,
          extraPayload,
          author,
          createId,
          currentRecords,
          existingSuggestions,
        )
      }

      // 3b. List toggle (bulletList <-> orderedList)
      if ((oldType === 'bulletList' || oldType === 'orderedList') && (newType === 'bulletList' || newType === 'orderedList')) {
        if (!isCapabilityAllowed('list_change', capabilities) || !capabilities.list_operations.includes('toggle_type')) {
          return { status: 'unsupported', reason: 'unsupported_list_op:toggle_type' }
        }
        const blockId = firstAddressableBlockId(oldNode)
        if (!blockId) return { status: 'unsupported', reason: 'missing_block_id' }

        const outgoing = state.tr
        outgoing.step(step)
        cloneMeta(incoming, outgoing)
        copySelectionSafe(incoming, outgoing)

        const id = createId()
        const groupId = createId()
        const payload = {
          listOp: 'toggle_type',
          listType: newType,
          previousListType: oldType,
          previousListAttrs: { ...oldNode.attrs },
        }
        const conflict = findOverlapConflict(
          { suggestionId: id, parentId: null, kind: 'list_change', blockId, from: 0, to: 0, payload },
          author,
          existingSuggestions,
        )
        const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'list_change', blockId, 0, 0, author, payload, conflict)
        return { status: 'handled', result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] } }
      }

      // 3c. Single cell header toggle (`tableCell` <-> `tableHeader`)
      if ((oldType === 'tableCell' || oldType === 'tableHeader') && (newType === 'tableCell' || newType === 'tableHeader')) {
        return handleTableSteps(state, incoming, author, createId, capabilities, existingSuggestions, 'toggle_header_cell')
      }

      // 3d. Any other node_type_change (e.g. paragraph <-> heading) -> gate via capabilities
      const transition = `${oldType}->${newType}`
      if (!capabilities.node_type_change_types.includes(transition) && !capabilities.node_type_change_types.includes(newType)) {
        return { status: 'unsupported', reason: `unsupported_node_type_change:${transition}` }
      }
    }

    // 3e. Wrapping paragraph/heading into bulletList / orderedList
    const firstWrap = step.slice.content.firstChild
    if (firstWrap && (firstWrap.type.name === 'bulletList' || firstWrap.type.name === 'orderedList')) {
      const listOp = firstWrap.type.name === 'orderedList' ? 'wrap_ordered' : 'wrap_bullet'
      if (!isCapabilityAllowed('list_change', capabilities) || !capabilities.list_operations.includes(listOp)) {
        return { status: 'unsupported', reason: `unsupported_list_op:${listOp}` }
      }
      // Verify gap contains only paragraph/heading blocks with blockIds (or listItems when sinking)
      const isSink = firstWrap.content.childCount === 0 || (
        findAncestorByType(state.doc, step.gapFrom + 1, ['listItem']) !== null
      )
      const resolvedOp = isSink && capabilities.list_operations.includes('sink_item') && findAncestorByType(state.doc, step.gapFrom + 1, ['bulletList', 'orderedList']) !== null
        ? 'sink_item'
        : listOp
      if (!capabilities.list_operations.includes(resolvedOp)) {
        return { status: 'unsupported', reason: `unsupported_list_op:${resolvedOp}` }
      }
      const gapNode = state.doc.slice(step.gapFrom, step.gapTo).content
      const blockIds: string[] = []
      gapNode.descendants(child => {
        if (typeof child.attrs?.blockId === 'string' && child.attrs.blockId !== '') {
          blockIds.push(child.attrs.blockId)
        }
      })
      const anchorBlockId = blockIds[0]
      if (!anchorBlockId) return { status: 'unsupported', reason: 'missing_block_id' }

      const outgoing = state.tr
      outgoing.step(step)
      cloneMeta(incoming, outgoing)
      copySelectionSafe(incoming, outgoing)

      const id = createId()
      const groupId = createId()
      const payload = {
        listOp: resolvedOp,
        listType: firstWrap.type.name,
        secondBlockId: blockIds[1] ?? undefined,
      }
      const conflict = findOverlapConflict(
        { suggestionId: id, parentId: null, kind: 'list_change', blockId: anchorBlockId, from: 0, to: 0, payload },
        author,
        existingSuggestions,
      )
      const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'list_change', anchorBlockId, 0, 0, author, payload, conflict)
      return { status: 'handled', result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] } }
    }
  }

  // 4. Table operations (single or multi-step inside existing table OR inserting new table)
  const detectedTableOp = detectTableOp(state.doc, steps)
  if (detectedTableOp) {
    return handleTableSteps(state, incoming, author, createId, capabilities, existingSuggestions, detectedTableOp)
  }

  // 5. Block split (`ReplaceStep` with from === to, openStart === 1, openEnd === 1, two paragraph/heading children)
  if (
    steps.length === 1
    && steps[0] instanceof ReplaceStep
    && steps[0].from === steps[0].to
    && steps[0].slice.openStart === 1
    && steps[0].slice.openEnd === 1
    && steps[0].slice.content.childCount === 2
  ) {
    const step = steps[0]
    const first = step.slice.content.child(0)
    const second = step.slice.content.child(1)
    if (ALLOWED_TEXTBLOCK_TYPES.has(first.type.name) && ALLOWED_TEXTBLOCK_TYPES.has(second.type.name)) {
      if (!isCapabilityAllowed('block_split', capabilities)) {
        return { status: 'unsupported', reason: 'unsupported_kind:block_split' }
      }
      const $pos = state.doc.resolve(step.from)
      const parentBlock = $pos.parent
      const parentBlockId = typeof parentBlock.attrs?.blockId === 'string' ? parentBlock.attrs.blockId : ''
      if (!ALLOWED_TEXTBLOCK_TYPES.has(parentBlock.type.name) || !parentBlockId) {
        return { status: 'unsupported', reason: 'unsupported_split_target' }
      }

      const localPmOffset = step.from - $pos.start()
      const fullOrigLen = blockTextLength(parentBlock, true)
      // Calculate logical offset excluding unaccepted inserts before localPmOffset
      let splitOffset = 0
      let walkedPm = 0
      parentBlock.forEach(child => {
        if (walkedPm >= localPmOffset) return
        const end = walkedPm + child.nodeSize
        const take = Math.min(end, localPmOffset) - walkedPm
        if (!child.marks.some(m => suggestionMarker(m)?.kind === 'insert')) {
          splitOffset += take
        }
        walkedPm = end
      })

      const splitKind = localPmOffset >= parentBlock.content.size || fullOrigLen === 0 ? 'insertion' : 'structural'
      const newBlockId = createId()
      const outgoing = state.tr
      outgoing.step(step)

      // Assign distinct newBlockId to the right-hand block created by the split
      const rightStart = outgoing.mapping.map(step.from, 1)
      const $right = outgoing.doc.resolve(rightStart)
      const rightBlockPos = $right.before()
      const rightBlockNode = $right.parent
      outgoing.setNodeMarkup(rightBlockPos, undefined, { ...rightBlockNode.attrs, blockId: newBlockId })
      cloneMeta(incoming, outgoing)
      copySelectionSafe(incoming, outgoing)

      const id = createId()
      const groupId = createId()
      const payload = {
        splitKind,
        newBlockId,
        newNodeType: rightBlockNode.type.name,
        newAttrs: { ...rightBlockNode.attrs, blockId: newBlockId },
        position: 'after',
        offsetUtf16: splitOffset,
      }
      const conflict = findOverlapConflict(
        { suggestionId: id, parentId: null, kind: 'block_split', blockId: parentBlockId, from: splitOffset, to: splitOffset, payload },
        author,
        existingSuggestions,
      )
      const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'block_split', parentBlockId, splitOffset, splitOffset, author, payload, conflict)
      return { status: 'handled', result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] } }
    }
  }

  // 6. List item split (`ReplaceStep` with from === to, openStart === 2, openEnd === 2, [listItem, listItem])
  if (
    steps.length === 1
    && steps[0] instanceof ReplaceStep
    && steps[0].from === steps[0].to
    && steps[0].slice.openStart === 2
    && steps[0].slice.openEnd === 2
    && steps[0].slice.content.childCount === 2
    && steps[0].slice.content.child(0).type.name === 'listItem'
    && steps[0].slice.content.child(1).type.name === 'listItem'
  ) {
    if (!isCapabilityAllowed('list_change', capabilities) || !capabilities.list_operations.includes('split_item')) {
      return { status: 'unsupported', reason: 'unsupported_list_op:split_item' }
    }
    const step = steps[0]
    const $pos = state.doc.resolve(step.from)
    const blockId = typeof $pos.parent.attrs?.blockId === 'string' ? $pos.parent.attrs.blockId : ''
    if (!blockId) return { status: 'unsupported', reason: 'missing_block_id' }
    const newBlockId = createId()
    const outgoing = state.tr
    outgoing.step(step)
    const rightPos = outgoing.mapping.map(step.from, 1)
    const $right = outgoing.doc.resolve(rightPos)
    outgoing.setNodeMarkup($right.before(), undefined, { ...$right.parent.attrs, blockId: newBlockId })
    cloneMeta(incoming, outgoing)
    copySelectionSafe(incoming, outgoing)

    const id = createId()
    const groupId = createId()
    const payload = { listOp: 'split_item', newBlockId }
    const conflict = findOverlapConflict(
      { suggestionId: id, parentId: null, kind: 'list_change', blockId, from: 0, to: 0, payload },
      author,
      existingSuggestions,
    )
    const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'list_change', blockId, 0, 0, author, payload, conflict)
    return { status: 'handled', result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] } }
  }

  // 7. List lift (removing list/listItem wrapper while preserving block text)
  if (findAncestorByType(state.doc, steps[0] instanceof ReplaceStep ? steps[0].from + 1 : 0, ['listItem']) && isLiftListTransaction(state.doc, steps)) {
    if (!isCapabilityAllowed('list_change', capabilities) || !capabilities.list_operations.includes('lift')) {
      return { status: 'unsupported', reason: 'unsupported_list_op:lift' }
    }
    const firstFrom = steps[0] instanceof ReplaceStep || steps[0] instanceof ReplaceAroundStep ? steps[0].from : 0
    const listAncestor = findAncestorByType(state.doc, firstFrom + 1, ['bulletList', 'orderedList'])
    const outgoing = state.tr
    for (const step of steps) outgoing.step(step)
    const liftedIds = extractBlockIdsFromNode(outgoing.doc)
    const blockId = liftedIds[0] ?? firstAddressableBlockId(listAncestor?.node ?? outgoing.doc)
    if (!blockId) return { status: 'unsupported', reason: 'missing_block_id' }
    cloneMeta(incoming, outgoing)
    copySelectionSafe(incoming, outgoing)

    const id = createId()
    const groupId = createId()
    const payload = {
      listOp: 'lift',
      previousListType: listAncestor?.node.type.name ?? 'bulletList',
      previousListAttrs: listAncestor ? { ...listAncestor.node.attrs } : null,
      inverseSteps: serializeInverseSteps(state.doc, steps),
    }
    const conflict = findOverlapConflict(
      { suggestionId: id, parentId: null, kind: 'list_change', blockId, from: 0, to: 0, payload },
      author,
      existingSuggestions,
    )
    const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'list_change', blockId, 0, 0, author, payload, conflict)
    return { status: 'handled', result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] } }
  }

  // 8. Block join (`ReplaceStep` with slice.size === 0, to - from === 2 between two adjacent paragraph/heading blocks)
  if (
    steps.length === 1
    && steps[0] instanceof ReplaceStep
    && steps[0].slice.size === 0
    && steps[0].to - steps[0].from === 2
  ) {
    const step = steps[0]
    const $from = state.doc.resolve(step.from)
    const $to = state.doc.resolve(step.to)
    const leftBlock = $from.parent
    const rightBlock = $to.parent
    if (
      ALLOWED_TEXTBLOCK_TYPES.has(leftBlock.type.name)
      && ALLOWED_TEXTBLOCK_TYPES.has(rightBlock.type.name)
      && $from.pos === $from.end()
      && $to.pos === $to.start()
      && $from.depth === $to.depth
    ) {
      if (!isCapabilityAllowed('block_join', capabilities)) {
        return { status: 'unsupported', reason: 'unsupported_kind:block_join' }
      }
      const leftBlockId = typeof leftBlock.attrs?.blockId === 'string' ? leftBlock.attrs.blockId : ''
      const rightBlockId = typeof rightBlock.attrs?.blockId === 'string' ? rightBlock.attrs.blockId : ''
      if (!leftBlockId || !rightBlockId) {
        return { status: 'unsupported', reason: 'missing_block_id' }
      }
      const joinOffset = blockTextLength(leftBlock, false)
      const outgoing = state.tr
      outgoing.step(step)
      cloneMeta(incoming, outgoing)
      copySelectionSafe(incoming, outgoing)

      const id = createId()
      const groupId = createId()
      const payload = {
        secondBlockId: rightBlockId,
        secondNodeType: rightBlock.type.name,
        secondAttrs: { ...rightBlock.attrs },
        joinOffset,
      }
      const conflict = findOverlapConflict(
        { suggestionId: id, parentId: null, kind: 'block_join', blockId: leftBlockId, from: joinOffset, to: joinOffset, payload },
        author,
        existingSuggestions,
      )
      const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'block_join', leftBlockId, joinOffset, joinOffset, author, payload, conflict)
      return { status: 'handled', result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] } }
    }
  }

  // Check if pure text ReplaceSteps (handled by trackText)
  if (steps.every(s => {
    if (!(s instanceof ReplaceStep) || s.slice.openStart !== 0 || s.slice.openEnd !== 0) return false
    let inlineTextOnly = true
    s.slice.content.forEach(n => {
      if (!n.isText) inlineTextOnly = false
    })
    return inlineTextOnly
  })) {
    return { status: 'not_structural' }
  }

  return { status: 'unsupported', reason: 'unsupported_structural_transaction' }
}

function finalizeAttrChange(
  outgoing: Transaction,
  blockId: string,
  nodeType: string,
  attr: string,
  oldValue: unknown,
  newValue: unknown,
  extraPayload: Record<string, unknown>,
  author: string,
  createId: () => string,
  currentRecords: readonly TrackedStructuralRecord[],
  existingSuggestions: readonly TrackedSuggestion[],
): TrackStructureOutcome {
  const existingSame = currentRecords.find(r =>
    r.kind === 'attr_change'
    && r.blockId === blockId
    && r.payload.nodeType === nodeType
    && r.payload.attr === attr
    && r.author === author
    && r.payload.rowIndex === extraPayload.rowIndex
    && r.payload.colIndex === extraPayload.colIndex,
  )

  if (existingSame) {
    if (existingSame.payload.oldValue === newValue) {
      return {
        status: 'handled',
        result: {
          transaction: outgoing,
          added: [],
          addedRecords: [],
          updatedRecords: [],
          removedIds: [existingSame.suggestionId],
        },
      }
    }
    const updated: TrackedStructuralRecord = {
      ...existingSame,
      payload: { ...existingSame.payload, newValue },
    }
    return {
      status: 'handled',
      result: {
        transaction: outgoing,
        added: [updated],
        addedRecords: [],
        updatedRecords: [updated],
        removedIds: [],
      },
    }
  }

  const id = createId()
  const groupId = createId()
  const payload = { nodeType, attr, oldValue, newValue, ...extraPayload }
  const conflict = findOverlapConflict(
    { suggestionId: id, parentId: null, kind: 'attr_change', blockId, from: 0, to: 0, payload },
    author,
    existingSuggestions,
  )
  const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'attr_change', blockId, 0, 0, author, payload, conflict)
  return {
    status: 'handled',
    result: {
      transaction: outgoing,
      added: [suggestion],
      addedRecords: [record],
      updatedRecords: [],
      removedIds: [],
    },
  }
}

function detectTableOp(doc: ProseMirrorNode, steps: readonly Step[]): string | null {
  for (const s of steps) {
    if (s instanceof ReplaceStep && s.slice.size > 0) {
      const first = s.slice.content.firstChild
      if (first?.type.name === 'table') return 'insert_table'
      if (first?.type.name === 'tableRow') return 'insert_row'
      if (first?.type.name === 'tableCell' || first?.type.name === 'tableHeader') return 'insert_column'
    }
    if (s instanceof ReplaceAroundStep) {
      const sliceChild = s.slice.content.firstChild
      if (sliceChild && (sliceChild.type.name === 'tableHeader' || sliceChild.type.name === 'tableCell')) {
        return steps.length > 1 ? 'toggle_header_row' : 'toggle_header_cell'
      }
    }
  }

  const first = steps[0]
  if (first instanceof ReplaceStep && first.slice.size === 0) {
    const tableHit = findAncestorByType(doc, first.from + 1, ['table'])
    if (tableHit) {
      const sliced = doc.slice(first.from, first.to).content.firstChild
      if (sliced?.type.name === 'tableRow') return 'delete_row'
      if (sliced?.type.name === 'tableCell' || sliced?.type.name === 'tableHeader') return 'delete_column'
    }
  }
  return null
}

function handleTableSteps(
  state: EditorState,
  incoming: Transaction,
  author: string,
  createId: () => string,
  capabilities: SuggestionCapabilities,
  existingSuggestions: readonly TrackedSuggestion[],
  tableOp: string,
): TrackStructureOutcome {
  if (!isCapabilityAllowed('table_change', capabilities) || !capabilities.table_operations.includes(tableOp)) {
    return { status: 'unsupported', reason: `unsupported_table_op:${tableOp}` }
  }

  // Validate any existing ancestor table BEFORE mutation (reject non-textual tables before write!)
  const firstStep = incoming.steps[0]
  const stepFrom = firstStep instanceof ReplaceStep || firstStep instanceof ReplaceAroundStep ? firstStep.from : 0
  const existingTableBefore = findAncestorByType(state.doc, stepFrom + 1, ['table'])
  if (existingTableBefore && tableOp === 'insert_table') {
    return { status: 'unsupported', reason: 'non_textual_table' }
  }
  if (existingTableBefore && !isTableNodeTextual(existingTableBefore.node, capabilities.allowed_marks)) {
    return { status: 'unsupported', reason: 'non_textual_table' }
  }

  const applied = applyStepsWithFreshCellIds(state.doc, incoming.steps, createId)
  if (!applied) {
    return { status: 'unsupported', reason: 'invalid_table_transform' }
  }

  const outgoing = state.tr
  for (const s of applied.tr.steps) {
    outgoing.step(s)
  }

  const survivingAnchorId = existingTableBefore
    ? extractBlockIdsFromNode(existingTableBefore.node).find(id => findBlockPosById(outgoing.doc, id) !== null)
    : undefined
  const survivingAnchor = survivingAnchorId ? findBlockPosById(outgoing.doc, survivingAnchorId) : null
  const mappedPos = outgoing.mapping.map(stepFrom + 1, 1)
  const targetTableHit = survivingAnchor
    ? findAncestorByType(outgoing.doc, survivingAnchor.pos + 1, ['table'])
    : findAncestorByType(outgoing.doc, Math.min(mappedPos, outgoing.doc.content.size), ['table'])
      ?? findFirstTableInRange(outgoing.doc, Math.max(0, stepFrom - 1))

  if (!targetTableHit || !isTableNodeTextual(targetTableHit.node, capabilities.allowed_marks)) {
    return { status: 'unsupported', reason: 'non_textual_table' }
  }

  let anchorBlockId = survivingAnchorId
  let insertion: Record<string, unknown> = {}
  let cellBlockIds = applied.insertedCellBlockIds.filter(id => extractBlockIdsFromNode(targetTableHit.node).includes(id))
  if (tableOp === 'insert_table') {
    const $table = outgoing.doc.resolve(targetTableHit.pos)
    const before = $table.nodeBefore
    const after = $table.parent.maybeChild($table.index() + 1)
    const previousId = before?.attrs?.blockId
    const nextId = after?.attrs?.blockId
    const previous = typeof previousId === 'string' && findBlockPosById(state.doc, previousId)
    const following = typeof nextId === 'string' && findBlockPosById(state.doc, nextId)
    anchorBlockId = previous ? previousId : following ? nextId : undefined

    const rows = Array.from({ length: targetTableHit.node.childCount }, (_, index) => targetTableHit.node.child(index))
    const cells = rows.flatMap(row => Array.from({ length: row.childCount }, (_, index) => row.child(index)))
    cellBlockIds = cells.map(cell => firstAddressableBlockId(cell)).filter((id): id is string => id !== null)
    insertion = {
      rows: rows.length,
      cols: rows[0]?.childCount ?? 0,
      withHeaderRow: rows[0]?.firstChild?.type.name === 'tableHeader',
      insertBefore: !previous,
      cellsContent: cells.map(cell => cell.toJSON().content ?? []),
    }
  }
  if (!anchorBlockId) {
    return { status: 'unsupported', reason: 'missing_table_block_id' }
  }

  let rowIndex: number | undefined
  let colIndex: number | undefined
  if (existingTableBefore && firstStep instanceof ReplaceStep) {
    const slicedFirst = state.doc.slice(firstStep.from, firstStep.to).content.firstChild
    if (slicedFirst) {
      const innerId = firstAddressableBlockId(slicedFirst)
      if (innerId) {
        const coords = locateTableCellCoords(existingTableBefore.node, innerId)
        if (coords) {
          rowIndex = coords.rowIndex
          colIndex = coords.colIndex
        }
      }
    }
  }

  cloneMeta(incoming, outgoing)
  copySelectionSafe(incoming, outgoing)

  const id = createId()
  const groupId = createId()
  const payload: Record<string, unknown> = {
    tableOp,
    cellBlockIds,
    ...insertion,
    tablePosAfter: targetTableHit.pos,
    inverseSteps: serializeInverseSteps(state.doc, incoming.steps),
    ...(rowIndex !== undefined ? { rowIndex } : {}),
    ...(colIndex !== undefined ? { colIndex } : {}),
  }
  const conflict = findOverlapConflict(
    { suggestionId: id, parentId: null, kind: 'table_change', blockId: anchorBlockId, from: 0, to: 0, payload },
    author,
    existingSuggestions,
  )
  const { suggestion, record } = buildRecordAndSuggestion(id, groupId, null, 'table_change', anchorBlockId, 0, 0, author, payload, conflict)
  return { status: 'handled', result: { transaction: outgoing, added: [suggestion], addedRecords: [record], updatedRecords: [], removedIds: [] } }
}

function findFirstTableInRange(doc: ProseMirrorNode, posFrom: number): { pos: number; node: ProseMirrorNode } | null {
  let found: { pos: number; node: ProseMirrorNode } | null = null
  doc.nodesBetween(posFrom, doc.content.size, (node, pos) => {
    if (found) return false
    if (node.type.name === 'table') {
      found = { pos, node }
      return false
    }
    return undefined
  })
  return found
}

function isLiftListTransaction(doc: ProseMirrorNode, steps: readonly Step[]): boolean {
  const probe = new Transform(doc)
  for (const s of steps) {
    if (probe.maybeStep(s).failed) return false
  }
  // Check that at least one paragraph was moved out of a list or reduced list depth cleanly
  let beforeListCount = 0
  let afterListCount = 0
  doc.descendants(n => {
    if (n.type.name === 'listItem') beforeListCount++
  })
  probe.doc.descendants(n => {
    if (n.type.name === 'listItem') afterListCount++
  })
  return afterListCount < beforeListCount
}
