export const MIN_OOXML_HEX_ID = 1
export const MAX_OOXML_HEX_ID = 0x7fffffff

const HEX_8_RE = /^[0-9A-F]{8}$/i

/**
 * Validates that a WordprocessingML w14:paraId / w16cid:durableId is an 8-char hex
 * strictly inside `00000001..7FFFFFFF` (most significant bit MUST be 0).
 */
export function isValidOoxmlHexId(value: string | undefined | null): value is string {
  if (typeof value !== 'string' || !HEX_8_RE.test(value)) {
    return false
  }
  const numeric = Number.parseInt(value, 16)
  return Number.isInteger(numeric) && numeric >= MIN_OOXML_HEX_ID && numeric <= MAX_OOXML_HEX_ID
}

export function formatOoxmlHexId(value: number): string {
  const clamped = ((Math.trunc(value) - MIN_OOXML_HEX_ID) % MAX_OOXML_HEX_ID + MAX_OOXML_HEX_ID) % MAX_OOXML_HEX_ID + MIN_OOXML_HEX_ID
  return clamped.toString(16).toUpperCase().padStart(8, '0')
}

function fnv1a31(seed: string): number {
  let hash = 0x811c9dc5
  for (let i = 0; i < seed.length; i += 1) {
    hash ^= seed.charCodeAt(i)
    hash = Math.imul(hash, 0x01000193)
  }
  // Mask MSB so value is strictly in 0..0x7FFFFFFE, then +1 => 0x00000001..0x7FFFFFFF.
  return ((hash >>> 0) & 0x7ffffffe) + 1
}

/**
 * Document-scoped allocator for Word numeric comment/revision IDs and
 * 31-bit hex identifiers (`w14:paraId`, `w16cid:durableId` in `00000001..7FFFFFFF`).
 */
export class OoxmlIdAllocator {
  private readonly usedCommentIds = new Set<number>()
  private readonly usedParaIds = new Set<string>()
  private readonly usedDurableIds = new Set<string>()
  private readonly usedRevisionIds = new Set<number>()
  private nextCommentId = 1
  private nextRevisionId = 1

  allocateCommentId(preferred?: number | null): number {
    if (
      typeof preferred === 'number' &&
      Number.isInteger(preferred) &&
      preferred >= 0 &&
      preferred <= MAX_OOXML_HEX_ID &&
      !this.usedCommentIds.has(preferred)
    ) {
      this.usedCommentIds.add(preferred)
      if (preferred >= this.nextCommentId) {
        this.nextCommentId = preferred + 1
      }
      return preferred
    }
    while (this.usedCommentIds.has(this.nextCommentId)) {
      this.nextCommentId += 1
    }
    const id = this.nextCommentId
    this.nextCommentId += 1
    this.usedCommentIds.add(id)
    return id
  }

  allocateParaId(seed: string, preferred?: string | null): string {
    return this.allocateHex(this.usedParaIds, `para:${seed}`, preferred)
  }

  allocateDurableId(seed: string, preferred?: string | null): string {
    return this.allocateHex(this.usedDurableIds, `durable:${seed}`, preferred)
  }

  allocateRevisionId(preferred?: number | null): number {
    if (
      typeof preferred === 'number' &&
      Number.isInteger(preferred) &&
      preferred >= 1 &&
      preferred <= MAX_OOXML_HEX_ID &&
      !this.usedRevisionIds.has(preferred)
    ) {
      this.usedRevisionIds.add(preferred)
      if (preferred >= this.nextRevisionId) {
        this.nextRevisionId = preferred + 1
      }
      return preferred
    }
    while (this.usedRevisionIds.has(this.nextRevisionId)) {
      this.nextRevisionId += 1
    }
    const id = this.nextRevisionId
    this.nextRevisionId += 1
    this.usedRevisionIds.add(id)
    return id
  }

  private allocateHex(pool: Set<string>, seed: string, preferred?: string | null): string {
    if (isValidOoxmlHexId(preferred)) {
      const upper = preferred.toUpperCase()
      if (!pool.has(upper)) {
        pool.add(upper)
        return upper
      }
    }
    let candidateNum = fnv1a31(seed)
    let candidate = formatOoxmlHexId(candidateNum)
    while (pool.has(candidate)) {
      candidateNum = candidateNum >= MAX_OOXML_HEX_ID ? 1 : candidateNum + 1
      candidate = formatOoxmlHexId(candidateNum)
    }
    pool.add(candidate)
    return candidate
  }
}
