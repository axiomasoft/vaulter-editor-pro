import type { Node as ProseMirrorNode } from '@tiptap/pm/model'
import type {
  AnchorActuality,
  AnchoredComment,
  SnapshotUtf16Payload,
  YjsRelativePayload,
} from '../types/review'
import { resolveYjsRangeAnchor, type SafeResolveOptions } from '../yjs/safeResolveRelativePos'
import type * as Y from 'yjs'

const CONTEXT_CHARS = 24

export interface BlockRangeHit {
  blockId: string
  blockFrom: number
  fromUtf16: number
  toUtf16: number
  prefix: string
  suffix: string
  quoteText: string
}

/** Locate the inner block carrying `attrs.blockId` covering ProseMirror `[from, to]`. */
export function extractSnapshotSelectionRange(
  doc: ProseMirrorNode,
  from: number,
  to: number,
): BlockRangeHit | null {
  if (from < 0 || to <= from || to > doc.content.size) {
    return null
  }

  let matched: BlockRangeHit | null = null
  doc.descendants((node, pos) => {
    if (matched !== null) {
      return false
    }
    const blockId = node.attrs?.blockId
    if (typeof blockId !== 'string' || blockId === '') {
      return true
    }
    const contentStart = pos + 1
    const contentEnd = pos + node.nodeSize - 1
    if (from >= contentStart && to <= contentEnd) {
      const fullText = node.textBetween(0, node.content.size, '\n', '\n')
      const fromUtf16 = node.textBetween(0, from - contentStart, '\n', '\n').length
      const toUtf16 = node.textBetween(0, to - contentStart, '\n', '\n').length
      if (toUtf16 <= fromUtf16) {
        return false
      }
      matched = {
        blockId,
        blockFrom: contentStart,
        fromUtf16,
        toUtf16,
        prefix: fullText.slice(Math.max(0, fromUtf16 - CONTEXT_CHARS), fromUtf16),
        suffix: fullText.slice(toUtf16, toUtf16 + CONTEXT_CHARS),
        quoteText: fullText.slice(fromUtf16, toUtf16),
      }
      return false
    }
    return true
  })

  return matched
}

export function buildSnapshotUtf16Payload(hit: BlockRangeHit): SnapshotUtf16Payload {
  return {
    schema: 1,
    block_id: hit.blockId,
    from_utf16: hit.fromUtf16,
    to_utf16: hit.toUtf16,
    prefix: hit.prefix,
    suffix: hit.suffix,
  }
}

function findBlockById(
  doc: ProseMirrorNode,
  blockId: string,
): { node: ProseMirrorNode; contentPos: number; text: string } | null {
  let found: { node: ProseMirrorNode; contentPos: number; text: string } | null = null
  doc.descendants((node, pos) => {
    if (found !== null) {
      return false
    }
    if (node.attrs?.blockId === blockId) {
      found = {
        node,
        contentPos: pos + 1,
        text: node.textBetween(0, node.content.size, '\n', '\n'),
      }
      return false
    }
    return true
  })
  return found
}

function utf16OffsetToPmPos(node: ProseMirrorNode, contentPos: number, utf16Offset: number): number {
  let remaining = utf16Offset
  let result = contentPos
  node.descendants((child, relPos) => {
    if (remaining <= 0) {
      return false
    }
    if (child.isText && child.text) {
      if (remaining <= child.text.length) {
        result = contentPos + relPos + remaining
        remaining = 0
        return false
      }
      remaining -= child.text.length
      result = contentPos + relPos + child.text.length
    } else if (child.type.name === 'hardBreak') {
      if (remaining <= 1) {
        result = contentPos + relPos + 1
        remaining = 0
        return false
      }
      remaining -= 1
      result = contentPos + relPos + 1
    }
    return true
  })
  return result
}

export interface SnapshotResolution {
  state: 'attached' | 'detached'
  from: number | null
  to: number | null
  shifted: boolean
  quoteText: string
}

