import { SaxesParser } from 'saxes'

export const DEFAULT_ZIP_QUOTAS = {
  maxEntries: 2000,
  maxFileBytes: 50 * 1024 * 1024, // 50 MiB
  maxTotalBytes: 150 * 1024 * 1024, // 150 MiB
  maxCompressionRatio: 100,
} as const

export const DEFAULT_MEDIA_QUOTAS = {
  maxMediaFiles: 256,
  maxMediaFileBytes: 15 * 1024 * 1024, // 15 MiB
  maxTotalMediaBytes: 50 * 1024 * 1024, // 50 MiB
  maxImageDimensionPx: 16384,
  maxImagePixels: 64_000_000,
} as const

export interface ZipQuotas {
  maxEntries?: number
  maxFileBytes?: number
  maxTotalBytes?: number
  maxCompressionRatio?: number
  maxMediaFiles?: number
  maxMediaFileBytes?: number
  maxTotalMediaBytes?: number
  maxImageDimensionPx?: number
  maxImagePixels?: number
}

export type DocxSecurityErrorCode =
  | 'zip_invalid_archive'
  | 'zip_slip'
  | 'zip_symlink'
  | 'zip_special_file'
  | 'zip_duplicate_entry'
  | 'zip_quota_exceeded'
  | 'zip_bomb_ratio'
  | 'zip_overlapping_ranges'
  | 'xml_xxe_forbidden'
  | 'xml_malformed'
  | 'xml_depth_exceeded'
  | 'external_rel_forbidden'
  | 'media_invalid_format'
  | 'media_svg_unsafe'
  | 'media_quota_exceeded'

export class DocxSecurityError extends Error {
  readonly code: DocxSecurityErrorCode

  constructor(code: DocxSecurityErrorCode, message: string) {
    super(message)
    this.name = 'DocxSecurityError'
    this.code = code
  }
}

export interface ZipEntryMeta {
  name: string
  compressedSize: number
  uncompressedSize: number
  compressionMethod: number
  externalFileAttributes: number
  localHeaderOffset: number
  dataStartOffset?: number
}

export interface ZipReader {
  readEntries(bytes: Uint8Array, quotas?: ZipQuotas): Promise<Record<string, Uint8Array>>
}

const S_IFMT = 0o170000
const S_IFDIR = 0o040000
const S_IFREG = 0o100000
const S_IFLNK = 0o120000

export function resolveZipQuotas(quotas?: ZipQuotas): {
  maxEntries: number
  maxFileBytes: number
  maxTotalBytes: number
  maxCompressionRatio: number
} {
  return {
    maxEntries: quotas?.maxEntries ?? DEFAULT_ZIP_QUOTAS.maxEntries,
    maxFileBytes: quotas?.maxFileBytes ?? DEFAULT_ZIP_QUOTAS.maxFileBytes,
    maxTotalBytes: quotas?.maxTotalBytes ?? DEFAULT_ZIP_QUOTAS.maxTotalBytes,
    maxCompressionRatio: quotas?.maxCompressionRatio ?? DEFAULT_ZIP_QUOTAS.maxCompressionRatio,
  }
}

export function resolveMediaQuotas(quotas?: ZipQuotas): {
  maxMediaFiles: number
  maxMediaFileBytes: number
  maxTotalMediaBytes: number
  maxImageDimensionPx: number
  maxImagePixels: number
} {
  return {
    maxMediaFiles: quotas?.maxMediaFiles ?? DEFAULT_MEDIA_QUOTAS.maxMediaFiles,
    maxMediaFileBytes: quotas?.maxMediaFileBytes ?? DEFAULT_MEDIA_QUOTAS.maxMediaFileBytes,
    maxTotalMediaBytes: quotas?.maxTotalMediaBytes ?? DEFAULT_MEDIA_QUOTAS.maxTotalMediaBytes,
    maxImageDimensionPx: quotas?.maxImageDimensionPx ?? DEFAULT_MEDIA_QUOTAS.maxImageDimensionPx,
    maxImagePixels: quotas?.maxImagePixels ?? DEFAULT_MEDIA_QUOTAS.maxImagePixels,
  }
}

export function assertSafeZipEntryPath(name: string): void {
  if (name.length === 0) {
    throw new DocxSecurityError('zip_slip', 'Empty ZIP entry path is forbidden.')
  }
  if (name.includes('\0')) {
    throw new DocxSecurityError('zip_slip', `ZIP entry "${name}" contains NUL byte.`)
  }
  if (name.includes('\\')) {
    throw new DocxSecurityError('zip_slip', `ZIP entry "${name}" contains forbidden backslash.`)
  }
  if (name.startsWith('/')) {
    throw new DocxSecurityError('zip_slip', `ZIP entry "${name}" has forbidden leading slash.`)
  }
  if (/^[A-Za-z]:/.test(name)) {
    throw new DocxSecurityError('zip_slip', `ZIP entry "${name}" has forbidden Windows drive prefix.`)
  }
  const trimmed = name.endsWith('/') ? name.slice(0, -1) : name
  const segments = trimmed.split('/')
  for (const segment of segments) {
    if (segment === '' || segment === '.' || segment === '..') {
      throw new DocxSecurityError('zip_slip', `ZIP entry "${name}" contains forbidden path traversal segment "${segment}".`)
    }
  }
}

