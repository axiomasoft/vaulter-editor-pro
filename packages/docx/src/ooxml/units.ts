import type { LineRule, OoxmlWidth } from '../model/types'

export const DXA_PER_PX = 15
export const DXA_PER_PT = 20
export const EMU_PER_PX = 9525
export const AUTO_LINE_SPACING_BASE = 240
export const PCT_UNITS_PER_PERCENT = 50
export const HALF_POINTS_PER_PT = 2
export const EIGHTH_POINTS_PER_PT = 8
export const MIN_BORDER_EIGHTH_PT = 2
export const MAX_BORDER_EIGHTH_PT = 96

export function pixelsToTwips(px: number): number {
  return Math.round(px * DXA_PER_PX)
}

export function twipsToPixels(twips: number): number {
  return Math.round((twips / DXA_PER_PX) * 100) / 100
}

export function pointsToTwips(pt: number): number {
  return Math.round(pt * DXA_PER_PT)
}

export function twipsToPoints(twips: number): number {
  return Math.round((twips / DXA_PER_PT) * 100) / 100
}

export function pixelsToEmu(px: number): number {
  return Math.round(px * EMU_PER_PX)
}

export function emuToPixels(emu: number): number {
  return Math.round(emu / EMU_PER_PX)
}

export function ptToHalfPoints(pt: number): number {
  return Math.round(pt * HALF_POINTS_PER_PT)
}

export function halfPointsToPt(halfPoints: number): number {
  return halfPoints / HALF_POINTS_PER_PT
}

export function ptToEighthPoints(pt: number): number {
  const raw = Math.round(pt * EIGHTH_POINTS_PER_PT)
  return Math.max(MIN_BORDER_EIGHTH_PT, Math.min(MAX_BORDER_EIGHTH_PT, raw))
}

export function eighthPointsToPt(eighthPt: number): number {
  return eighthPt / EIGHTH_POINTS_PER_PT
}

export function lineHeightToOoxml(
  value: number,
  rule: LineRule = 'auto',
): { line: number; lineRule: LineRule } {
  if (rule === 'auto') {
    return {
      line: Math.round(value * AUTO_LINE_SPACING_BASE),
      lineRule: 'auto',
    }
  }
  return {
    line: Math.round(value),
    lineRule: rule,
  }
}

export function ooxmlToLineHeight(
  rawLine: number,
  rawRule?: string | null,
): { lineSpacing: number; lineRule: LineRule } {
  const rule: LineRule = rawRule === 'exact' || rawRule === 'atLeast' ? rawRule : 'auto'
  if (rule === 'auto') {
    return {
      lineSpacing: Math.round((rawLine / AUTO_LINE_SPACING_BASE) * 1000) / 1000,
      lineRule: 'auto',
    }
  }
  return {
    lineSpacing: Math.round(rawLine),
    lineRule: rule,
  }
}

/**
 * Parses OOXML percentage widths ("5000" or "100%") into fifths-of-a-percent integer units
 * (where 100% = 5000, i.e., 50 units per 1%), or twips ("dxa").
 */
export function parseOoxmlWidth(rawW: string | number | undefined, rawType?: string | null): OoxmlWidth | null {
  if (rawW === undefined || rawW === null) {
    return null
  }
  const str = String(rawW).trim()
  if (str === '') {
    return null
  }
  if (str.endsWith('%')) {
    const pct = Number.parseFloat(str.slice(0, -1))
    if (!Number.isFinite(pct) || pct < 0) {
      return null
    }
    return {
      unit: 'pct',
      value: Math.round(pct * PCT_UNITS_PER_PERCENT),
    }
  }
  const numeric = Number.parseFloat(str)
  if (!Number.isFinite(numeric) || numeric < 0) {
    return null
  }
  if (rawType === 'pct') {
    return {
      unit: 'pct',
      value: Math.round(numeric),
    }
  }
  return {
    unit: 'dxa',
    value: Math.round(numeric),
  }
}

export function formatOoxmlPct(fifthsOfPercent: number): string {
  return String(Math.round(fifthsOfPercent))
}

export function parseFontSizeToHalfPt(raw: unknown): number | null {
  if (typeof raw === 'number' && Number.isFinite(raw) && raw > 0) {
    return ptToHalfPoints(raw)
  }
  if (typeof raw !== 'string') {
    return null
  }
  const trimmed = raw.trim().toLowerCase()
  if (trimmed === '') {
    return null
  }
  if (trimmed.endsWith('pt')) {
    const pt = Number.parseFloat(trimmed.slice(0, -2))
    return Number.isFinite(pt) && pt > 0 ? ptToHalfPoints(pt) : null
  }
  if (trimmed.endsWith('px')) {
    const px = Number.parseFloat(trimmed.slice(0, -2))
    return Number.isFinite(px) && px > 0 ? ptToHalfPoints((px * 72) / 96) : null
  }
  const n = Number.parseFloat(trimmed)
  return Number.isFinite(n) && n > 0 ? ptToHalfPoints(n) : null
}

export function normalizeHexColor(raw: unknown): string | null {
  if (typeof raw !== 'string') {
    return null
  }
  const trimmed = raw.trim()
  if (trimmed === '' || trimmed.toLowerCase() === 'auto' || trimmed.toLowerCase() === 'transparent') {
    return null
  }
  const noHash = trimmed.startsWith('#') ? trimmed.slice(1) : trimmed
  if (/^[0-9a-f]{6}$/i.test(noHash)) {
    return noHash.toUpperCase()
  }
  if (/^[0-9a-f]{3}$/i.test(noHash)) {
    return noHash
      .split('')
      .map((c) => `${c}${c}`)
      .join('')
      .toUpperCase()
  }
  const rgbMatch = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*[\d.]+)?\s*\)$/i.exec(trimmed)
  if (rgbMatch) {
    const hex = [rgbMatch[1], rgbMatch[2], rgbMatch[3]]
      .map((part) => Math.max(0, Math.min(255, Number.parseInt(part, 10))).toString(16).padStart(2, '0'))
      .join('')
      .toUpperCase()
    return hex
  }
  return null
}

const SAFE_HYPERLINK_SCHEMES = new Set(['https:', 'http:', 'mailto:'])

export function isSafeHyperlinkUrl(raw: unknown): boolean {
  if (typeof raw !== 'string') {
    return false
  }
  // eslint-disable-next-line no-control-regex
  const cleaned = raw.replace(/[\u0000-\u0020\u007f]+/g, '').trim()
  if (cleaned === '' || cleaned.startsWith('\\\\') || cleaned.startsWith('//')) {
    return false
  }
  try {
    const parsed = new URL(cleaned)
    return SAFE_HYPERLINK_SCHEMES.has(parsed.protocol.toLowerCase())
  } catch {
    return false
  }
}
