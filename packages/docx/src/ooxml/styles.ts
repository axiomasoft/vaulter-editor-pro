import type { LineRule, TextAlignment } from '../model/types'
import { findChild, findChildren, getAttr, type XmlElementNode } from './xmlParser'
import { normalizeHexColor, ooxmlToLineHeight } from './units'

export const MAX_STYLE_BASED_ON_DEPTH = 16

export interface ResolvedRunStyle {
  bold: boolean | null
  italic: boolean | null
  underline: string | null
  strike: boolean | null
  superscript: boolean | null
  subscript: boolean | null
  fontFamily: string | null
  fontSizeHalfPt: number | null
  colorHex: string | null
  highlightHex: string | null
}

export interface ResolvedParagraphStyle {
  textAlign: TextAlignment | null
  indentLeftDxa: number | null
  indentFirstLineDxa: number | null
  indentRightDxa: number | null
  spacingBeforeDxa: number | null
  spacingAfterDxa: number | null
  lineSpacing: number | null
  lineRule: LineRule | null
  contextualSpacing: boolean | null
  keepNext: boolean | null
  keepLines: boolean | null
  outlineLevel: number | null
  run: ResolvedRunStyle
}

export type ParagraphStyleProperties = Partial<Omit<ResolvedParagraphStyle, 'run'>> & {
  run?: Partial<ResolvedRunStyle>
}

interface RawStyleDef {
  id: string
  type: string
  basedOn: string | null
  pPr: ParagraphStyleProperties
  rPr: Partial<ResolvedRunStyle>
}

function parseBoolToggle(el: XmlElementNode | undefined): boolean | null {
  if (!el) {
    return null
  }
  const val = getAttr(el, 'w:val', 'val')
  if (val === undefined) {
    return true
  }
  const lower = val.toLowerCase()
  return lower !== '0' && lower !== 'false' && lower !== 'off'
}

function parseIntAttr(el: XmlElementNode | undefined, ...names: string[]): number | null {
  if (!el) {
    return null
  }
  const raw = getAttr(el, ...names)
  if (raw === undefined) {
    return null
  }
  const parsed = Number.parseInt(raw, 10)
  return Number.isFinite(parsed) ? parsed : null
}

export function extractRunProps(rPr: XmlElementNode | undefined): Partial<ResolvedRunStyle> {
  if (!rPr) {
    return {}
  }
  const out: Partial<ResolvedRunStyle> = {}

  const bold = parseBoolToggle(findChild(rPr, 'b'))
  if (bold !== null) out.bold = bold

  const italic = parseBoolToggle(findChild(rPr, 'i'))
  if (italic !== null) out.italic = italic

  const uEl = findChild(rPr, 'u')
  if (uEl) {
    const val = getAttr(uEl, 'w:val', 'val') ?? 'single'
    out.underline = val
  }

  const strike = parseBoolToggle(findChild(rPr, 'strike'))
  if (strike !== null) out.strike = strike

  const vertAlign = findChild(rPr, 'vertAlign')
  if (vertAlign) {
    const val = getAttr(vertAlign, 'w:val', 'val')
    if (val === 'superscript') {
      out.superscript = true
      out.subscript = false
    } else if (val === 'subscript') {
      out.subscript = true
      out.superscript = false
    } else if (val === 'baseline') {
      out.superscript = false
      out.subscript = false
    }
  }

  const rFonts = findChild(rPr, 'rFonts')
  if (rFonts) {
    const ascii = getAttr(rFonts, 'w:ascii', 'ascii') ?? getAttr(rFonts, 'w:hAnsi', 'hAnsi')
    if (ascii) out.fontFamily = ascii
  }

  const sz = parseIntAttr(findChild(rPr, 'sz'), 'w:val', 'val')
  if (sz !== null && sz > 0) out.fontSizeHalfPt = sz

  const color = findChild(rPr, 'color')
  if (color) {
    const hex = normalizeHexColor(getAttr(color, 'w:val', 'val'))
    if (hex) out.colorHex = hex
  }

  const highlight = findChild(rPr, 'highlight')
  if (highlight) {
    const val = getAttr(highlight, 'w:val', 'val')
    if (val) {
      const hex = normalizeHexColor(val) ?? val
      out.highlightHex = hex
    }
  }

  return out
}

