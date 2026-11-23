import { unzipSync, zipSync } from 'fflate'
import {
  assertActualEntryDecompressedSize,
  DocxSecurityError,
  parseZipCentralDirectory,
  resolveZipQuotas,
  validateZipDirectoryEntries,
  type ZipQuotas,
  type ZipReader,
} from './guard'

export async function readZipWithFflate(
  bytes: Uint8Array,
  quotas?: ZipQuotas,
): Promise<Record<string, Uint8Array>> {
  const limits = resolveZipQuotas(quotas)
  const directoryEntries = parseZipCentralDirectory(bytes)
  validateZipDirectoryEntries(directoryEntries, limits)

  const metaByName = new Map(directoryEntries.map((entry) => [entry.name, entry]))
  const expectedNames = new Set(directoryEntries.map((entry) => entry.name))

  let runningTotal = 0
  let decoded: Record<string, Uint8Array>
  try {
    decoded = unzipSync(bytes, {
      filter(file) {
        const meta = metaByName.get(file.name)
        if (!meta) {
          return false
        }
        if (file.originalSize !== undefined && file.originalSize > limits.maxFileBytes) {
          throw new DocxSecurityError(
            'zip_quota_exceeded',
            `ZIP entry "${file.name}" exceeds max file size ${limits.maxFileBytes}.`,
          )
        }
        return !file.name.endsWith('/')
      },
    })
  } catch (error) {
    if (error instanceof DocxSecurityError) {
      throw error
    }
    const message = error instanceof Error ? error.message : String(error)
    throw new DocxSecurityError('zip_invalid_archive', `Failed to decompress ZIP archive: ${message}`)
  }

  const out: Record<string, Uint8Array> = {}
  for (const [name, content] of Object.entries(decoded)) {
    const meta = metaByName.get(name)
    if (!meta || !expectedNames.has(name)) {
      continue
    }
    runningTotal = assertActualEntryDecompressedSize(
      name,
      content.byteLength,
      meta.compressedSize,
      runningTotal,
      limits,
    )
    out[name] = content
  }
  return out
}

export function writeZipWithFflate(entries: Record<string, Uint8Array>): Uint8Array {
  return zipSync(entries, { level: 6 })
}

export const browserZipReader: ZipReader = {
  readEntries: readZipWithFflate,
}