export function assertSafeExternalAttributes(entry: ZipEntryMeta): void {
  const mode = (entry.externalFileAttributes >>> 16) & S_IFMT
  if (mode === S_IFLNK) {
    throw new DocxSecurityError('zip_symlink', `ZIP entry "${entry.name}" is a UNIX symlink (S_IFLNK), which is forbidden.`)
  }
  if (mode !== 0 && mode !== S_IFREG && mode !== S_IFDIR) {
    throw new DocxSecurityError('zip_special_file', `ZIP entry "${entry.name}" has forbidden UNIX special file mode 0o${mode.toString(8)}.`)
  }
  if (mode === S_IFDIR && entry.uncompressedSize > 0) {
    throw new DocxSecurityError('zip_invalid_archive', `Directory ZIP entry "${entry.name}" declares non-zero size.`)
  }
}

export function validateZipDirectoryEntries(
  entries: readonly ZipEntryMeta[],
  quotas?: ZipQuotas,
): void {
  const limits = resolveZipQuotas(quotas)
  if (entries.length > limits.maxEntries) {
    throw new DocxSecurityError(
      'zip_quota_exceeded',
      `ZIP entry count ${entries.length} exceeds limit ${limits.maxEntries}.`,
    )
  }

  const seenLower = new Set<string>()
  let declaredTotalBytes = 0
  const ranges: Array<{ name: string; start: number; end: number }> = []

  for (const entry of entries) {
    assertSafeZipEntryPath(entry.name)
    assertSafeExternalAttributes(entry)

    const lower = entry.name.toLowerCase()
    if (seenLower.has(lower)) {
      throw new DocxSecurityError(
        'zip_duplicate_entry',
        `Duplicate case-insensitive ZIP entry "${entry.name}" detected.`,
      )
    }
    seenLower.add(lower)

    if (entry.uncompressedSize > limits.maxFileBytes) {
      throw new DocxSecurityError(
        'zip_quota_exceeded',
        `ZIP entry "${entry.name}" size ${entry.uncompressedSize} exceeds ${limits.maxFileBytes} bytes.`,
      )
    }

    declaredTotalBytes += entry.uncompressedSize
    if (declaredTotalBytes > limits.maxTotalBytes) {
      throw new DocxSecurityError(
        'zip_quota_exceeded',
        `Total declared uncompressed size ${declaredTotalBytes} exceeds ${limits.maxTotalBytes} bytes.`,
      )
    }

    if (entry.uncompressedSize > 0) {
      if (entry.compressedSize === 0) {
        throw new DocxSecurityError(
          'zip_bomb_ratio',
          `ZIP entry "${entry.name}" has zero compressed size for ${entry.uncompressedSize} uncompressed bytes.`,
        )
      }
      const ratio = entry.uncompressedSize / entry.compressedSize
      if (ratio > limits.maxCompressionRatio) {
        throw new DocxSecurityError(
          'zip_bomb_ratio',
          `ZIP entry "${entry.name}" compression ratio ${ratio.toFixed(1)}x exceeds ${limits.maxCompressionRatio}x.`,
        )
      }
    }

    const dataStart = entry.dataStartOffset ?? entry.localHeaderOffset
    const end = dataStart + entry.compressedSize
    ranges.push({ name: entry.name, start: entry.localHeaderOffset, end })
  }

  ranges.sort((a, b) => a.start - b.start)
  for (let i = 1; i < ranges.length; i += 1) {
    const prev = ranges[i - 1]
    const curr = ranges[i]
    if (curr.start < prev.end) {
      throw new DocxSecurityError(
        'zip_overlapping_ranges',
        `Overlapping ZIP byte ranges detected between "${prev.name}" [${prev.start}, ${prev.end}) and "${curr.name}" [${curr.start}, ${curr.end}).`,
      )
    }
  }
}