export function extractParagraphProps(pPr: XmlElementNode | undefined): ParagraphStyleProperties {
  if (!pPr) {
    return {}
  }
  const out: ParagraphStyleProperties = {}

  const jc = findChild(pPr, 'jc')
  if (jc) {
    const val = getAttr(jc, 'w:val', 'val')
    if (val === 'left' || val === 'start') out.textAlign = 'left'
    else if (val === 'center') out.textAlign = 'center'
    else if (val === 'right' || val === 'end') out.textAlign = 'right'
    else if (val === 'both' || val === 'justify') out.textAlign = 'both'
  }

  const ind = findChild(pPr, 'ind')
  if (ind) {
    const left = parseIntAttr(ind, 'w:left', 'left', 'w:start', 'start')
    const firstLine = parseIntAttr(ind, 'w:firstLine', 'firstLine')
    const hanging = parseIntAttr(ind, 'w:hanging', 'hanging')
    const right = parseIntAttr(ind, 'w:right', 'right', 'w:end', 'end')
    if (left !== null) out.indentLeftDxa = left
    if (firstLine !== null) out.indentFirstLineDxa = firstLine
    else if (hanging !== null) out.indentFirstLineDxa = -hanging
    if (right !== null) out.indentRightDxa = right
  }

  const spacing = findChild(pPr, 'spacing')
  if (spacing) {
    const before = parseIntAttr(spacing, 'w:before', 'before')
    const after = parseIntAttr(spacing, 'w:after', 'after')
    const line = parseIntAttr(spacing, 'w:line', 'line')
    const lineRule = getAttr(spacing, 'w:lineRule', 'lineRule')
    if (before !== null) out.spacingBeforeDxa = before
    if (after !== null) out.spacingAfterDxa = after
    if (line !== null) {
      const parsed = ooxmlToLineHeight(line, lineRule)
      out.lineSpacing = parsed.lineSpacing
      out.lineRule = parsed.lineRule
    }
  }

  const contextualSpacing = parseBoolToggle(findChild(pPr, 'contextualSpacing'))
  if (contextualSpacing !== null) out.contextualSpacing = contextualSpacing

  const keepNext = parseBoolToggle(findChild(pPr, 'keepNext'))
  if (keepNext !== null) out.keepNext = keepNext

  const keepLines = parseBoolToggle(findChild(pPr, 'keepLines'))
  if (keepLines !== null) out.keepLines = keepLines

  const outlineLvl = parseIntAttr(findChild(pPr, 'outlineLvl'), 'w:val', 'val')
  if (outlineLvl !== null) out.outlineLevel = outlineLvl

  const rPr = findChild(pPr, 'rPr')
  if (rPr) {
    out.run = extractRunProps(rPr)
  }

  return out
}

function mergeDefined<T extends object>(base: T, overlay: Partial<T> | undefined): T {
  if (!overlay) {
    return { ...base }
  }
  const next = { ...base }
  for (const [k, v] of Object.entries(overlay)) {
    if (v !== null && v !== undefined) {
      ;(next as Record<string, unknown>)[k] = v
    }
  }
  return next
}

function mergeParagraphStyle(base: ResolvedParagraphStyle, overlay: ParagraphStyleProperties = {}): ResolvedParagraphStyle {
  const { run, ...paragraph } = overlay
  return { ...mergeDefined(base, paragraph), run: mergeDefined(base.run, run) }
}

const EMPTY_RUN_STYLE: ResolvedRunStyle = {
  bold: null,
  italic: null,
  underline: null,
  strike: null,
  superscript: null,
  subscript: null,
  fontFamily: null,
  fontSizeHalfPt: null,
  colorHex: null,
  highlightHex: null,
}

const EMPTY_PARAGRAPH_STYLE: ResolvedParagraphStyle = {
  textAlign: null,
  indentLeftDxa: null,
  indentFirstLineDxa: null,
  indentRightDxa: null,
  spacingBeforeDxa: null,
  spacingAfterDxa: null,
  lineSpacing: null,
  lineRule: null,
  contextualSpacing: null,
  keepNext: null,
  keepLines: null,
  outlineLevel: null,
  run: { ...EMPTY_RUN_STYLE },
}

/**
 * OOXML style cascade resolver (`docDefaults -> tblStyle -> pStyle(basedOn <= 16) -> rStyle -> numPr -> inline`).
 * Null properties strictly mean "inherit", never coerced to `0`.
 */
export class StyleRegistry {
  private readonly stylesById = new Map<string, RawStyleDef>()
  private readonly defaultPPr: ParagraphStyleProperties
  private readonly defaultRPr: Partial<ResolvedRunStyle>

