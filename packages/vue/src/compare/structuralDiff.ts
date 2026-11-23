import { Fragment, type Mark, type Node as ProseMirrorNode, type Schema } from '@tiptap/pm/model'
import { StepMap } from '@tiptap/pm/transform'
import { ChangeSet, type Span, type TokenEncoder } from 'prosemirror-changeset'

export type DiffSpanKind = 'insert' | 'delete' | 'replace' | 'mark_change' | 'attr_change'

export interface MarkChangeDetail {
  before: Array<{ type: string; attrs: Record<string, unknown> }>
  after: Array<{ type: string; attrs: Record<string, unknown> }>
}

export interface AttrChangeEntry {
  nodeType: string
  blockId: string | null
  attr: string
  oldValue: unknown
  newValue: unknown
  fromB: number
  toB: number
}

export interface BlockAlignment {
  kind: 'matched' | 'inserted' | 'deleted'
  blockId: string | null
  fromNode: ProseMirrorNode | null
  toNode: ProseMirrorNode | null
  fromPosA: number | null
  toPosA: number | null
  fromPosB: number | null
  toPosB: number | null
  moved: boolean
}

export interface InlineDiffSpan {
  kind: 'insert' | 'delete' | 'mark_change'
  fromA: number
  toA: number
  fromB: number
  toB: number
  text: string
  marksBefore: Array<{ type: string; attrs: Record<string, unknown> }>
  marksAfter: Array<{ type: string; attrs: Record<string, unknown> }>
}

export interface StructuralDiffResult {
  alignments: BlockAlignment[]
  inlineSpans: InlineDiffSpan[]
  attrChanges: AttrChangeEntry[]
  hasChanges: boolean
}

function canonicalSortObject(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(canonicalSortObject)
  }
  if (value !== null && typeof value === 'object') {
    const out: Record<string, unknown> = {}
    for (const key of Object.keys(value as Record<string, unknown>).sort()) {
      out[key] = canonicalSortObject((value as Record<string, unknown>)[key])
    }
    return out
  }
  return value
}

function encodeMarks(marks: readonly Mark[]): string {
  if (marks.length === 0) {
    return ''
  }
  const normalized = marks
    .map((m) => `${m.type.name}:${JSON.stringify(canonicalSortObject(m.attrs))}`)
    .sort()
  return normalized.join('|')
}

function encodeAttrsWithoutBlockId(attrs: Record<string, unknown>): string {
  const copy: Record<string, unknown> = {}
  for (const key of Object.keys(attrs).sort()) {
    if (key !== 'blockId') {
      copy[key] = canonicalSortObject(attrs[key])
    }
  }
  return JSON.stringify(copy)
}

/**
 * Custom `TokenEncoder` encoding active marks (name + sorted attrs) directly into
 * character tokens so `prosemirror-changeset` detects formatting changes (`bold`, `link` `href`, etc.)
 * rather than ignoring marks like `DefaultEncoder` does.
 */
export const MarkAwareEncoder: TokenEncoder<string> = {
  encodeCharacter(char: number, marks: readonly Mark[]): string {
    return `c:${char}:${encodeMarks(marks)}`
  },
  encodeNodeStart(node: ProseMirrorNode): string {
    return `s:${node.type.name}:${encodeAttrsWithoutBlockId(node.attrs)}`
  },
  encodeNodeEnd(node: ProseMirrorNode): string {
    return `e:${node.type.name}`
  },
  compareTokens(a: string, b: string): boolean {
    return a === b
  },
}

/** Deterministic 64-bit string simhash (Fowler-Noll-Vo shingle digest) for fallback block LCS alignment. */
export function textSimhash(text: string): string {
  const normalized = text.trim().toLowerCase().replace(/\s+/g, ' ')
  if (normalized.length === 0) {
    return '00000000'
  }
  let h1 = 0x811c9dc5
  let h2 = 0x811c9dc5
  for (let i = 0; i < normalized.length; i++) {
    const code = normalized.charCodeAt(i)
    h1 ^= code
    h1 = Math.imul(h1, 0x01000193)
    h2 ^= (code + (i & 0xff))
    h2 = Math.imul(h2, 0x01000193)
  }
  return (h1 >>> 0).toString(16).padStart(8, '0') + (h2 >>> 0).toString(16).padStart(8, '0')
}