export function assertActualEntryDecompressedSize(
  name: string,
  actualBytes: number,
  compressedSize: number,
  runningTotalBytes: number,
  quotas?: ZipQuotas,
): number {
  const limits = resolveZipQuotas(quotas)
  if (actualBytes > limits.maxFileBytes) {
    throw new DocxSecurityError(
      'zip_quota_exceeded',
      `Actual decompressed size of "${name}" (${actualBytes}) exceeds ${limits.maxFileBytes} bytes.`,
    )
  }
  const nextTotal = runningTotalBytes + actualBytes
  if (nextTotal > limits.maxTotalBytes) {
    throw new DocxSecurityError(
      'zip_quota_exceeded',
      `Actual total decompressed size (${nextTotal}) exceeds ${limits.maxTotalBytes} bytes.`,
    )
  }
  if (actualBytes > 0) {
    if (compressedSize <= 0 || actualBytes / compressedSize > limits.maxCompressionRatio) {
      throw new DocxSecurityError(
        'zip_bomb_ratio',
        `Actual compression ratio for "${name}" exceeds ${limits.maxCompressionRatio}x.`,
      )
    }
  }
  return nextTotal
}

function readUint16LE(bytes: Uint8Array, offset: number): number {
  return bytes[offset] | (bytes[offset + 1] << 8)
}

function readUint32LE(bytes: Uint8Array, offset: number): number {
  return (
    (bytes[offset] |
      (bytes[offset + 1] << 8) |
      (bytes[offset + 2] << 16) |
      (bytes[offset + 3] << 24)) >>>
    0
  )
}

/**
 * Pure-TS Central Directory parser for browser/isomorphic environments before calling `fflate`.
 * Reads EOCD + Central Directory File Headers + Local File Header offsets so `externalFileAttributes`
 * (`S_IFLNK`) and byte-range overlaps are verified identically to Node `yauzl`.
 */
export function parseZipCentralDirectory(bytes: Uint8Array): ZipEntryMeta[] {
  if (bytes.length < 22) {
    throw new DocxSecurityError('zip_invalid_archive', 'Buffer is too small to be a valid ZIP archive.')
  }

  const minOffset = Math.max(0, bytes.length - 22 - 65535)
  let eocdOffset = -1
  for (let pos = bytes.length - 22; pos >= minOffset; pos -= 1) {
    if (
      bytes[pos] === 0x50 &&
      bytes[pos + 1] === 0x4b &&
      bytes[pos + 2] === 0x05 &&
      bytes[pos + 3] === 0x06
    ) {
      const commentLength = readUint16LE(bytes, pos + 20)
      if (pos + 22 + commentLength === bytes.length) {
        eocdOffset = pos
        break
      }
    }
  }

  if (eocdOffset === -1) {
    throw new DocxSecurityError('zip_invalid_archive', 'ZIP End of Central Directory record not found.')
  }

  const diskNumber = readUint16LE(bytes, eocdOffset + 4)
  const cdStartDisk = readUint16LE(bytes, eocdOffset + 6)
  if (diskNumber !== 0 || cdStartDisk !== 0) {
    throw new DocxSecurityError('zip_invalid_archive', 'Multi-disk ZIP archives are not supported.')
  }

  const entryCount = readUint16LE(bytes, eocdOffset + 10)
  const cdSize = readUint32LE(bytes, eocdOffset + 12)
  const cdOffset = readUint32LE(bytes, eocdOffset + 16)

  if (
    entryCount === 0xffff ||
    cdSize === 0xffffffff ||
    cdOffset === 0xffffffff ||
    cdOffset + cdSize > eocdOffset
  ) {
    throw new DocxSecurityError('zip_invalid_archive', 'ZIP central directory bounds are invalid or Zip64 exceeds supported limits.')
  }

  const decoder = new TextDecoder('utf-8', { fatal: false })
  const entries: ZipEntryMeta[] = []
  let cursor = cdOffset

  for (let i = 0; i < entryCount; i += 1) {
    if (cursor + 46 > cdOffset + cdSize || readUint32LE(bytes, cursor) !== 0x02014b50) {
      throw new DocxSecurityError('zip_invalid_archive', `Corrupt ZIP central directory header at index ${i}.`)
    }

    const compressionMethod = readUint16LE(bytes, cursor + 10)
    const compressedSize = readUint32LE(bytes, cursor + 20)
    const uncompressedSize = readUint32LE(bytes, cursor + 24)
    const fileNameLen = readUint16LE(bytes, cursor + 28)
    const extraLen = readUint16LE(bytes, cursor + 30)
    const commentLen = readUint16LE(bytes, cursor + 32)
    const externalFileAttributes = readUint32LE(bytes, cursor + 38)
    const localHeaderOffset = readUint32LE(bytes, cursor + 42)

    const nameStart = cursor + 46
    const nameEnd = nameStart + fileNameLen
    const nextCursor = nameEnd + extraLen + commentLen
    if (nextCursor > cdOffset + cdSize) {
      throw new DocxSecurityError('zip_invalid_archive', `Truncated central directory entry at index ${i}.`)
    }

    const name = decoder.decode(bytes.subarray(nameStart, nameEnd))

    if (localHeaderOffset + 30 > cdOffset || readUint32LE(bytes, localHeaderOffset) !== 0x04034b50) {
      throw new DocxSecurityError('zip_invalid_archive', `Missing or invalid local file header for "${name}".`)
    }
    const localNameLen = readUint16LE(bytes, localHeaderOffset + 26)
    const localExtraLen = readUint16LE(bytes, localHeaderOffset + 28)
    const dataStartOffset = localHeaderOffset + 30 + localNameLen + localExtraLen
    if (dataStartOffset + compressedSize > cdOffset) {
      throw new DocxSecurityError('zip_invalid_archive', `Compressed payload of "${name}" exceeds central directory boundary.`)
    }

    entries.push({
      name,
      compressedSize,
      uncompressedSize,
      compressionMethod,
      externalFileAttributes,
      localHeaderOffset,
      dataStartOffset,
    })

    cursor = nextCursor
  }

  return entries
}

