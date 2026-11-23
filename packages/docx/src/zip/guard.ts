export const DEFAULT_ZIP_QUOTAS = {
  maxEntries: 2000,
  maxFileBytes: 50 * 1024 * 1024, // 50 MiB
  maxTotalBytes: 150 * 1024 * 1024, // 150 MiB
  maxCompressionRatio: 100,
} as const

export interface ZipQuotas {
  maxEntries?: number
  maxFileBytes?: number
  maxTotalBytes?: number
  maxCompressionRatio?: number
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

export function resolveZipQuotas(quotas?: ZipQuotas): Required<ZipQuotas> {
  return {
    maxEntries: quotas?.maxEntries ?? DEFAULT_ZIP_QUOTAS.maxEntries,
    maxFileBytes: quotas?.maxFileBytes ?? DEFAULT_ZIP_QUOTAS.maxFileBytes,
    maxTotalBytes: quotas?.maxTotalBytes ?? DEFAULT_ZIP_QUOTAS.maxTotalBytes,
    maxCompressionRatio: quotas?.maxCompressionRatio ?? DEFAULT_ZIP_QUOTAS.maxCompressionRatio,
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