export function resolveSnapshotUtf16Anchor(
  doc: ProseMirrorNode,
  payload: SnapshotUtf16Payload,
  quoteText: string,
): SnapshotResolution {
  const block = findBlockById(doc, payload.block_id)
  if (!block) {
    return { state: 'detached', from: null, to: null, shifted: false, quoteText }
  }

  const direct = block.text.slice(payload.from_utf16, payload.to_utf16)
  if (direct === quoteText && quoteText.length > 0) {
    return {
      state: 'attached',
      from: utf16OffsetToPmPos(block.node, block.contentPos, payload.from_utf16),
      to: utf16OffsetToPmPos(block.node, block.contentPos, payload.to_utf16),
      shifted: false,
      quoteText,
    }
  }

  if (quoteText.length > 0) {
    const matches: number[] = []
    let idx = block.text.indexOf(quoteText)
    while (idx !== -1) {
      matches.push(idx)
      idx = block.text.indexOf(quoteText, idx + 1)
    }

    if (matches.length === 1) {
      const m = matches[0]
      return {
        state: 'attached',
        from: utf16OffsetToPmPos(block.node, block.contentPos, m),
        to: utf16OffsetToPmPos(block.node, block.contentPos, m + quoteText.length),
        shifted: m !== payload.from_utf16,
        quoteText,
      }
    }

    if (matches.length > 1) {
      const prefix = payload.prefix ?? ''
      const suffix = payload.suffix ?? ''
      if (prefix.length > 0 || suffix.length > 0) {
        const filtered = matches.filter((m) => {
          const prefixOk = prefix.length === 0
            || (m >= prefix.length && block.text.slice(m - prefix.length, m) === prefix)
          const suffixOk = suffix.length === 0
            || block.text.slice(m + quoteText.length, m + quoteText.length + suffix.length) === suffix
          return prefixOk && suffixOk
        })
        if (filtered.length === 1) {
          const m = filtered[0]
          return {
            state: 'attached',
            from: utf16OffsetToPmPos(block.node, block.contentPos, m),
            to: utf16OffsetToPmPos(block.node, block.contentPos, m + quoteText.length),
            shifted: m !== payload.from_utf16,
            quoteText,
          }
        }
      }
    }
  }

  return { state: 'detached', from: null, to: null, shifted: false, quoteText }
}

export interface ActualityContext {
  currentGeneration: number
  doc?: ProseMirrorNode | null
  ydoc?: Y.Doc | null
  fragment?: Y.XmlFragment | null
  mapping?: Map<unknown, unknown> | null
  resolveOptions?: SafeResolveOptions
}

/**
 * Evaluates live actuality of a comment's anchor against the active document state.
 */
export function evaluateAnchorActuality(
  comment: AnchoredComment,
  ctx: ActualityContext,
): AnchorActuality {
  const anchor = comment.anchor
  if (!anchor) {
    return { status: 'plain', from: null, to: null, resolvedQuote: null, needsReanchor: false }
  }

  if (anchor.anchor_state === 'historical') {
    return { status: 'historical', from: null, to: null, resolvedQuote: anchor.quote_text, needsReanchor: true }
  }

  if (anchor.anchor_state === 'detached') {
    return { status: 'detached', from: null, to: null, resolvedQuote: anchor.quote_text, needsReanchor: true }
  }

  if (anchor.generation !== ctx.currentGeneration) {
    return { status: 'stale_generation', from: null, to: null, resolvedQuote: anchor.quote_text, needsReanchor: true }
  }

  if (anchor.selector_kind === 'yjs_relative' && ctx.ydoc && ctx.fragment && ctx.mapping) {
    const payload = anchor.selector_payload as YjsRelativePayload
    const res = resolveYjsRangeAnchor(
      ctx.ydoc,
      ctx.fragment,
      payload,
      ctx.mapping,
      ctx.doc ? (f, t) => ctx.doc!.textBetween(f, Math.min(t, ctx.doc!.content.size), '\n', '\n') : undefined,
      ctx.resolveOptions,
    )
    if (res.state === 'detached' || res.from === null || res.to === null) {
      return { status: 'detached', from: null, to: null, resolvedQuote: anchor.quote_text, needsReanchor: true }
    }
    const shifted = res.quoteText !== null && res.quoteText !== anchor.quote_text
    return {
      status: shifted ? 'shifted' : 'current',
      from: res.from,
      to: res.to,
      resolvedQuote: res.quoteText ?? anchor.quote_text,
      needsReanchor: false,
    }
  }

  if (anchor.selector_kind === 'snapshot_utf16' && ctx.doc) {
    const res = resolveSnapshotUtf16Anchor(docOrEmpty(ctx.doc), anchor.selector_payload as SnapshotUtf16Payload, anchor.quote_text)
    if (res.state === 'detached' || res.from === null || res.to === null) {
      return { status: 'detached', from: null, to: null, resolvedQuote: anchor.quote_text, needsReanchor: true }
    }
    return {
      status: res.shifted ? 'shifted' : 'current',
      from: res.from,
      to: res.to,
      resolvedQuote: res.quoteText,
      needsReanchor: false,
    }
  }

  return { status: 'current', from: null, to: null, resolvedQuote: anchor.quote_text, needsReanchor: false }
}

function docOrEmpty(doc: ProseMirrorNode): ProseMirrorNode {
  return doc
}