export type SupportedImageFormat = 'png' | 'jpg' | 'gif' | 'bmp' | 'svg'

export interface ValidatedMediaAsset {
  format: SupportedImageFormat
  mimeType: string
  bytes: Uint8Array
  widthPx?: number
  heightPx?: number
}

export function mimeTypeForImageFormat(format: SupportedImageFormat): string {
  switch (format) {
    case 'png':
      return 'image/png'
    case 'jpg':
      return 'image/jpeg'
    case 'gif':
      return 'image/gif'
    case 'bmp':
      return 'image/bmp'
    case 'svg':
      return 'image/svg+xml'
  }
}

export function imageFormatFromExtension(fileName: string): SupportedImageFormat | null {
  const dot = fileName.lastIndexOf('.')
  if (dot === -1) return null
  const ext = fileName.slice(dot + 1).toLowerCase()
  switch (ext) {
    case 'png':
      return 'png'
    case 'jpg':
    case 'jpeg':
      return 'jpg'
    case 'gif':
      return 'gif'
    case 'bmp':
      return 'bmp'
    case 'svg':
      return 'svg'
    default:
      return null
  }
}

export function encodeBase64Bytes(bytes: Uint8Array): string {
  let binary = ''
  const chunkSize = 0x8000
  for (let i = 0; i < bytes.length; i += chunkSize) {
    const slice = bytes.subarray(i, i + chunkSize)
    binary += String.fromCharCode(...slice)
  }
  return btoa(binary)
}

export function decodeBase64Bytes(raw: string): Uint8Array {
  const cleaned = raw.replace(/\s+/g, '')
  if (cleaned.length === 0 || cleaned.length % 4 !== 0 || !/^[A-Za-z0-9+/]+={0,2}$/.test(cleaned)) {
    throw new DocxSecurityError('media_invalid_format', 'Malformed base64 image payload.')
  }
  let binary: string
  try {
    binary = atob(cleaned)
  } catch {
    throw new DocxSecurityError('media_invalid_format', 'Invalid base64 image payload.')
  }
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes
}

function readUint16BE(bytes: Uint8Array, offset: number): number {
  return (bytes[offset] << 8) | bytes[offset + 1]
}

function readUint32BE(bytes: Uint8Array, offset: number): number {
  return (
    ((bytes[offset] << 24) |
      (bytes[offset + 1] << 16) |
      (bytes[offset + 2] << 8) |
      bytes[offset + 3]) >>>
    0
  )
}

function readInt32LE(bytes: Uint8Array, offset: number): number {
  return (
    bytes[offset] |
    (bytes[offset + 1] << 8) |
    (bytes[offset + 2] << 16) |
    (bytes[offset + 3] << 24)
  )
}

function assertImageDimensionsWithinQuotas(
  widthPx: number | undefined,
  heightPx: number | undefined,
  limits: ReturnType<typeof resolveMediaQuotas>,
  label: string,
): void {
  if (widthPx === undefined || heightPx === undefined) {
    return
  }
  if (
    !Number.isFinite(widthPx) ||
    !Number.isFinite(heightPx) ||
    widthPx <= 0 ||
    heightPx <= 0
  ) {
    throw new DocxSecurityError('media_invalid_format', `Image "${label}" has invalid dimensions.`)
  }
  if (widthPx > limits.maxImageDimensionPx || heightPx > limits.maxImageDimensionPx) {
    throw new DocxSecurityError(
      'media_quota_exceeded',
      `Image "${label}" dimensions (${widthPx}x${heightPx}) exceed max ${limits.maxImageDimensionPx}px.`,
    )
  }
  if (widthPx * heightPx > limits.maxImagePixels) {
    throw new DocxSecurityError(
      'media_quota_exceeded',
      `Image "${label}" pixel count (${widthPx * heightPx}) exceeds max ${limits.maxImagePixels}.`,
    )
  }
}

const SVG_FORBIDDEN_ELEMENTS = new Set([
  'script',
  'foreignobject',
  'iframe',
  'frame',
  'frameset',
  'object',
  'embed',
  'applet',
  'meta',
  'link',
  'base',
  'handler',
  'listener',
])

