import * as yauzl from 'yauzl'
import {
  assertActualEntryDecompressedSize,
  DocxSecurityError,
  resolveZipQuotas,
  validateZipDirectoryEntries,
  type ZipEntryMeta,
  type ZipQuotas,
  type ZipReader,
} from './guard'

interface CollectedEntry {
  entry: yauzl.Entry
  meta: ZipEntryMeta
}

function fromBufferYauzl(buffer: Buffer): Promise<yauzl.ZipFile> {
  return new Promise((resolve, reject) => {
    yauzl.fromBuffer(
      buffer,
      {
        lazyEntries: true,
        autoClose: false,
        validateEntrySizes: true,
        decodeStrings: true,
        strictFileNames: true,
      },
      (err, zipfile) => {
        if (err || !zipfile) {
          reject(
            new DocxSecurityError(
              'zip_invalid_archive',
              `Invalid ZIP archive: ${err?.message ?? 'unknown error'}`,
            ),
          )
          return
        }
        resolve(zipfile)
      },
    )
  })
}

function readLocalHeaderStart(zipfile: yauzl.ZipFile, entry: yauzl.Entry): Promise<number> {
  return new Promise((resolve, reject) => {
    zipfile.readLocalFileHeader(entry, { minimal: true }, (err, header) => {
      if (err || !header) {
        reject(
          new DocxSecurityError(
            'zip_invalid_archive',
            `Invalid local header for "${entry.fileName}": ${err?.message ?? 'missing header'}`,
          ),
        )
        return
      }
      resolve(header.fileDataStart)
    })
  })
}

function collectCentralDirectory(zipfile: yauzl.ZipFile, maxEntries: number): Promise<CollectedEntry[]> {
  return new Promise((resolve, reject) => {
    const collected: CollectedEntry[] = []
    let settled = false

    const fail = (error: unknown) => {
      if (settled) return
      settled = true
      reject(error)
    }

    zipfile.on('error', (err) => {
      const msg = err.message
      const code =
        msg.startsWith('invalid relative path') ||
        msg.startsWith('invalid characters in fileName') ||
        msg.startsWith('absolute path')
          ? 'zip_slip'
          : 'zip_invalid_archive'
      fail(new DocxSecurityError(code, `ZIP error: ${msg}`))
    })

    zipfile.on('entry', (entry: yauzl.Entry) => {
      if (collected.length + 1 > maxEntries) {
        fail(
          new DocxSecurityError(
            'zip_quota_exceeded',
            `ZIP entry count exceeds limit ${maxEntries}.`,
          ),
        )
        return
      }
      void readLocalHeaderStart(zipfile, entry)
        .then((dataStartOffset) => {
          collected.push({
            entry,
            meta: {
              name: entry.fileName,
              compressedSize: entry.compressedSize,
              uncompressedSize: entry.uncompressedSize,
              compressionMethod: entry.compressionMethod,
              externalFileAttributes: entry.externalFileAttributes,
              localHeaderOffset: entry.relativeOffsetOfLocalHeader,
              dataStartOffset,
            },
          })
          zipfile.readEntry()
        })
        .catch(fail)
    })

    zipfile.on('end', () => {
      if (settled) return
      settled = true
      resolve(collected)
    })

    zipfile.readEntry()
  })
}

function readEntryStream(
  zipfile: yauzl.ZipFile,
  entry: yauzl.Entry,
  maxBytes: number,
  maxRatio: number,
): Promise<Uint8Array> {
  return new Promise((resolve, reject) => {
    zipfile.openReadStream(entry, (err, stream) => {
      if (err || !stream) {
        reject(
          new DocxSecurityError(
            'zip_invalid_archive',
            `Unable to open entry "${entry.fileName}": ${err?.message ?? 'stream missing'}`,
          ),
        )
        return
      }

      const chunks: Buffer[] = []
      let total = 0
      let aborted = false

      stream.on('data', (chunk: Buffer) => {
        total += chunk.byteLength
        if (total > maxBytes) {
          aborted = true
          stream.destroy()
          reject(
            new DocxSecurityError(
              'zip_quota_exceeded',
              `Entry "${entry.fileName}" decompressed stream exceeded ${maxBytes} bytes.`,
            ),
          )
          return
        }
        if (total > 0 && (entry.compressedSize <= 0 || total / entry.compressedSize > maxRatio)) {
          aborted = true
          stream.destroy()
          reject(
            new DocxSecurityError(
              'zip_bomb_ratio',
              `Entry "${entry.fileName}" actual compression ratio exceeded ${maxRatio}x.`,
            ),
          )
          return
        }
        chunks.push(chunk)
      })

      stream.on('error', (streamErr: Error) => {
        if (aborted) return
        reject(
          new DocxSecurityError(
            'zip_invalid_archive',
            `Corrupt compressed entry "${entry.fileName}": ${streamErr.message}`,
          ),
        )
      })

      stream.on('end', () => {
        if (aborted) return
        resolve(new Uint8Array(Buffer.concat(chunks, total)))
      })
    })
  })
}

export async function readZipWithYauzl(
  bytes: Uint8Array,
  quotas?: ZipQuotas,
): Promise<Record<string, Uint8Array>> {
  const limits = resolveZipQuotas(quotas)
  const buffer = Buffer.from(bytes.buffer, bytes.byteOffset, bytes.byteLength)
  const zipfile = await fromBufferYauzl(buffer)

  try {
    const collected = await collectCentralDirectory(zipfile, limits.maxEntries)
    validateZipDirectoryEntries(
      collected.map((item) => item.meta),
      limits,
    )

    const out: Record<string, Uint8Array> = {}
    let runningTotal = 0

    for (const { entry, meta } of collected) {
      if (entry.fileName.endsWith('/')) {
        continue
      }
      const remainingTotal = limits.maxTotalBytes - runningTotal
      const maxForThisEntry = Math.min(limits.maxFileBytes, remainingTotal)
      const data = await readEntryStream(zipfile, entry, maxForThisEntry, limits.maxCompressionRatio)
      runningTotal = assertActualEntryDecompressedSize(
        entry.fileName,
        data.byteLength,
        meta.compressedSize,
        runningTotal,
        limits,
      )
      out[entry.fileName] = data
    }

    return out
  } finally {
    try {
      zipfile.close()
    } catch {
      // already closed
    }
  }
}

export const nodeZipReader: ZipReader = {
  readEntries: readZipWithYauzl,
}
