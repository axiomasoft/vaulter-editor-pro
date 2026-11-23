export type PinReason =
  | 'manual_version'
  | 'review_base'
  | 'comment_history'
  | 'exchange_source'

export type SelectorKind = 'snapshot_utf16' | 'yjs_relative'

export type AnchorState = 'attached' | 'detached' | 'historical'

export type CommentFilterStatus = 'all' | 'active' | 'resolved' | 'detached'

export interface SnapshotUtf16Payload {
  schema: 1
  block_id: string
  from_utf16: number
  to_utf16: number
  prefix?: string
  suffix?: string
}

export interface YjsRelativePayload {
  schema: 1
  block_id: string
  start: {
    rel: string
    assoc: 0
  }
  end: {
    rel: string
    assoc: -1
  }
}

export type SelectorPayload = SnapshotUtf16Payload | YjsRelativePayload | Record<string, unknown>

export interface CommentAnchor {
  id: string
  comment_id: string
  document_id: string
  generation: number
  source_position: number
  schema_version: number
  block_id: string
  selector_kind: SelectorKind
  selector_payload: SelectorPayload
  quote_text: string
  anchor_state: AnchorState
  version: number
  etag: string
  created_at: string
  updated_at: string
}

export interface CommentAnchorInput {
  generation: number
  source_position: number
  schema_version: number
  block_id: string
  selector_kind: SelectorKind
  selector_payload: SelectorPayload
  quote_text: string
  pin_revision_id?: string | null
}

export interface AnchoredComment {
  id: string
  node_id: string
  parent_id: string | null
  author: {
    type: string
    id: string
  }
  body: string
  legacy_anchor: Record<string, unknown> | null
  anchor: CommentAnchor | null
  resolved_at: string | null
  resolved_by: string | null
  version: number
  etag: string
  created_at: string
}

export interface RevisionPin {
  revision_id: string
  document_id: string
  pin_reason: PinReason
  source_generation: number
  source_position: number
  pinned_by_subject: string
  pinned_at: string
  expires_at: string | null
}

export interface RevisionMeta {
  kind: 'revision' | 'working'
  id: string | null
  document_id: string
  node_id: string
  type: string
  number?: number
  schema_version: number
  state_version: number
  collab_epoch: number
  label?: string | null
  is_autosave?: boolean
  keep_forever?: boolean
  content_hash: string | null
  created_by?: string | null
  created_at?: string | null
  updated_by?: string | null
  updated_at?: string | null
}

export interface RevisionPreview {
  revision: RevisionMeta
  pin: RevisionPin | null
  content: Record<string, unknown>
}

export interface RevisionCompare {
  from: {
    meta: RevisionMeta
    pin: RevisionPin | null
    content: Record<string, unknown>
  }
  to: {
    meta: RevisionMeta
    pin: RevisionPin | null
    content: Record<string, unknown>
  }
}

export interface PageMeta {
  next_cursor: string | null
  per_page: number
}

export interface CursorPage<T> {
  data: T[]
  meta: PageMeta
}

export type ActualityStatus =
  | 'current'
  | 'shifted'
  | 'stale_generation'
  | 'detached'
  | 'historical'
  | 'plain'

export interface AnchorActuality {
  status: ActualityStatus
  from: number | null
  to: number | null
  resolvedQuote: string | null
  needsReanchor: boolean
}

export type ExchangeJobKind = 'import' | 'export'

export type ExchangeJobState =
  | 'queued'
  | 'running'
  | 'preview_ready'
  | 'completed'
  | 'applied'
  | 'failed'

export interface ExchangeJob {
  id: string
  document_id: string
  request_key: string
  kind: ExchangeJobKind
  format: 'docx'
  profile_id: string
  state: ExchangeJobState
  source_blob_id: string | null
  source_revision_id: string | null
  source_generation: number | null
  source_position: number | null
  output_blob_id: string | null
  apply_command_id: string | null
  apply_receipt_id: string | null
  diagnostics_report: Record<string, unknown> | null
  error_code: string | null
  preview?: Record<string, unknown> | null
  semantic?: Record<string, unknown> | null
  created_at: string
  updated_at: string
  finished_at: string | null
}

export interface RegisterExchangeJobPayload {
  request_key: string
  kind: ExchangeJobKind
  format?: 'docx'
  profile_id?: string
  source_blob_id?: string
  source_revision_id?: string
  source_generation?: number
  source_position?: number
  source?: {
    blob_id?: string
    node_id?: string
    revision_id?: string
    generation?: number
    position?: number
  }
  docx_b64?: string
  request_fingerprint?: string
  options?: Record<string, unknown>
}