const SVG_FORBIDDEN_XML_RE = /<!\s*(?:DOCTYPE|ENTITY|ELEMENT|ATTLIST)\b|<[A-Za-z0-9_-]*:include\b|http:\/\/www\.w3\.org\/2001\/XInclude/i

function isSafeSvgUri(raw: string): boolean {
  // eslint-disable-next-line no-control-regex
  const cleaned = raw.replace(/[\u0000-\u0020\u007f]+/g, '').trim()
  if (cleaned === '' || cleaned.startsWith('#')) {
    return true
  }
  return /^data:image\/(?:png|jpe?g|gif|bmp);base64,[A-Za-z0-9+/=]+$/i.test(cleaned)
}

function hasUnsafeSvgCss(raw: string): boolean {
  const normalized = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/[\u0000-\u0020\u007f]+/g, '').toLowerCase()
  return (
    normalized.includes('javascript:') ||
    normalized.includes('vbscript:') ||
    normalized.includes('expression(') ||
    normalized.includes('@import') ||
    normalized.includes('-moz-binding') ||
    normalized.includes('data:text/html') ||
    normalized.includes('url(http:') ||
    normalized.includes('url(https:')
  )
}

function assertSafeSvgBytes(
  bytes: Uint8Array,
  limits: ReturnType<typeof resolveMediaQuotas>,
  label: string,
): { widthPx?: number; heightPx?: number } {
  let text: string
  try {
    text = new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  } catch {
    throw new DocxSecurityError('media_invalid_format', `SVG "${label}" is not valid UTF-8.`)
  }
  if (SVG_FORBIDDEN_XML_RE.test(text)) {
    throw new DocxSecurityError('xml_xxe_forbidden', `SVG "${label}" contains forbidden DOCTYPE/ENTITY/XInclude construct.`)
  }

  let depth = 0
  let rootLocal = ''
  let inStyleTag = false
  let styleText = ''
  let widthPx: number | undefined
  let heightPx: number | undefined
  let securityError: DocxSecurityError | null = null

  const parser = new SaxesParser({ xmlns: false, position: true })
  parser.on('doctype', () => {
    if (!securityError) {
      securityError = new DocxSecurityError('xml_xxe_forbidden', `SVG "${label}" declares forbidden DOCTYPE.`)
    }
  })
  parser.on('opentag', (tag) => {
    depth += 1
    if (depth > 256) {
      if (!securityError) {
        securityError = new DocxSecurityError('xml_depth_exceeded', `SVG "${label}" exceeded max XML depth.`)
      }
      return
    }
    if (securityError) return
    const idx = tag.name.indexOf(':')
    const local = (idx === -1 ? tag.name : tag.name.slice(idx + 1)).toLowerCase()
    if (!rootLocal) {
      rootLocal = local
      if (local !== 'svg') {
        securityError = new DocxSecurityError('media_invalid_format', `SVG "${label}" root element must be <svg>.`)
        return
      }
      const wAttr = tag.attributes.width
      const hAttr = tag.attributes.height
      const wNum = wAttr ? Number.parseFloat(wAttr) : Number.NaN
      const hNum = hAttr ? Number.parseFloat(hAttr) : Number.NaN
      if (Number.isFinite(wNum) && wNum > 0) widthPx = Math.round(wNum)
      if (Number.isFinite(hNum) && hNum > 0) heightPx = Math.round(hNum)
    }

    if (SVG_FORBIDDEN_ELEMENTS.has(local)) {
      securityError = new DocxSecurityError('media_svg_unsafe', `SVG "${label}" contains forbidden <${local}> element.`)
      return
    }
    if (local === 'style') {
      inStyleTag = true
      styleText = ''
    }

    for (const [rawKey, rawVal] of Object.entries(tag.attributes)) {
      const kIdx = rawKey.indexOf(':')
      const attrLocal = (kIdx === -1 ? rawKey : rawKey.slice(kIdx + 1)).toLowerCase()
      if (attrLocal.startsWith('on')) {
        securityError = new DocxSecurityError('media_svg_unsafe', `SVG "${label}" contains forbidden event attribute "${rawKey}".`)
        return
      }
      if (
        (local === 'animate' || local === 'set' || local === 'animatemotion' || local === 'animatetransform') &&
        attrLocal === 'attributename' &&
        (rawVal.trim().toLowerCase() === 'href' || rawVal.trim().toLowerCase() === 'xlink:href' || rawVal.trim().toLowerCase().startsWith('on'))
      ) {
        securityError = new DocxSecurityError('media_svg_unsafe', `SVG "${label}" animates forbidden attribute "${rawVal}".`)
        return
      }
      if (attrLocal === 'href' || attrLocal === 'src' || attrLocal === 'action' || attrLocal === 'formaction' || attrLocal === 'poster' || attrLocal === 'data') {
        if (!isSafeSvgUri(rawVal)) {
          securityError = new DocxSecurityError('media_svg_unsafe', `SVG "${label}" contains unsafe URI in "${rawKey}".`)
          return
        }
      }
      if (attrLocal === 'style' && hasUnsafeSvgCss(rawVal)) {
        securityError = new DocxSecurityError('media_svg_unsafe', `SVG "${label}" contains unsafe CSS in style attribute.`)
        return
      }
    }
  })
  parser.on('text', (chunk) => {
    if (inStyleTag) styleText += chunk
  })
  parser.on('cdata', (chunk) => {
    if (inStyleTag) styleText += chunk
  })
  parser.on('closetag', () => {
    if (inStyleTag) {
      inStyleTag = false
      if (!securityError && hasUnsafeSvgCss(styleText)) {
        securityError = new DocxSecurityError('media_svg_unsafe', `SVG "${label}" contains unsafe CSS in <style>.`)
      }
    }
    depth = Math.max(0, depth - 1)
  })
  parser.on('error', (err) => {
    if (!securityError) {
      securityError = new DocxSecurityError('media_invalid_format', `Malformed SVG "${label}": ${err.message}`)
    }
  })

  parser.write(text).close()
  if (securityError) {
    throw securityError
  }
  if (rootLocal !== 'svg') {
    throw new DocxSecurityError('media_invalid_format', `SVG "${label}" is missing <svg> root element.`)
  }
  assertImageDimensionsWithinQuotas(widthPx, heightPx, limits, label)
  return { widthPx, heightPx }
}