interface AlignItem {
  index: number
  node: ProseMirrorNode
  pos: number
  blockId: string | null
  signature: string
}

function collectTopUnits(doc: ProseMirrorNode): AlignItem[] {
  const items: AlignItem[] = []
  let pos = 0
  for (let i = 0; i < doc.childCount; i++) {
    const child = doc.child(i)
    const rawBlockId = child.attrs?.blockId
    const blockId = typeof rawBlockId === 'string' && rawBlockId !== '' ? rawBlockId : null
    items.push({
      index: i,
      node: child,
      pos,
      blockId,
      signature: `${child.type.name}:${textSimhash(child.textContent)}`,
    })
    pos += child.nodeSize
  }
  return items
}

function signatureSimilarity(a: AlignItem, b: AlignItem): boolean {
  if (a.node.type.name !== b.node.type.name) {
    return false
  }
  if (a.signature === b.signature) {
    return true
  }
  const ta = a.node.textContent.trim()
  const tb = b.node.textContent.trim()
  if (ta.length === 0 && tb.length === 0) {
    return true
  }
  const minLen = Math.min(ta.length, tb.length)
  if (minLen === 0) {
    return false
  }
  // Allow pairing blocks of the same node type when they share substantial prefix/word overlap.
  const wordsA = new Set(ta.toLowerCase().split(/\s+/).filter(Boolean))
  const wordsB = new Set(tb.toLowerCase().split(/\s+/).filter(Boolean))
  let shared = 0
  for (const w of wordsA) {
    if (wordsB.has(w)) {
      shared++
    }
  }
  return shared > 0 || (a.node.childCount > 0 && b.node.childCount > 0 && (a.node.type.name === 'bulletList' || a.node.type.name === 'orderedList' || a.node.type.name === 'table'))
}

