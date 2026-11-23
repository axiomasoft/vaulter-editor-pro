import { unzipSync, zipSync } from 'fflate'

export function fflateUnzipRaw(bytes: Uint8Array): Record<string, Uint8Array> {
  return unzipSync(bytes)
}

export function fflateZipRaw(entries: Record<string, Uint8Array>): Uint8Array {
  return zipSync(entries, { level: 6 })
}