function sniffPng(bytes: Uint8Array, label: string): { format: 'png'; widthPx: number; heightPx: number } {
  if (bytes.length < 33) {
    throw new DocxSecurityError('media_invalid_format', `Truncated PNG "${label}".`)
  }
  const ihdrLen = readUint32BE(bytes, 8)
  const ihdrTag = String.fromCharCode(bytes[12], bytes[13], bytes[14], bytes[15])
  if (ihdrLen !== 13 || ihdrTag !== 'IHDR') {
    throw new DocxSecurityError('media_invalid_format', `Invalid PNG IHDR chunk in "${label}".`)
  }
  const widthPx = readUint32BE(bytes, 16)
  const heightPx = readUint32BE(bytes, 20)
  if (widthPx === 0 || heightPx === 0) {
    throw new DocxSecurityError('media_invalid_format', `PNG "${label}" has zero dimensions.`)
  }
  let pos = 8
  let sawIend = false
  while (pos + 12 <= bytes.length) {
    const len = readUint32BE(bytes, pos)
    const tag = String.fromCharCode(bytes[pos + 4], bytes[pos + 5], bytes[pos + 6], bytes[pos + 7])
    const nextPos = pos + 12 + len
    if (nextPos > bytes.length) {
      throw new DocxSecurityError('media_invalid_format', `Truncated PNG chunk "${tag}" in "${label}".`)
    }
    pos = nextPos
    if (tag === 'IEND') {
      sawIend = true
      break
    }
  }
  if (!sawIend) {
    throw new DocxSecurityError('media_invalid_format', `PNG "${label}" is missing IEND chunk.`)
  }
  return { format: 'png', widthPx, heightPx }
}

function sniffJpeg(bytes: Uint8Array, label: string): { format: 'jpg'; widthPx: number; heightPx: number } {
  if (bytes.length < 4) {
    throw new DocxSecurityError('media_invalid_format', `Truncated JPEG "${label}".`)
  }
  let pos = 2
  let widthPx: number | undefined
  let heightPx: number | undefined
  let sawEoi = false

  while (pos < bytes.length) {
    if (bytes[pos] !== 0xff) {
      throw new DocxSecurityError('media_invalid_format', `Corrupt JPEG marker stream in "${label}".`)
    }
    while (pos < bytes.length && bytes[pos] === 0xff) pos += 1
    if (pos >= bytes.length) break
    const marker = bytes[pos]
    pos += 1
    if (marker === 0xd9) {
      sawEoi = true
      break
    }
    if (marker === 0x00 || marker === 0x01 || (marker >= 0xd0 && marker <= 0xd8)) {
      continue
    }
    if (pos + 2 > bytes.length) {
      throw new DocxSecurityError('media_invalid_format', `Truncated JPEG segment in "${label}".`)
    }
    const segLen = readUint16BE(bytes, pos)
    if (segLen < 2 || pos + segLen > bytes.length) {
      throw new DocxSecurityError('media_invalid_format', `Invalid JPEG segment length in "${label}".`)
    }
    const isSof =
      (marker >= 0xc0 && marker <= 0xc3) ||
      (marker >= 0xc5 && marker <= 0xc7) ||
      (marker >= 0xc9 && marker <= 0xcb) ||
      (marker >= 0xcd && marker <= 0xcf)
    if (isSof && segLen >= 7) {
      heightPx = readUint16BE(bytes, pos + 3)
      widthPx = readUint16BE(bytes, pos + 5)
    }
    pos += segLen
    if (marker === 0xda) {
      while (pos + 1 < bytes.length) {
        if (bytes[pos] === 0xff && bytes[pos + 1] !== 0x00 && !(bytes[pos + 1] >= 0xd0 && bytes[pos + 1] <= 0xd7)) {
          break
        }
        pos += 1
      }
    }
  }

  if (!sawEoi || !widthPx || !heightPx) {
    throw new DocxSecurityError('media_invalid_format', `Invalid or truncated JPEG "${label}".`)
  }
  return { format: 'jpg', widthPx, heightPx }
}