  constructor(stylesRoot?: XmlElementNode | null) {
    this.defaultPPr = {}
    this.defaultRPr = {}
    if (!stylesRoot) {
      return
    }

    const docDefaults = findChild(stylesRoot, 'docDefaults')
    if (docDefaults) {
      const pPrDefault = findChild(findChild(docDefaults, 'pPrDefault') ?? docDefaults, 'pPr')
      const rPrDefault = findChild(findChild(docDefaults, 'rPrDefault') ?? docDefaults, 'rPr')
      Object.assign(this.defaultPPr, extractParagraphProps(pPrDefault))
      Object.assign(this.defaultRPr, extractRunProps(rPrDefault))
    }

    for (const styleEl of findChildren(stylesRoot, 'style')) {
      const id = getAttr(styleEl, 'w:styleId', 'styleId')
      if (!id) {
        continue
      }
      const type = getAttr(styleEl, 'w:type', 'type') ?? 'paragraph'
      const basedOnEl = findChild(styleEl, 'basedOn')
      const basedOn = basedOnEl ? getAttr(basedOnEl, 'w:val', 'val') ?? null : null
      const pPr = extractParagraphProps(findChild(styleEl, 'pPr'))
      const rPr = extractRunProps(findChild(styleEl, 'rPr'))
      this.stylesById.set(id, { id, type, basedOn, pPr, rPr })
    }
  }

  private resolveStyleChain(styleId: string | null | undefined): RawStyleDef[] {
    if (!styleId) {
      return []
    }
    const chain: RawStyleDef[] = []
    const visited = new Set<string>()
    let currentId: string | null = styleId
    let depth = 0

    while (currentId && depth < MAX_STYLE_BASED_ON_DEPTH && !visited.has(currentId)) {
      visited.add(currentId)
      const def = this.stylesById.get(currentId)
      if (!def) {
        break
      }
      chain.push(def)
      currentId = def.basedOn
      depth += 1
    }

    return chain.reverse()
  }

  resolveParagraph(options: {
    tblStyleId?: string | null
    pStyleId?: string | null
    numPrProps?: ParagraphStyleProperties
    inlinePPr?: ParagraphStyleProperties
  }): ResolvedParagraphStyle {
    let acc: ResolvedParagraphStyle = {
      ...EMPTY_PARAGRAPH_STYLE,
      run: { ...EMPTY_RUN_STYLE },
    }
    acc = mergeParagraphStyle(acc, this.defaultPPr)
    acc.run = mergeDefined(acc.run, this.defaultRPr)

    for (const def of this.resolveStyleChain(options.tblStyleId)) {
      acc = mergeParagraphStyle(acc, def.pPr)
      acc.run = mergeDefined(acc.run, def.rPr)
    }

    for (const def of this.resolveStyleChain(options.pStyleId)) {
      acc = mergeParagraphStyle(acc, def.pPr)
      acc.run = mergeDefined(acc.run, def.rPr)
    }

    return mergeParagraphStyle(mergeParagraphStyle(acc, options.numPrProps), options.inlinePPr)
  }

  resolveRun(options: {
    paragraphStyle: ResolvedParagraphStyle
    rStyleId?: string | null
    inlineRPr?: Partial<ResolvedRunStyle>
  }): ResolvedRunStyle {
    let run: ResolvedRunStyle = { ...options.paragraphStyle.run }
    for (const def of this.resolveStyleChain(options.rStyleId)) {
      run = mergeDefined(run, def.rPr)
    }
    if (options.inlineRPr) {
      run = mergeDefined(run, options.inlineRPr)
    }
    return run
  }

  headingLevelForStyle(pStyleId: string | null | undefined, resolvedP: ResolvedParagraphStyle): number | null {
    if (pStyleId) {
      const m = /^Heading([1-6])$/i.exec(pStyleId.trim())
      if (m) {
        return Number.parseInt(m[1], 10)
      }
      for (const def of this.resolveStyleChain(pStyleId)) {
        const parentMatch = /^Heading([1-6])$/i.exec(def.id.trim())
        if (parentMatch) {
          return Number.parseInt(parentMatch[1], 10)
        }
      }
    }
    if (resolvedP.outlineLevel !== null && resolvedP.outlineLevel >= 0 && resolvedP.outlineLevel <= 5) {
      return resolvedP.outlineLevel + 1
    }
    return null
  }
}