function alignSequence(
  itemsA: AlignItem[],
  itemsB: AlignItem[],
): BlockAlignment[] {
  const matchedA = new Set<number>()
  const matchedB = new Set<number>()
  const pairs: Array<{ a: AlignItem; b: AlignItem; moved: boolean }> = []

  // Stage 1a: Exact attrs.blockId matching (tracks moves across positions too).
  const byIdB = new Map<string, AlignItem>()
  for (const b of itemsB) {
    if (b.blockId !== null && !byIdB.has(b.blockId)) {
      byIdB.set(b.blockId, b)
    }
  }

  for (const a of itemsA) {
    if (a.blockId === null) {
      continue
    }
    const b = byIdB.get(a.blockId)
    if (b && !matchedB.has(b.index)) {
      matchedA.add(a.index)
      matchedB.add(b.index)
      pairs.push({ a, b, moved: a.index !== b.index })
    }
  }

  // Stage 1b: LCS fallback for unmatched nodes (without blockId or replaced blockIds).
  const remA = itemsA.filter((item) => !matchedA.has(item.index))
  const remB = itemsB.filter((item) => !matchedB.has(item.index))

  if (remA.length > 0 && remB.length > 0) {
    const n = remA.length
    const m = remB.length
    const dp: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(m + 1).fill(0))
    for (let i = n - 1; i >= 0; i--) {
      for (let j = m - 1; j >= 0; j--) {
        if (signatureSimilarity(remA[i], remB[j])) {
          dp[i][j] = 1 + dp[i + 1][j + 1]
        } else {
          dp[i][j] = Math.max(dp[i + 1][j], dp[i][j + 1])
        }
      }
    }

    let i = 0
    let j = 0
    while (i < n && j < m) {
      if (signatureSimilarity(remA[i], remB[j])) {
        matchedA.add(remA[i].index)
        matchedB.add(remB[j].index)
        pairs.push({ a: remA[i], b: remB[j], moved: false })
        i++
        j++
      } else if (dp[i + 1][j] >= dp[i][j + 1]) {
        i++
      } else {
        j++
      }
    }
  }

  pairs.sort((p1, p2) => p1.b.index - p2.b.index)

  const alignments: BlockAlignment[] = []
  let cursorA = 0
  let cursorB = 0

  for (const pair of pairs) {
    while (cursorA < pair.a.index) {
      const a = itemsA[cursorA]
      if (!matchedA.has(a.index)) {
        alignments.push({
          kind: 'deleted',
          blockId: a.blockId,
          fromNode: a.node,
          toNode: null,
          fromPosA: a.pos,
          toPosA: a.pos + a.node.nodeSize,
          fromPosB: null,
          toPosB: null,
          moved: false,
        })
      }
      cursorA++
    }
    while (cursorB < pair.b.index) {
      const b = itemsB[cursorB]
      if (!matchedB.has(b.index)) {
        alignments.push({
          kind: 'inserted',
          blockId: b.blockId,
          fromNode: null,
          toNode: b.node,
          fromPosA: null,
          toPosA: null,
          fromPosB: b.pos,
          toPosB: b.pos + b.node.nodeSize,
          moved: false,
        })
      }
      cursorB++
    }

    alignments.push({
      kind: 'matched',
      blockId: pair.b.blockId ?? pair.a.blockId,
      fromNode: pair.a.node,
      toNode: pair.b.node,
      fromPosA: pair.a.pos,
      toPosA: pair.a.pos + pair.a.node.nodeSize,
      fromPosB: pair.b.pos,
      toPosB: pair.b.pos + pair.b.node.nodeSize,
      moved: pair.moved,
    })

    if (pair.a.index === cursorA) {
      cursorA++
    }
    if (pair.b.index === cursorB) {
      cursorB++
    }
  }

  while (cursorA < itemsA.length) {
    const a = itemsA[cursorA]
    if (!matchedA.has(a.index)) {
      alignments.push({
        kind: 'deleted',
        blockId: a.blockId,
        fromNode: a.node,
        toNode: null,
        fromPosA: a.pos,
        toPosA: a.pos + a.node.nodeSize,
        fromPosB: null,
        toPosB: null,
        moved: false,
      })
    }
    cursorA++
  }

  while (cursorB < itemsB.length) {
    const b = itemsB[cursorB]
    if (!matchedB.has(b.index)) {
      alignments.push({
        kind: 'inserted',
        blockId: b.blockId,
        fromNode: null,
        toNode: b.node,
        fromPosA: null,
        toPosA: null,
        fromPosB: b.pos,
        toPosB: b.pos + b.node.nodeSize,
        moved: false,
      })
    }
    cursorB++
  }

  return alignments
}

function diffOwnNodeAttributes(
  nodeA: ProseMirrorNode,
  nodeB: ProseMirrorNode,
  posB: number,
  out: AttrChangeEntry[],
): void {
  if (nodeA.type.name !== nodeB.type.name) {
    return
  }
  const blockId = typeof nodeB.attrs?.blockId === 'string' ? nodeB.attrs.blockId : null
  const keys = new Set([...Object.keys(nodeA.attrs), ...Object.keys(nodeB.attrs)])
  for (const attr of keys) {
    if (attr === 'blockId') {
      continue
    }
    const oldVal = nodeA.attrs[attr]
    const newVal = nodeB.attrs[attr]
    if (JSON.stringify(canonicalSortObject(oldVal)) !== JSON.stringify(canonicalSortObject(newVal))) {
      out.push({
        nodeType: nodeB.type.name,
        blockId,
        attr,
        oldValue: oldVal,
        newValue: newVal,
        fromB: posB,
        toB: posB + nodeB.nodeSize,
      })
    }
  }
}