export interface ApplyExchangeImportPayload {
  apply_command_id: string
  expected_generation?: number | null
  expected_position?: number | null
}

export interface ApplyExchangeReceipt {
  job_id: string
  document_id: string
  state: ExchangeJobState
  apply_command_id: string
  apply_receipt_id: string | null
  write_mode: string
  generation: number
  position: number
  replayed: boolean
  pending_commit: boolean
}

export type TextSuggestionKind = 'insert' | 'delete' | 'replace'

export type StructuralSuggestionKind =
  | 'mark_change'
  | 'attr_change'
  | 'block_split'
  | 'block_join'
  | 'list_change'
  | 'table_change'

export type SuggestionKind = TextSuggestionKind | StructuralSuggestionKind

export type SuggestionState = 'open' | 'accepted' | 'rejected' | 'superseded'

export interface SuggestionCapabilities {
  supported_kinds: SuggestionKind[]
  text_kinds: TextSuggestionKind[]
  structural_kinds: StructuralSuggestionKind[]
  node_type_change_types: string[]
  table_mode: 'text_only'
  allowed_marks: string[]
  allowed_attributes: Record<string, string[]>
  list_operations: string[]
  table_operations: string[]
  overlap_policy: 'keep_open_with_conflict'
  document_type: string | null
  write_mode: string | null
  journal_supported: boolean
}

export const DEFAULT_REVIEW_CAPABILITIES: SuggestionCapabilities = {
  supported_kinds: [
    'insert',
    'delete',
    'replace',
    'mark_change',
    'attr_change',
    'block_split',
    'block_join',
    'list_change',
    'table_change',
  ],
  text_kinds: ['insert', 'delete', 'replace'],
  structural_kinds: [
    'mark_change',
    'attr_change',
    'block_split',
    'block_join',
    'list_change',
    'table_change',
  ],
  node_type_change_types: [],
  table_mode: 'text_only',
  allowed_marks: ['bold', 'italic', 'strike', 'code', 'link', 'underline'],
  allowed_attributes: {
    heading: ['level'],
    orderedList: ['start', 'type'],
    tableCell: ['colspan', 'rowspan', 'colwidth', 'align'],
    tableHeader: ['colspan', 'rowspan', 'colwidth', 'align'],
    link: ['href', 'target', 'rel', 'class', 'title'],
  },
  list_operations: ['wrap_bullet', 'wrap_ordered', 'lift', 'toggle_type', 'sink_item', 'split_item'],
  table_operations: [
    'insert_table',
    'insert_row',
    'delete_row',
    'insert_column',
    'delete_column',
    'toggle_header_row',
    'toggle_header_cell',
    'set_cell_attr',
  ],
  overlap_policy: 'keep_open_with_conflict',
  document_type: 'vaulter/rich-text',
  write_mode: null,
  journal_supported: false,
}

export interface DocumentSuggestion {
  id: string
  group_id: string
  proposal_id: string | null
  document_id: string
  generation: number
  base_position: number
  schema_version: number
  author_subject: string
  kind: SuggestionKind
  target_block_id: string
  target_selector: Record<string, unknown>
  payload: Record<string, unknown>
  from: number
  to: number
  parent_id: string | null
  conflicted: boolean
  conflict_reason: string | null
  conflict_with_id: string | null
  before: string
  after: string
  state: SuggestionState
  version: number
  etag: string
  receipt_id: string | null
  decided_by_subject: string | null
  decided_at: string | null
  pending_commit: boolean
  journal_command_id: string | null
  journal_state: string | null
  committed_position: number | null
}

export interface SaveSuggestionPayload {
  id: string
  group_id: string
  generation: number
  base_position: number
  schema_version: number
  kind: SuggestionKind | string
  target_block_id: string
  proposal_id?: string | null
  parent_id?: string | null
  expected_version?: number
  from?: number
  to?: number
  before?: string
  after?: string
  target_selector?: Record<string, unknown>
  payload?: Record<string, unknown>
}

export interface DecideSuggestionPayload {
  generation: number
  expected_version: number
  command_id?: string | null
}

export interface DecideGroupSuggestionsPayload {
  generation: number
  expected_versions: Record<string, number>
  command_id?: string | null
}
