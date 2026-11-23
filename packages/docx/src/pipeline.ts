import type {
  DocxProfileId,
  DocxProjectionMode,
  DocxSectionInput,
  IntermediateDocument,
  LossReport,
  SourceMarker,
  TipTapDoc,
} from './model/types'
import { DOC_MODEL_CHECK } from './pipelineValidation'
import { DocxProfileBlockedError, LossReportBuilder } from './model/lossReport'
import type { ProfileFeatureOptions } from './model/profiles'
import { exportIntermediateToDocx } from './ooxml/exporter'
import { importOoxmlToIntermediate } from './ooxml/importer'
import { toIntermediate, type ToIntermediateOptions } from './tiptap/adapter'
import { extractSemanticSummary, type SemanticReport } from './compare'
import { browserZipReader } from './zip/browserZip'
import type { ZipQuotas, ZipReader } from './zip/guard'

let defaultZipReader: ZipReader = browserZipReader

export function setDefaultZipReader(reader: ZipReader): void {
  defaultZipReader = reader
}

export function getDefaultZipReader(): ZipReader {
  return defaultZipReader
}

export type DocxExportInput =
  | IntermediateDocument
  | {
      document: TipTapDoc
      sections?: DocxSectionInput[]
      discussions?: ToIntermediateOptions['discussions']
      replacementGroups?: ToIntermediateOptions['replacementGroups']
      sourceMarker?: SourceMarker | null
      title?: string | null
      creator?: string | null
      evenAndOddHeaders?: boolean
    }
  | TipTapDoc

export interface ExportDocxOptions extends ProfileFeatureOptions {
  profile?: DocxProfileId
  projection?: DocxProjectionMode
  sourceMarker?: SourceMarker | null
  sections?: DocxSectionInput[]
  discussions?: ToIntermediateOptions['discussions']
  throwOnBlocked?: boolean
}

export interface ExportDocxResult {
  blocked: boolean
  binary: Uint8Array | null
  model: IntermediateDocument
  report: LossReport
  semantic: SemanticReport
}

export interface ImportDocxOptions extends ProfileFeatureOptions {
  profile?: DocxProfileId
  quotas?: ZipQuotas
  sourceMarker?: SourceMarker | null
  zipReader?: ZipReader
  throwOnBlocked?: boolean
}

export interface ImportDocxResult {
  blocked: boolean
  model: IntermediateDocument
  report: LossReport
  semantic: SemanticReport
}

function isIntermediateDoc(input: DocxExportInput): input is IntermediateDocument {
  return typeof input === 'object' && input !== null && 'schema' in input && input.schema === DOC_MODEL_CHECK
}

function isWrappedDocInput(
  input: DocxExportInput,
): input is Exclude<DocxExportInput, IntermediateDocument | TipTapDoc> {
  return typeof input === 'object' && input !== null && 'document' in input
}

function buildIntermediateForExport(
  input: DocxExportInput,
  options: ExportDocxOptions,
  diagnostics: LossReportBuilder,
): IntermediateDocument {
  if (isIntermediateDoc(input)) {
    return input
  }
  const doc = isWrappedDocInput(input) ? input.document : input
  const wrapped = isWrappedDocInput(input) ? input : undefined

  const res = toIntermediate(doc, {
    profile: options.profile,
    projection: options.projection,
    sourceMarker: options.sourceMarker ?? wrapped?.sourceMarker ?? null,
    sections: options.sections ?? wrapped?.sections,
    discussions: options.discussions ?? wrapped?.discussions,
    replacementGroups: wrapped?.replacementGroups,
    title: wrapped?.title,
    creator: wrapped?.creator,
    evenAndOddHeaders: wrapped?.evenAndOddHeaders,
    enableDiscussions: options.enableDiscussions,
    enableRevisions: options.enableRevisions,
    requireFormattingRevisions: options.requireFormattingRevisions,
  })
  diagnostics.merge(res.diagnostics)
  return res.model
}

/**
 * Dry-run preview returning the versioned IntermediateDocument, LossReport, and SemanticReport
 * with zero network activity and zero ZIP serialization overhead.
 */
export function previewDocxExport(
  input: DocxExportInput,
  options: ExportDocxOptions = {},
): Omit<ExportDocxResult, 'binary'> {
  const profile = options.profile ?? 'discussions'
  const diagnostics = new LossReportBuilder()
  const model = buildIntermediateForExport(input, options, diagnostics)
  const report = diagnostics.build(profile, model.sourceMarker)
  const semantic = extractSemanticSummary(model, report)

  return {
    blocked: report.blocked,
    model,
    report,
    semantic,
  }
}

/**
 * Isomorphic DOCX exporter (Node + Browser/Web Worker, 0 network calls).
 */
export async function exportDocx(
  input: DocxExportInput,
  options: ExportDocxOptions = {},
): Promise<ExportDocxResult> {
  const profile = options.profile ?? 'discussions'
  const diagnostics = new LossReportBuilder()
  const model = buildIntermediateForExport(input, options, diagnostics)

  const preCheckReport = diagnostics.build(profile, model.sourceMarker)
  let binary: Uint8Array | null = null

  if (!preCheckReport.blocked) {
    binary = await exportIntermediateToDocx(model, { ...options, profile }, diagnostics)
  }

  const report = diagnostics.build(profile, model.sourceMarker)
  if (report.blocked) {
    binary = null
    if (options.throwOnBlocked) {
      throw new DocxProfileBlockedError(report)
    }
  }

  const semantic = extractSemanticSummary(model, report)

  return {
    blocked: report.blocked,
    binary,
    model,
    report,
    semantic,
  }
}

/**
 * Isomorphic DOCX importer (Node via yauzl@3.4.0, Browser via fflate@0.8.3 + central directory guard).
 */
export async function importDocx(
  bytes: Uint8Array | ArrayBuffer,
  options: ImportDocxOptions = {},
): Promise<ImportDocxResult> {
  const profile = options.profile ?? 'discussions'
  const reader = options.zipReader ?? defaultZipReader
  const data = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes)
  const diagnostics = new LossReportBuilder()

  const model = await importOoxmlToIntermediate(data, reader, options, diagnostics)
  const report = diagnostics.build(profile, model.sourceMarker)

  if (report.blocked && options.throwOnBlocked) {
    throw new DocxProfileBlockedError(report)
  }

  const semantic = extractSemanticSummary(model, report)

  return {
    blocked: report.blocked,
    model,
    report,
    semantic,
  }
}

export async function roundtripDocx(
  bytesOrInput: Uint8Array | ArrayBuffer | DocxExportInput,
  options: ExportDocxOptions & ImportDocxOptions = {},
): Promise<{
  firstImport?: ImportDocxResult
  exported: ExportDocxResult
  secondImport: ImportDocxResult | null
}> {
  const isBytes = bytesOrInput instanceof Uint8Array || bytesOrInput instanceof ArrayBuffer
  const firstImport = isBytes ? await importDocx(bytesOrInput, options) : undefined
  const sourceModelOrInput: DocxExportInput = firstImport
    ? firstImport.model
    : (bytesOrInput as DocxExportInput)
  const exported = await exportDocx(sourceModelOrInput, options)

  const secondImport =
    exported.binary !== null ? await importDocx(exported.binary, options) : null

  return {
    ...(firstImport ? { firstImport } : {}),
    exported,
    secondImport,
  }
}