function diffNodeAttributesRecursive(
  nodeA: ProseMirrorNode,
  nodeB: ProseMirrorNode,
  posB: number,
  out: AttrChangeEntry[],
): void {
  diffOwnNodeAttributes(nodeA, nodeB, posB, out)

  // Descend pairwise for structural subtrees (tableRow -> tableCell -> paragraph, etc.).
  if (!nodeA.isText && !nodeB.isText) {
    const count = Math.min(nodeA.childCount, nodeB.childCount)
    let childOffsetB = posB + 1
    for (let i = 0; i < count; i++) {
      const childA = nodeA.child(i)
      const childB = nodeB.child(i)
      diffNodeAttributesRecursive(childA, childB, childOffsetB, out)
      childOffsetB += childB.nodeSize
    }
  }
}

function collectMarksInRange(doc: ProseMirrorNode, from: number, to: number): Array<{ type: string; attrs: Record<string, unknown> }> {
  const seen = new Map<string, { type: string; attrs: Record<string, unknown> }>()
  doc.nodesBetween(Math.max(0, from), Math.min(doc.content.size, to), (node) => {
    for (const mark of node.marks) {
      const key = `${mark.type.name}:${JSON.stringify(canonicalSortObject(mark.attrs))}`
      if (!seen.has(key)) {
        seen.set(key, { type: mark.type.name, attrs: { ...mark.attrs } })
      }
    }
    return true
  })
  return [...seen.values()]
}

function splitMarkRuns(
  docA: ProseMirrorNode,
  fromA: number,
  toA: number,
  docB: ProseMirrorNode,
  fromB: number,
  toB: number,
  offsetA: number,
  offsetB: number,
  out: InlineDiffSpan[],
): void {
  // Compute boundary offsets relative to range start where inline mark sets change on either side.
  const cuts = new Set<number>([0, toA - fromA])
  const collectCuts = (doc: ProseMirrorNode, from: number, to: number): void => {
    let cursor = 0
    doc.nodesBetween(from, to, (node, pos) => {
      if (!node.isText) {
        return true
      }
      const start = Math.max(pos, from) - from
      const end = Math.min(pos + node.nodeSize, to) - from
      if (end > start) {
        cuts.add(start)
        cuts.add(end)
        cursor = end
      }
      return false
    })
    if (cursor > 0) {
      cuts.add(cursor)
    }
  }
  collectCuts(docA, fromA, toA)
  collectCuts(docB, fromB, toB)

  const sorted = [...cuts].sort((a, b) => a - b)
  for (let i = 0; i < sorted.length - 1; i++) {
    const segStart = sorted[i]
    const segEnd = sorted[i + 1]
    if (segEnd <= segStart) {
      continue
    }
    const segFromA = fromA + segStart
    const segToA = fromA + segEnd
    const segFromB = fromB + segStart
    const segToB = fromB + segEnd
    const marksBefore = collectMarksInRange(docA, segFromA, segToA)
    const marksAfter = collectMarksInRange(docB, segFromB, segToB)
    const sigBefore = marksBefore.map((m) => `${m.type}:${JSON.stringify(canonicalSortObject(m.attrs))}`).sort().join('|')
    const sigAfter = marksAfter.map((m) => `${m.type}:${JSON.stringify(canonicalSortObject(m.attrs))}`).sort().join('|')
    if (sigBefore !== sigAfter) {
      out.push({
        kind: 'mark_change',
        fromA: offsetA + segFromA,
        toA: offsetA + segToA,
        fromB: offsetB + segFromB,
        toB: offsetB + segToB,
        text: docB.textBetween(segFromB, segToB, '\n', '\n'),
        marksBefore,
        marksAfter,
      })
    }
  }
}

