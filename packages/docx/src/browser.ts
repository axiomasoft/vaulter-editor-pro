import { browserZipReader } from './zip/browserZip'
import {
  exportDocx,
  previewDocxExport,
  setDefaultZipReader,
  type DocxExportInput,
  type ExportDocxOptions,
  type ExportDocxResult,
} from './pipeline'

setDefaultZipReader(browserZipReader)

export * from './index'

export type DocxWorkerRequest =
  | {
      id: string
      kind: 'preview'
      input: DocxExportInput
      options?: ExportDocxOptions
    }
  | {
      id: string
      kind: 'export'
      input: DocxExportInput
      options?: ExportDocxOptions
    }

export type DocxWorkerResponse =
  | {
      id: string
      ok: true
      result: Omit<ExportDocxResult, 'binary'> | ExportDocxResult
    }
  | {
      id: string
      ok: false
      error: {
        name: string
        message: string
        report?: unknown
      }
    }

export async function handleDocxWorkerRequest(
  req: DocxWorkerRequest,
): Promise<DocxWorkerResponse> {
  try {
    if (req.kind === 'preview') {
      const result = previewDocxExport(req.input, req.options)
      return { id: req.id, ok: true, result }
    }
    const result = await exportDocx(req.input, req.options)
    return { id: req.id, ok: true, result }
  } catch (error) {
    const err = error instanceof Error ? error : new Error(String(error))
    return {
      id: req.id,
      ok: false,
      error: {
        name: err.name,
        message: err.message,
        ...(typeof error === 'object' && error !== null && 'report' in error
          ? { report: (error as { report?: unknown }).report }
          : {}),
      },
    }
  }
}
