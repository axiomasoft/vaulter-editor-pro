export const DOCX_MODEL_SCHEMA = 'vaulter.docx.intermediate/v1' as const
export const DOCX_LOSS_REPORT_SCHEMA = 'vaulter.docx.loss-report/v1' as const
export const VAULTER_DOCX_CUSTOM_XML_NS = 'urn:vaulter:docx:review:1' as const

export type DocxProfileId =
  | 'standard'
  | 'discussions'
  | 'legacy-discussions'
  | 'revisions'
  | 'strict-ooxml'

export type DocxProjectionMode = 'original' | 'proposed' | 'review'

export type DiagnosticSeverity = 'info' | 'warning' | 'error'

export type DiagnosticAction =
  | 'preserved'
  | 'degraded'
  | 'dropped'
  | 'projected'
  | 'blocked'

export interface SourceMarker {
  nodeId?: string | null
  documentId?: string | null
  revisionId?: string | null
  generation?: number | null
  position?: number | null
  schemaVersion?: number | null
  contentHash?: string | null
  projection?: DocxProjectionMode
}

export interface LossDiagnostic {
  code: string
  severity: DiagnosticSeverity
  feature: string
  location: string
  action: DiagnosticAction
  message: string
  details?: Record<string, unknown>
}

export interface LossReport {
  schema: typeof DOCX_LOSS_REPORT_SCHEMA
  profile: DocxProfileId
  source_marker: SourceMarker | null
  blocked: boolean
  blocking_reasons: string[]
  diagnostics: LossDiagnostic[]
  summary: {
    info: number
    warning: number
    error: number
  }
}

export type TextAlignment = 'left' | 'center' | 'right' | 'both' | 'justify'

export type UnderlineType =
  | 'single'
  | 'double'
  | 'thick'
  | 'dotted'
  | 'dash'
  | 'wave'
  | 'none'

export type LineRule = 'auto' | 'exact' | 'atLeast'

export type WidthUnit = 'dxa' | 'pct'

export interface OoxmlWidth {
  value: number
  unit: WidthUnit
}

export interface BorderSpec {
  style: string
  sizeEighthPt: number
  colorHex: string | null
}

export interface CellMarginsDxa {
  topDxa?: number | null
  rightDxa?: number | null
  bottomDxa?: number | null
  leftDxa?: number | null
}

export interface TableBordersSpec {
  top?: BorderSpec | null
  bottom?: BorderSpec | null
  left?: BorderSpec | null
  right?: BorderSpec | null
  insideH?: BorderSpec | null
  insideV?: BorderSpec | null
}

export interface CellBordersSpec {
  top?: BorderSpec | null
  bottom?: BorderSpec | null
  left?: BorderSpec | null
  right?: BorderSpec | null
}

export type IntermediateMark =
  | { type: 'bold' }
  | { type: 'italic' }
  | { type: 'underline'; underlineType?: UnderlineType | null }
  | { type: 'strike' }
  | { type: 'code' }
  | { type: 'superscript' }
  | { type: 'subscript' }
  | { type: 'highlight'; colorHex: string }
  | { type: 'link'; href: string; title?: string | null }
  | {
      type: 'textStyle'
      fontFamily?: string | null
      fontSizeHalfPt?: number | null
      colorHex?: string | null
      backgroundColorHex?: string | null
      lineHeight?: number | null
    }

export interface InlineRevisionMeta {
  id: string
  kind: 'insert' | 'delete'
  groupId?: string | null
  author: string
  date: string
  ooxmlId?: number
}

export interface FormattingChangeMeta {
  id: string
  targetType: 'run' | 'paragraph'
  author: string
  date: string
  summary: string
  ooxmlId?: number
  markChanges?: Array<{
    operation: 'added' | 'removed'
    markName: string
    markAttrs?: Record<string, unknown>
  }>
}

export interface IntermediateTextInline {
  type: 'text'
  text: string
  marks: IntermediateMark[]
  commentIds?: string[]
  revision?: InlineRevisionMeta | null
  formattingChange?: FormattingChangeMeta | null
}

export interface IntermediateHardBreakInline {
  type: 'hardBreak'
  commentIds?: string[]
  revision?: InlineRevisionMeta | null
}