function diffAlignedBlockWithChangeSet(
  schema: Schema,
  blockA: ProseMirrorNode,
  blockB: ProseMirrorNode,
  offsetA: number,
  offsetB: number,
  spansOut: InlineDiffSpan[],
  attrsOut: AttrChangeEntry[],
  parent?: ProseMirrorNode,
): void {
  // If container holds nested alignable children (listItem/tableRow), diff own attrs once then align children recursively.
  if (
    blockA.type.name === blockB.type.name
    && (blockA.type.name === 'bulletList' || blockA.type.name === 'orderedList' || blockA.type.name === 'table')
  ) {
    diffOwnNodeAttributes(blockA, blockB, offsetB, attrsOut)
    const childrenA: AlignItem[] = []
    let pA = 0
    for (let i = 0; i < blockA.childCount; i++) {
      const c = blockA.child(i)
      childrenA.push({
        index: i,
        node: c,
        pos: pA,
        blockId: typeof c.attrs?.blockId === 'string' ? c.attrs.blockId : null,
        signature: `${c.type.name}:${textSimhash(c.textContent)}`,
      })
      pA += c.nodeSize
    }
    const childrenB: AlignItem[] = []
    let pB = 0
    for (let i = 0; i < blockB.childCount; i++) {
      const c = blockB.child(i)
      childrenB.push({
        index: i,
        node: c,
        pos: pB,
        blockId: typeof c.attrs?.blockId === 'string' ? c.attrs.blockId : null,
        signature: `${c.type.name}:${textSimhash(c.textContent)}`,
      })
      pB += c.nodeSize
    }

    const childAlignments = alignSequence(childrenA, childrenB)
    for (const sub of childAlignments) {
      if (sub.kind === 'matched' && sub.fromNode && sub.toNode && sub.fromPosA !== null && sub.fromPosB !== null) {
        diffAlignedBlockWithChangeSet(
          schema,
          sub.fromNode,
          sub.toNode,
          offsetA + 1 + sub.fromPosA,
          offsetB + 1 + sub.fromPosB,
          spansOut,
          attrsOut,
          blockA,
        )
      } else if (sub.kind === 'inserted' && sub.toNode && sub.fromPosB !== null) {
        spansOut.push({
          kind: 'insert',
          fromA: offsetA,
          toA: offsetA,
          fromB: offsetB + 1 + sub.fromPosB,
          toB: offsetB + 1 + sub.fromPosB + sub.toNode.nodeSize,
          text: sub.toNode.textContent,
          marksBefore: [],
          marksAfter: [],
        })
      } else if (sub.kind === 'deleted' && sub.fromNode && sub.fromPosA !== null) {
        spansOut.push({
          kind: 'delete',
          fromA: offsetA + 1 + sub.fromPosA,
          toA: offsetA + 1 + sub.fromPosA + sub.fromNode.nodeSize,
          fromB: offsetB + 1,
          toB: offsetB + 1,
          text: sub.fromNode.textContent,
          marksBefore: [],
          marksAfter: [],
        })
      }
    }
    return
  }

  diffNodeAttributesRecursive(blockA, blockB, offsetB, attrsOut)

  // A list item or row requires its parent in a valid document. Account for the
  // synthetic parent’s opening token when translating the diff back to the original document.
  const docA = schema.topNodeType.createAndFill(null, parent ? parent.copy(Fragment.from(blockA)) : blockA)
  const docB = schema.topNodeType.createAndFill(null, parent ? parent.copy(Fragment.from(blockB)) : blockB)
  if (!docA || !docB) {
    return
  }
  const docOffsetA = parent ? offsetA - 1 : offsetA
  const docOffsetB = parent ? offsetB - 1 : offsetB

  const cs = ChangeSet.create<number>(docA, (a, b) => (a === b ? a : a), MarkAwareEncoder)
  const map = new StepMap([0, docA.content.size, docB.content.size])
  const nextCs = cs.addSteps(docB, [map], 1)

  for (const ch of nextCs.changes) {
    const absFromA = docOffsetA + ch.fromA
    const absToA = docOffsetA + ch.toA
    const absFromB = docOffsetB + ch.fromB
    const absToB = docOffsetB + ch.toB

    const textA = docA.textBetween(ch.fromA, ch.toA, '\n', '\n')
    const textB = docB.textBetween(ch.fromB, ch.toB, '\n', '\n')
    const marksA = collectMarksInRange(docA, ch.fromA, ch.toA)
    const marksB = collectMarksInRange(docB, ch.fromB, ch.toB)

    // Identical text with changed marks/attrs detected by MarkAwareEncoder!
    if (textA.length > 0 && textA === textB) {
      const beforeCount = spansOut.length
      splitMarkRuns(docA, ch.fromA, ch.toA, docB, ch.fromB, ch.toB, docOffsetA, docOffsetB, spansOut)
      if (spansOut.length === beforeCount) {
        spansOut.push({
          kind: 'mark_change',
          fromA: absFromA,
          toA: absToA,
          fromB: absFromB,
          toB: absToB,
          text: textB,
          marksBefore: marksA,
          marksAfter: marksB,
        })
      }
      continue
    }

    const delLen = ch.deleted.reduce((sum: number, s: Span<number>) => sum + s.length, 0)
    const insLen = ch.inserted.reduce((sum: number, s: Span<number>) => sum + s.length, 0)

    if (delLen > 0) {
      spansOut.push({
        kind: 'delete',
        fromA: absFromA,
        toA: absToA,
        fromB: absFromB,
        toB: absFromB,
        text: textA,
        marksBefore: marksA,
        marksAfter: [],
      })
    }

    if (insLen > 0) {
      spansOut.push({
        kind: 'insert',
        fromA: absFromA,
        toA: absToA,
        fromB: absFromB,
        toB: absToB,
        text: textB,
        marksBefore: [],
        marksAfter: marksB,
      })
    }
  }
}

