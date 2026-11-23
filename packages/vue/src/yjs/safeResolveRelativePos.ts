import * as Y from 'yjs'
import { relativePositionToAbsolutePosition } from '@tiptap/y-tiptap'
import type { YjsRelativePayload } from '../types/review'

export type YjsRelativeResolver = (
  ydoc: Y.Doc,
  fragment: Y.XmlFragment,
  relPos: Y.RelativePosition,
  mapping: Map<unknown, unknown>,
) => number | null

export interface SafeResolveOptions {
  /** Optional resolver override (defaults to `@tiptap/y-tiptap`'s `relativePositionToAbsolutePosition`). */
  resolveFn?: YjsRelativeResolver
}

export interface YjsRangeResolution {
  state: 'attached' | 'detached'
  from: number | null
  to: number | null
  quoteText: string | null
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyYType = Y.AbstractType<any>

function getParentContainer(type: AnyYType): AnyYType | null {
  const item = type._item
  if (!item || !item.parent) {
    return null
  }
  return item.parent as AnyYType
}

function hasDeletedAncestor(type: AnyYType, root: Y.XmlFragment): boolean {
  let cursor: AnyYType | null = type
  while (cursor !== null) {
    if (cursor._item?.deleted) {
      return true
    }
    if (cursor === root) {
      return false
    }
    cursor = getParentContainer(cursor)
  }
  return true
}

function hasPrecedingLiveSibling(node: AnyYType): boolean {
  let left = node._item?.left ?? null
  while (left !== null) {
    if (!left.deleted) {
      return true
    }
    left = left.left
  }
  return false
}

function isFirstLiveStartInFragment(textType: Y.XmlText, fragment: Y.XmlFragment): boolean {
  let cursor: AnyYType | null = textType
  while (cursor !== null && cursor !== fragment) {
    if (cursor._item?.deleted || hasPrecedingLiveSibling(cursor)) {
      return false
    }
    cursor = getParentContainer(cursor)
  }
  return cursor === fragment
}

/**
 * Resolves a Yjs relative position against `fragment` with protection against
 * `@tiptap/y-tiptap #8614`, where `relativePositionToAbsolutePosition` returns `null`
 * for a valid start-of-document relative position (`pos === 1`, e.g. after deleting
 * leading characters like `"Xhello"` -> `"hello"`).
 *
 * Deleted text nodes, deleted ancestors, and foreign fragments strictly return `null`.
 */
export function safeResolveRelativePos(
  ydoc: Y.Doc,
  fragment: Y.XmlFragment,
  relPos: Y.RelativePosition,
  mapping: Map<unknown, unknown>,
  options?: SafeResolveOptions,
): number | null {
  const decoded = Y.createAbsolutePositionFromRelativePosition(relPos, ydoc)
  if (
    decoded === null
    || (decoded.type !== fragment && !Y.isParentOf(fragment, decoded.type._item))
    || hasDeletedAncestor(decoded.type, fragment)
  ) {
    return null
  }

  const resolve = options?.resolveFn
    ?? (relativePositionToAbsolutePosition as unknown as YjsRelativeResolver)
  const resolved = resolve(ydoc, fragment, relPos, mapping)
  if (resolved !== null) {
    return resolved
  }

  // Workaround for @tiptap/y-tiptap #8614:
  // When index === 0 in the first live Y.XmlText inside the first live block of the fragment,
  // upstream relativePositionToAbsolutePosition computes absPos = 1 (or parent walk fails -> null).
  if (
    decoded.type instanceof Y.XmlText
    && !decoded.type._item?.deleted
    && decoded.index === 0
    && isFirstLiveStartInFragment(decoded.type, fragment)
  ) {
    return 1
  }

  return null
}

export function encodeRelativePositionBase64(relPos: Y.RelativePosition): string {
  const bytes = Y.encodeRelativePosition(relPos)
  let binary = ''
  for (let i = 0; i < bytes.length; i++) {
    binary += String.fromCharCode(bytes[i])
  }
  if (typeof globalThis.btoa === 'function') {
    return globalThis.btoa(binary)
  }
  return Buffer.from(bytes).toString('base64')
}

export function decodeRelativePositionBase64(encoded: string): Y.RelativePosition {
  let bytes: Uint8Array
  if (typeof globalThis.atob === 'function') {
    const binary = globalThis.atob(encoded)
    bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i)
    }
  } else {
    bytes = new Uint8Array(Buffer.from(encoded, 'base64'))
  }
  return Y.decodeRelativePosition(bytes)
}