export interface IntermediateBookmarkInline {
  type: 'bookmark'
  id: string
  name: string
}

export interface IntermediateImageInline {
  type: 'image'
  src: string
  alt?: string | null
  title?: string | null
  widthPx?: number | null
  heightPx?: number | null
  crop?: {
    top?: number | null
    bottom?: number | null
    left?: number | null
    right?: number | null
  } | null
}

export interface IntermediateUnsupportedInline {
  type: 'unsupportedInline'
  elementName: string
  mandatory: boolean
  textFallback?: string
}

export type IntermediateInline =
  | IntermediateTextInline
  | IntermediateHardBreakInline
  | IntermediateBookmarkInline
  | IntermediateImageInline
  | IntermediateUnsupportedInline

export interface ListBinding {
  kind: 'bullet' | 'ordered'
  level: number
  numId: number
  start?: number
  format?: string
}

export interface ParagraphAttrs {
  styleId?: string | null
  textAlign?: TextAlignment | null
  indentLeftDxa?: number | null
  indentFirstLineDxa?: number | null
  indentRightDxa?: number | null
  spacingBeforeDxa?: number | null
  spacingAfterDxa?: number | null
  lineSpacing?: number | null
  lineRule?: LineRule | null
  contextualSpacing?: boolean | null
  keepNext?: boolean | null
  keepLines?: boolean | null
  list?: ListBinding | null
  blockJoinDeletion?: InlineRevisionMeta | null
  formattingChange?: FormattingChangeMeta | null
}

export interface IntermediateParagraphBlock {
  type: 'paragraph'
  id: string | null
  attrs: ParagraphAttrs
  inlines: IntermediateInline[]
}

export interface IntermediateHeadingBlock {
  type: 'heading'
  id: string | null
  level: 1 | 2 | 3 | 4 | 5 | 6
  attrs: ParagraphAttrs
  inlines: IntermediateInline[]
}

export interface IntermediateTableCell {
  colspan: number
  rowspan: number
  colwidthDxa?: number[] | null
  width?: OoxmlWidth | null
  backgroundHex?: string | null
  verticalAlign?: 'top' | 'center' | 'bottom' | null
  borders?: CellBordersSpec | null
  margins?: CellMarginsDxa | null
  blocks: IntermediateBlock[]
}

export interface IntermediateTableRow {
  isHeader: boolean
  heightDxa?: number | null
  heightRule?: LineRule | null
  cells: IntermediateTableCell[]
}

export interface IntermediateTableBlock {
  type: 'table'
  id: string | null
  width?: OoxmlWidth | null
  indentDxa?: number | null
  gridColsDxa: number[]
  borders?: TableBordersSpec | null
  margins?: CellMarginsDxa | null
  rows: IntermediateTableRow[]
}

export interface IntermediateBlockquoteBlock {
  type: 'blockquote'
  id: string | null
  blocks: IntermediateBlock[]
}

export interface IntermediateCodeBlock {
  type: 'codeBlock'
  id: string | null
  language?: string | null
  text: string
}

export interface IntermediateHorizontalRuleBlock {
  type: 'horizontalRule'
  id: string | null
}

export interface IntermediatePageBreakBlock {
  type: 'pageBreak'
  id: string | null
}

export interface IntermediateUnsupportedBlock {
  type: 'unsupportedBlock'
  id: string | null
  elementName: string
  mandatory: boolean
  reason: string
}

export type IntermediateBlock =
  | IntermediateParagraphBlock
  | IntermediateHeadingBlock
  | IntermediateTableBlock
  | IntermediateBlockquoteBlock
  | IntermediateCodeBlock
  | IntermediateHorizontalRuleBlock
  | IntermediatePageBreakBlock
  | IntermediateUnsupportedBlock

export interface HeaderFooterContent {
  blocks: IntermediateBlock[]
}

export interface HeaderFooterSlots {
  default?: HeaderFooterContent | null
  first?: HeaderFooterContent | null
  even?: HeaderFooterContent | null
}