/**
 * Computes the two-stage structural diff between two fixed ProseMirror documents:
 * - Stage 1: Align top-level blocks, list items, and table rows via `attrs.blockId`, with LCS `(type + simhash)` fallback.
 * - Stage 2: Run `prosemirror-changeset` with `MarkAwareEncoder` inside aligned pairs + emit explicit `attr_change` records.
 */
export function computeStructuralDiff(
  fromDoc: ProseMirrorNode,
  toDoc: ProseMirrorNode,
): StructuralDiffResult {
  const schema = toDoc.type.schema
  const itemsA = collectTopUnits(fromDoc)
  const itemsB = collectTopUnits(toDoc)
  const alignments = alignSequence(itemsA, itemsB)

  const inlineSpans: InlineDiffSpan[] = []
  const attrChanges: AttrChangeEntry[] = []

  for (const item of alignments) {
    if (item.kind === 'matched' && item.fromNode && item.toNode && item.fromPosA !== null && item.fromPosB !== null) {
      diffAlignedBlockWithChangeSet(
        schema,
        item.fromNode,
        item.toNode,
        item.fromPosA,
        item.fromPosB,
        inlineSpans,
        attrChanges,
      )
    } else if (item.kind === 'inserted' && item.toNode && item.fromPosB !== null && item.toPosB !== null) {
      inlineSpans.push({
        kind: 'insert',
        fromA: item.fromPosA ?? 0,
        toA: item.fromPosA ?? 0,
        fromB: item.fromPosB,
        toB: item.toPosB,
        text: item.toNode.textContent,
        marksBefore: [],
        marksAfter: collectMarksInRange(item.toNode, 0, item.toNode.content.size),
      })
    } else if (item.kind === 'deleted' && item.fromNode && item.fromPosA !== null && item.toPosA !== null) {
      inlineSpans.push({
        kind: 'delete',
        fromA: item.fromPosA,
        toA: item.toPosA,
        fromB: 0,
        toB: 0,
        text: item.fromNode.textContent,
        marksBefore: collectMarksInRange(item.fromNode, 0, item.fromNode.content.size),
        marksAfter: [],
      })
    }
  }

  return {
    alignments,
    inlineSpans,
    attrChanges,
    hasChanges: inlineSpans.length > 0 || attrChanges.length > 0 || alignments.some((a) => a.moved),
  }
}