function sniffGif(bytes: Uint8Array, label: string): { format: 'gif'; widthPx: number; heightPx: number } {
  if (bytes.length < 14) {
    throw new DocxSecurityError('media_invalid_format', `Truncated GIF "${label}".`)
  }
  const widthPx = readUint16LE(bytes, 6)
  const heightPx = readUint16LE(bytes, 8)
  if (widthPx === 0 || heightPx === 0) {
    throw new DocxSecurityError('media_invalid_format', `GIF "${label}" has zero dimensions.`)
  }
  return { format: 'gif', widthPx, heightPx }
}

function sniffBmp(bytes: Uint8Array, label: string): { format: 'bmp'; widthPx: number; heightPx: number } {
  if (bytes.length < 26) {
    throw new DocxSecurityError('media_invalid_format', `Truncated BMP "${label}".`)
  }
  const pixelOffset = readUint32LE(bytes, 10)
  const dibSize = readUint32LE(bytes, 14)
  if (14 + dibSize > bytes.length || pixelOffset < 14 + dibSize || pixelOffset > bytes.length) {
    throw new DocxSecurityError('media_invalid_format', `Invalid BMP header in "${label}".`)
  }
  let widthPx: number
  let heightPx: number
  if (dibSize === 12) {
    widthPx = readUint16LE(bytes, 18)
    heightPx = readUint16LE(bytes, 20)
  } else if (dibSize >= 40) {
    widthPx = readInt32LE(bytes, 18)
    heightPx = Math.abs(readInt32LE(bytes, 22))
  } else {
    throw new DocxSecurityError('media_invalid_format', `Unsupported BMP DIB header size ${dibSize} in "${label}".`)
  }
  if (widthPx <= 0 || heightPx <= 0) {
    throw new DocxSecurityError('media_invalid_format', `BMP "${label}" has invalid dimensions.`)
  }
  return { format: 'bmp', widthPx, heightPx }
}

export function validateMediaAsset(
  bytes: Uint8Array,
  options: {
    fileName?: string
    expectedFormat?: SupportedImageFormat
    expectedMime?: string
    quotas?: ZipQuotas
  } = {},
): ValidatedMediaAsset {
  const label = options.fileName ?? options.expectedMime ?? 'media'
  const limits = resolveMediaQuotas(options.quotas)

  if (bytes.byteLength === 0) {
    throw new DocxSecurityError('media_invalid_format', `Media "${label}" is empty.`)
  }
  if (bytes.byteLength > limits.maxMediaFileBytes) {
    throw new DocxSecurityError(
      'media_quota_exceeded',
      `Media "${label}" (${bytes.byteLength} bytes) exceeds max ${limits.maxMediaFileBytes} bytes.`,
    )
  }

  let detected: { format: SupportedImageFormat; widthPx?: number; heightPx?: number }
  if (
    bytes.length >= 8 &&
    bytes[0] === 0x89 &&
    bytes[1] === 0x50 &&
    bytes[2] === 0x4e &&
    bytes[3] === 0x47 &&
    bytes[4] === 0x0d &&
    bytes[5] === 0x0a &&
    bytes[6] === 0x1a &&
    bytes[7] === 0x0a
  ) {
    detected = sniffPng(bytes, label)
  } else if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
    detected = sniffJpeg(bytes, label)
  } else if (
    bytes.length >= 6 &&
    bytes[0] === 0x47 &&
    bytes[1] === 0x49 &&
    bytes[2] === 0x46 &&
    bytes[3] === 0x38 &&
    (bytes[4] === 0x37 || bytes[4] === 0x39) &&
    bytes[5] === 0x61
  ) {
    detected = sniffGif(bytes, label)
  } else if (bytes.length >= 2 && bytes[0] === 0x42 && bytes[1] === 0x4d) {
    detected = sniffBmp(bytes, label)
  } else {
    const extFormat = options.fileName ? imageFormatFromExtension(options.fileName) : null
    const isSvgHint = options.expectedFormat === 'svg' || extFormat === 'svg'
    const head = new TextDecoder('utf-8', { fatal: false }).decode(bytes.subarray(0, 64)).trimStart()
    if (isSvgHint || head.startsWith('<svg') || head.startsWith('<?xml')) {
      const svgDims = assertSafeSvgBytes(bytes, limits, label)
      detected = { format: 'svg', ...svgDims }
    } else {
      throw new DocxSecurityError('media_invalid_format', `Unrecognized or corrupt image format in "${label}".`)
    }
  }

  const extFormat = options.fileName ? imageFormatFromExtension(options.fileName) : null
  if (options.expectedFormat && detected.format !== options.expectedFormat) {
    throw new DocxSecurityError(
      'media_invalid_format',
      `Media "${label}" declared as ${options.expectedFormat} but sniffed as ${detected.format}.`,
    )
  }
  if (extFormat && detected.format !== extFormat) {
    throw new DocxSecurityError(
      'media_invalid_format',
      `Media "${label}" extension (${extFormat}) does not match sniffed format (${detected.format}).`,
    )
  }

  assertImageDimensionsWithinQuotas(detected.widthPx, detected.heightPx, limits, label)

  return {
    format: detected.format,
    mimeType: mimeTypeForImageFormat(detected.format),
    bytes,
    ...(detected.widthPx !== undefined ? { widthPx: detected.widthPx } : {}),
    ...(detected.heightPx !== undefined ? { heightPx: detected.heightPx } : {}),
  }
}