export interface IntermediateSection {
  id: string
  type?: 'nextPage' | 'continuous' | 'evenPage' | 'oddPage'
  pageSize?: {
    widthDxa: number
    heightDxa: number
    orientation?: 'portrait' | 'landscape'
  }
  margins?: {
    topDxa?: number | null
    rightDxa?: number | null
    bottomDxa?: number | null
    leftDxa?: number | null
    headerDxa?: number | null
    footerDxa?: number | null
    gutterDxa?: number | null
  }
  titlePg?: boolean | null
  headers: HeaderFooterSlots
  footers: HeaderFooterSlots
  blocks: IntermediateBlock[]
}

export interface IntermediateComment {
  id: string
  parentId?: string | null
  author: string
  initials?: string | null
  createdAt: string
  bodyParagraphs: string[]
  ooxml?: {
    wId?: number
    paraId?: string
    durableId?: string
  }
}

export interface IntermediateDiscussion {
  id: string
  anchorState: 'attached' | 'detached' | 'historical'
  quoteText?: string | null
  blockId?: string | null
  sourcePosition?: number | null
  generation?: number | null
  resolved: boolean
  comments: IntermediateComment[]
}

export interface ReplacementGroupMetadata {
  groupId: string
  deletionRevisionId: string
  insertionRevisionId: string
  author?: string | null
  date?: string | null
}

export interface FormattingRevisionRecord {
  id: string
  targetType: 'run' | 'paragraph'
  location: string
  author: string
  date: string
  summary: string
}

export interface NumberingDefinition {
  numId: number
  kind: 'bullet' | 'ordered'
  start: number
  format: string
}

export interface IntermediateDocument {
  schema: typeof DOCX_MODEL_SCHEMA
  sourceMarker: SourceMarker | null
  metadata: {
    title?: string | null
    creator?: string | null
    lastModifiedBy?: string | null
    createdAt?: string | null
    modifiedAt?: string | null
    evenAndOddHeaders: boolean
  }
  numbering: NumberingDefinition[]
  sections: IntermediateSection[]
  discussions: IntermediateDiscussion[]
  replacementGroups: ReplacementGroupMetadata[]
  formattingRevisions: FormattingRevisionRecord[]
}

export interface TipTapMark {
  type: string
  attrs?: Record<string, unknown>
}

export interface TipTapNode {
  type: string
  attrs?: Record<string, unknown>
  text?: string
  marks?: TipTapMark[]
  content?: TipTapNode[]
}

export interface TipTapDoc extends TipTapNode {
  type: 'doc'
  content?: TipTapNode[]
}

export interface AnchoredCommentLike {
  id: string
  parent_id: string | null
  author: {
    type: string
    id: string
  }
  body: string
  anchor: {
    generation: number
    source_position: number
    block_id: string
    selector_kind: 'snapshot_utf16' | 'yjs_relative'
    selector_payload: object
    quote_text: string
    anchor_state: 'attached' | 'detached' | 'historical'
  } | null
  resolved_at: string | null
  created_at: string
}

export interface DocxSectionInput {
  id?: string
  type?: 'nextPage' | 'continuous' | 'evenPage' | 'oddPage'
  pageSize?: {
    widthDxa: number
    heightDxa: number
    orientation?: 'portrait' | 'landscape'
  }
  margins?: IntermediateSection['margins']
  titlePg?: boolean | null
  headers?: {
    default?: TipTapDoc | HeaderFooterContent | string | null
    first?: TipTapDoc | HeaderFooterContent | string | null
    even?: TipTapDoc | HeaderFooterContent | string | null
  }
  footers?: {
    default?: TipTapDoc | HeaderFooterContent | string | null
    first?: TipTapDoc | HeaderFooterContent | string | null
    even?: TipTapDoc | HeaderFooterContent | string | null
  }
  blockCount?: number
}

export interface DocxDiscussionInput {
  id: string
  anchorState?: 'attached' | 'detached' | 'historical'
  quoteText?: string | null
  blockId?: string | null
  sourcePosition?: number | null
  generation?: number | null
  resolved?: boolean
  comments: Array<{
    id: string
    parentId?: string | null
    author: string
    initials?: string | null
    createdAt?: string
    body: string | string[]
    originalWId?: number | string
    originalParaId?: string
    originalDurableId?: string
  }>
}
