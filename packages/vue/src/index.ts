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
export { REVIEW_TRACKED_PLUGIN_KEY, VaulterTrackedChanges } from './extensions/VaulterTrackedChanges'
export type { TrackedChangesOptions, TrackedChangesPluginState } from './extensions/VaulterTrackedChanges'
export {
  applySuggestionDecision,
  isStructuralSuggestion,
  isTextSuggestion,
  projectTrackedChanges,
  structuralSuggestionKindList,
} from './review/trackedProjection'
export type {
  TrackedProjection,
  TrackedStructuralSuggestion,
  TrackedSuggestion,
  TrackedSuggestionSelection,
  TrackedTextSuggestion,
} from './review/trackedProjection'
export {
  getDocSnapshot,
  isTableNodeTextual,
  registerDocSnapshot,
  suggestionsOverlap,
} from './review/structuralReverter'
export type {
  TextSuggestionMetadata,
  TrackedDocSnapshotState,
  TrackedStructuralRecord,
} from './review/structuralReverter'
export { DEFAULT_REVIEW_CAPABILITIES } from './types/review'

// Composables
export { isDetachedAnchor, useRangeComments } from './composables/useRangeComments'
export type { CommentThreadItem, UseRangeCommentsOptions, UseRangeCommentsReturn } from './composables/useRangeComments'
export { useTrackedChanges } from './composables/useTrackedChanges'
export type { UseTrackedChangesOptions, UseTrackedChangesReturn } from './composables/useTrackedChanges'
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
export { default as VaulterTrackedChangesPanel } from './components/VaulterTrackedChangesPanel.vue'