export function validateArchiveMediaEntries(
  entries: Record<string, Uint8Array>,
  quotas?: ZipQuotas,
): Map<string, ValidatedMediaAsset> {
  const limits = resolveMediaQuotas(quotas)
  const validated = new Map<string, ValidatedMediaAsset>()
  let count = 0
  let totalBytes = 0

  for (const [entryName, entryBytes] of Object.entries(entries)) {
    if (/(?:^|\/)_rels\/[^/]+\.rels$/i.test(entryName)) {
      const relsXml = new TextDecoder('utf-8', { fatal: false }).decode(entryBytes)
      const parser = new SaxesParser({ xmlns: false, position: false })
      let relError: DocxSecurityError | null = null
      parser.on('opentag', (tag) => {
        if (relError) return
        const idx = tag.name.indexOf(':')
        const local = (idx === -1 ? tag.name : tag.name.slice(idx + 1)).toLowerCase()
        if (local !== 'relationship') return
        const targetMode = (tag.attributes.TargetMode ?? '').toLowerCase()
        const relType = tag.attributes.Type ?? ''
        if (targetMode === 'external' && (relType.endsWith('/image') || relType.includes('/relationships/image'))) {
          relError = new DocxSecurityError(
            'external_rel_forbidden',
            `External image relationship "${tag.attributes.Id ?? ''}" in "${entryName}" is forbidden.`,
          )
        }
      })
      try {
        parser.write(relsXml).close()
      } catch {
        // XML structural errors are handled by parseXmlPart
      }
      if (relError) {
        throw relError
      }
      continue
    }

    if (!/^word\/media\/.+$/i.test(entryName)) {
      continue
    }
    count += 1
    if (count > limits.maxMediaFiles) {
      throw new DocxSecurityError(
        'media_quota_exceeded',
        `Archive media file count ${count} exceeds max ${limits.maxMediaFiles}.`,
      )
    }
    totalBytes += entryBytes.byteLength
    if (totalBytes > limits.maxTotalMediaBytes) {
      throw new DocxSecurityError(
        'media_quota_exceeded',
        `Archive total media size (${totalBytes} bytes) exceeds max ${limits.maxTotalMediaBytes} bytes.`,
      )
    }

    const extFormat = imageFormatFromExtension(entryName)
    if (extFormat) {
      validated.set(
        entryName,
        validateMediaAsset(entryBytes, { fileName: entryName, expectedFormat: extFormat, quotas }),
      )
    }
  }

  return validated
}

export function parseAndValidateImageDataUri(src: string, quotas?: ZipQuotas): ValidatedMediaAsset {
  const match = /^data:(image\/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\r\n]+)$/i.exec(src.trim())
  if (!match) {
    throw new DocxSecurityError('media_invalid_format', 'Image data URI must be a base64-encoded image/* URI.')
  }
  const mime = match[1].toLowerCase()
  let expectedFormat: SupportedImageFormat
  switch (mime) {
    case 'image/png':
      expectedFormat = 'png'
      break
    case 'image/jpeg':
    case 'image/jpg':
      expectedFormat = 'jpg'
      break
    case 'image/gif':
      expectedFormat = 'gif'
      break
    case 'image/bmp':
      expectedFormat = 'bmp'
      break
    case 'image/svg+xml':
      expectedFormat = 'svg'
      break
    default:
      throw new DocxSecurityError('media_invalid_format', `Unsupported image MIME type "${mime}".`)
  }
  const bytes = decodeBase64Bytes(match[2])
  return validateMediaAsset(bytes, { expectedFormat, expectedMime: mime, quotas })
}
