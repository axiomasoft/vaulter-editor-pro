// Types
export type * from './types/review'

// API Client
export {
  createReviewClient,
  provideReviewClient,
  ReviewApiError,
  useReviewClient,
  vaulterReviewClientKey,
} from './api/reviewClient'
export type {
  AddAnchoredCommentPayload,
  ReviewClient,
  ReviewClientOptions,
} from './api/reviewClient'

// Yjs & Snapshot Anchors
export {
  buildYjsRelativePayload,
  decodeRelativePositionBase64,
  encodeRelativePositionBase64,
  resolveXmlTextPosition,
  resolveYjsRangeAnchor,
  safeResolveRelativePos,
} from './yjs/safeResolveRelativePos'
export type {
  SafeResolveOptions,
  YjsRangeResolution,
  YjsRelativeResolver,
} from './yjs/safeResolveRelativePos'

export {
  buildSnapshotUtf16Payload,
  evaluateAnchorActuality,
  extractSnapshotSelectionRange,
  resolveSnapshotUtf16Anchor,
} from './anchors/snapshotUtf16'
export type {
  ActualityContext,
  BlockRangeHit,
  SnapshotResolution,
} from './anchors/snapshotUtf16'

// Two-Stage Structural Diff & Extensions
export {
  computeStructuralDiff,
  MarkAwareEncoder,
  textSimhash,
} from './compare/structuralDiff'
export type {
  AttrChangeEntry,
  BlockAlignment,
  DiffSpanKind,
  InlineDiffSpan,
  MarkChangeDetail,
  StructuralDiffResult,
} from './compare/structuralDiff'

export { DiffOverlay } from './extensions/DiffOverlay'
export type { DiffOverlayOptions } from './extensions/DiffOverlay'
export { RANGE_ANCHOR_PLUGIN_KEY, RangeAnchorHighlight } from './extensions/RangeAnchorMark'
export type { ActiveRangeDecoration, RangeAnchorHighlightOptions } from './extensions/RangeAnchorMark'

// Composables
export { isDetachedAnchor, useRangeComments } from './composables/useRangeComments'
export type { CommentThreadItem, UseRangeCommentsOptions, UseRangeCommentsReturn } from './composables/useRangeComments'
export { useRevisionCompare } from './composables/useRevisionCompare'
export type { UseRevisionCompareOptions, UseRevisionCompareReturn } from './composables/useRevisionCompare'
export { DOCX_MIME_TYPE, LocalDocxExportError, useLocalDocxExport } from './composables/useLocalDocxExport'
export type {
  LocalDocxExportErrorCode,
  LocalDocxExportOutput,
  LocalDocxSource,
  UseLocalDocxExportOptions,
  UseLocalDocxExportReturn,
} from './composables/useLocalDocxExport'
export { attachDocxWorkerListener } from './docx/localExportWorker'
export { useDocxExchange } from './composables/useDocxExchange'
export type { UseDocxExchangeOptions, UseDocxExchangeReturn } from './composables/useDocxExchange'

// Components
export { default as VaulterHistoryCompare } from './components/VaulterHistoryCompare.vue'
export { default as VaulterRangeComments } from './components/VaulterRangeComments.vue'
export { default as VaulterLocalDocxExport } from './components/VaulterLocalDocxExport.vue'
export { default as VaulterDocxExchange } from './components/VaulterDocxExchange.vue'