/**
 * Find the inner `(Y.XmlText, textOffset)` corresponding to a ProseMirror character position
 * inside a mapped Yjs fragment so we can bind explicit `assoc` polarity (`0` start, `-1` end).
 */
export function resolveXmlTextPosition(
  ydoc: Y.Doc,
  fragment: Y.XmlFragment,
  pmPos: number,
  mapping: Map<unknown, unknown>,
  assoc: 0 | -1,
): Y.RelativePosition | null {
  const candidates: Array<{ type: Y.XmlText; absStart: number; length: number }> = []

  function walk(container: Y.XmlFragment | Y.XmlElement): void {
    for (const child of container.toArray()) {
      if (child instanceof Y.XmlText) {
        if (child._item?.deleted) {
          continue
        }
        const relZero = Y.createRelativePositionFromTypeIndex(child, 0, 0)
        const abs = safeResolveRelativePos(ydoc, fragment, relZero, mapping)
        if (abs !== null) {
          candidates.push({ type: child, absStart: abs, length: child.length })
        }
      } else if (child instanceof Y.XmlElement) {
        walk(child)
      }
    }
  }

  walk(fragment)

  for (const item of candidates) {
    const end = item.absStart + item.length
    if (pmPos >= item.absStart && pmPos <= end) {
      const localOffset = Math.max(0, Math.min(item.length, pmPos - item.absStart))
      return Y.createRelativePositionFromTypeIndex(item.type, localOffset, assoc)
    }
  }

  return null
}

/**
 * Builds a canonical `yjs_relative` selector payload from a live ProseMirror `[from, to]` selection:
 * - left bound uses `assoc = 0` (glued to first selected character; prepending left does not expand)
 * - right bound uses `assoc = -1` (glued to last selected character; appending right does not expand)
 */
export function buildYjsRelativePayload(
  ydoc: Y.Doc,
  fragment: Y.XmlFragment,
  from: number,
  to: number,
  blockId: string,
  mapping: Map<unknown, unknown>,
): YjsRelativePayload | null {
  if (from < 0 || to <= from || blockId.trim() === '') {
    return null
  }

  const startRel = resolveXmlTextPosition(ydoc, fragment, from, mapping, 0)
  const endRel = resolveXmlTextPosition(ydoc, fragment, to, mapping, -1)
  if (!startRel || !endRel) {
    return null
  }

  return {
    schema: 1,
    block_id: blockId,
    start: {
      rel: encodeRelativePositionBase64(startRel),
      assoc: 0,
    },
    end: {
      rel: encodeRelativePositionBase64(endRel),
      assoc: -1,
    },
  }
}

/**
 * Canonical range resolution in a Yjs-backed ProseMirror document.
 * Detects edge-non-expansion, partial narrowing, and full deletion (`left >= right` or `null` -> `detached`).
 */
export function resolveYjsRangeAnchor(
  ydoc: Y.Doc,
  fragment: Y.XmlFragment,
  payload: YjsRelativePayload,
  mapping: Map<unknown, unknown>,
  extractText?: (from: number, to: number) => string,
  options?: SafeResolveOptions,
): YjsRangeResolution {
  let startRel: Y.RelativePosition
  let endRel: Y.RelativePosition
  try {
    startRel = decodeRelativePositionBase64(payload.start.rel)
    endRel = decodeRelativePositionBase64(payload.end.rel)
  } catch {
    return { state: 'detached', from: null, to: null, quoteText: null }
  }

  // Enforce expected assoc polarity even if serialized externally.
  startRel = new Y.RelativePosition(startRel.type, startRel.tname, startRel.item, 0)
  endRel = new Y.RelativePosition(endRel.type, endRel.tname, endRel.item, -1)

  const left = safeResolveRelativePos(ydoc, fragment, startRel, mapping, options)
  const right = safeResolveRelativePos(ydoc, fragment, endRel, mapping, options)

  if (left === null || right === null || left >= right) {
    return { state: 'detached', from: null, to: null, quoteText: null }
  }

  const quoteText = extractText ? extractText(left, right) : null
  if (quoteText !== null && quoteText.length === 0) {
    return { state: 'detached', from: null, to: null, quoteText: null }
  }

  return {
    state: 'attached',
    from: left,
    to: right,
    quoteText,
  }
}
