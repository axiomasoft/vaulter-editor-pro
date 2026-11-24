import { computed, getCurrentScope, onScopeDispose, ref, shallowRef, type ComputedRef, type Ref, type ShallowRef } from 'vue'
import type { Editor } from '@tiptap/core'
import type { Node as ProseMirrorNode } from '@tiptap/pm/model'
import { useReviewClient, type ReviewClient } from '../api/reviewClient'
import {
  applySuggestionDecision,
  projectTrackedChanges,
  type TrackedProjection,
  type TrackedSuggestionSelection,
} from '../review/trackedProjection'
import {
  DEFAULT_REVIEW_CAPABILITIES,
  type BulkDecideSuggestionsPayload,
  type DocumentSuggestion,
  type OverlayEventsSource,
  type SaveSuggestionPayload,
  type SuggestionCapabilities,
  type SuggestionKind,
} from '../types/review'

export interface UseTrackedChangesOptions {
  client?: ReviewClient
  editor?: Editor | null
  capabilities?: SuggestionCapabilities
  initialSuggestions?: DocumentSuggestion[]
  initialProjection?: TrackedProjection
  overlay?: OverlayEventsSource | null
  includeStaleGenerations?: boolean
}

export interface UseTrackedChangesReturn {
  capabilities: ShallowRef<SuggestionCapabilities>
  suggestions: ShallowRef<DocumentSuggestion[]>
  projection: Ref<TrackedProjection>
  loading: Ref<boolean>
  error: Ref<unknown>
  openSuggestions: ComputedRef<DocumentSuggestion[]>
  activeOpenSuggestions: ComputedRef<DocumentSuggestion[]>
  staleGenerationSuggestions: ComputedRef<DocumentSuggestion[]>
  conflictedSuggestions: ComputedRef<DocumentSuggestion[]>
  structuralSuggestions: ComputedRef<DocumentSuggestion[]>
  proposalFilter: Ref<string | null>
  supportsKind: (kind: SuggestionKind) => boolean
  supportsNodeTypeChange: (transition?: string) => boolean
  supportsMark: (markName: string) => boolean
  supportsListOperation: (op: string) => boolean
  supportsTableOperation: (op: string) => boolean
  canAccept: (suggestion: DocumentSuggestion) => boolean
  canReject: (suggestion: DocumentSuggestion) => boolean
  loadCapabilities: () => Promise<SuggestionCapabilities>
  list: (proposalId?: string | null, includeStaleGenerations?: boolean) => Promise<DocumentSuggestion[]>
  save: (input: SaveSuggestionPayload, idempotencyKey?: string) => Promise<DocumentSuggestion>
  accept: (
    suggestionId: string,
    generation: number,
    expectedVersion?: number,
    commandId?: string | null,
    idempotencyKey?: string,
  ) => Promise<DocumentSuggestion>
  reject: (
    suggestionId: string,
    generation: number,
    expectedVersion?: number,
    commandId?: string | null,
    idempotencyKey?: string,
  ) => Promise<DocumentSuggestion>
  acceptGroup: (
    groupId: string,
    generation: number,
    expectedVersions?: Record<string, number>,
    commandId?: string | null,
    idempotencyKey?: string,
  ) => Promise<DocumentSuggestion[]>
  rejectGroup: (
    groupId: string,
    generation: number,
    expectedVersions?: Record<string, number>,
    commandId?: string | null,
    idempotencyKey?: string,
  ) => Promise<DocumentSuggestion[]>
  acceptAll: (generation: number, commandId?: string | null, idempotencyKey?: string) => Promise<DocumentSuggestion[]>
  rejectAll: (generation: number, commandId?: string | null, idempotencyKey?: string) => Promise<DocumentSuggestion[]>
  acceptByUser: (generation: number, authorSubject: string, commandId?: string | null, idempotencyKey?: string) => Promise<DocumentSuggestion[]>
  rejectByUser: (generation: number, authorSubject: string, commandId?: string | null, idempotencyKey?: string) => Promise<DocumentSuggestion[]>
  acceptInRange: (generation: number, from: number, to: number, commandId?: string | null, idempotencyKey?: string) => Promise<DocumentSuggestion[]>
  rejectInRange: (generation: number, from: number, to: number, commandId?: string | null, idempotencyKey?: string) => Promise<DocumentSuggestion[]>
  setProjection: (mode: TrackedProjection) => void
  projectDoc: (doc: ProseMirrorNode, selection?: TrackedSuggestionSelection) => ProseMirrorNode
}

const STRUCTURAL_KINDS: ReadonlySet<string> = new Set([
  'mark_change',
  'attr_change',
  'block_split',
  'block_join',
  'list_change',
  'table_change',
])

export function useTrackedChanges(
  nodeId: string,
  options: UseTrackedChangesOptions = {},
): UseTrackedChangesReturn {
  const client = options.client ?? useReviewClient()
  const capabilities = shallowRef<SuggestionCapabilities>(options.capabilities ?? DEFAULT_REVIEW_CAPABILITIES)
  const suggestions = shallowRef<DocumentSuggestion[]>(options.initialSuggestions ?? [])
  const projection = ref<TrackedProjection>(options.initialProjection ?? 'review')
  const loading = ref(false)
  const error = ref<unknown>(null)

  let listedProposalId: string | null | undefined
  let listedIncludeStale = options.includeStaleGenerations ?? false
  const proposalFilter = ref<string | null>(null)

  const openSuggestions = computed(() => suggestions.value.filter(s => s.state === 'open'))
  const staleGenerationSuggestions = computed(() => openSuggestions.value.filter(s => Boolean(s.generation_changed)))
  const activeOpenSuggestions = computed(() => openSuggestions.value.filter(s => !s.generation_changed))
  const conflictedSuggestions = computed(() => activeOpenSuggestions.value.filter(s => s.conflicted))
  const structuralSuggestions = computed(() => suggestions.value.filter(s => STRUCTURAL_KINDS.has(s.kind)))

  if (options.overlay) {
    const unsubscribe = options.overlay.overlayEvents((event) => {
      if (event.kind === 'review') {
        void list(listedProposalId, listedIncludeStale).catch(() => undefined)
      }
    })
    if (getCurrentScope()) {
      onScopeDispose(unsubscribe)
    }
  }

  function supportsKind(kind: SuggestionKind): boolean {
    return capabilities.value.supported_kinds.includes(kind)
  }

  function supportsNodeTypeChange(transition?: string): boolean {
    if (transition !== undefined) {
      return capabilities.value.node_type_change_types.includes(transition)
    }
    return capabilities.value.node_type_change_types.length > 0
  }

  function supportsMark(markName: string): boolean {
    return supportsKind('mark_change') && capabilities.value.allowed_marks.includes(markName)
  }

  function supportsListOperation(op: string): boolean {
    return supportsKind('list_change') && capabilities.value.list_operations.includes(op)
  }

  function supportsTableOperation(op: string): boolean {
    return supportsKind('table_change')
      && capabilities.value.table_mode === 'text_only'
      && capabilities.value.table_operations.includes(op)
  }

  function canAccept(suggestion: DocumentSuggestion): boolean {
    return !loading.value
      && suggestion.state === 'open'
      && !suggestion.conflicted
      && !suggestion.pending_commit
      && !suggestion.generation_changed
  }

  function canReject(suggestion: DocumentSuggestion): boolean {
    return !loading.value && suggestion.state === 'open' && !suggestion.pending_commit
  }

  function requireSuggestionsApi() {
    if (!client.suggestions) {
      throw new Error('[Vaulter Review] ReviewClient does not configure the suggestions API.')
    }
    return client.suggestions
  }

  function resolveVersion(suggestionId: string, explicit?: number): number {
    if (typeof explicit === 'number') return explicit
    const found = suggestions.value.find(s => s.id === suggestionId)
    if (!found) {
      throw new Error(`[Vaulter Review] Suggestion ${suggestionId} not found in loaded list.`)
    }
    return found.version
  }

  async function loadCapabilities(): Promise<SuggestionCapabilities> {
    loading.value = true
    error.value = null
    try {
      const api = requireSuggestionsApi()
      const loaded = await api.capabilities(nodeId)
      capabilities.value = loaded
      return loaded
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function list(proposalId?: string | null, includeStaleGenerations?: boolean): Promise<DocumentSuggestion[]> {
    loading.value = true
    error.value = null
    try {
      const api = requireSuggestionsApi()
      const staleFlag = includeStaleGenerations ?? listedIncludeStale
      const loaded = await api.list(nodeId, {
        ...(proposalId ? { proposal_id: proposalId } : {}),
        ...(staleFlag ? { include_stale_generations: true } : {}),
      })
      suggestions.value = loaded
      listedProposalId = proposalId
      listedIncludeStale = staleFlag
      return loaded
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function save(input: SaveSuggestionPayload, idempotencyKey?: string): Promise<DocumentSuggestion> {
    error.value = null
    try {
      const api = requireSuggestionsApi()
      const saved = await api.save(nodeId, input, idempotencyKey)
      const exists = suggestions.value.some(s => s.id === saved.id)
      suggestions.value = exists
        ? suggestions.value.map(s => (s.id === saved.id ? saved : s))
        : [...suggestions.value, saved]
      return saved
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function accept(
    suggestionId: string,
    generation: number,
    expectedVersion?: number,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion> {
    const existing = suggestions.value.find(s => s.id === suggestionId)
    if (existing?.conflicted) {
      const err = new Error(`[Vaulter Review] Cannot accept conflicted suggestion ${suggestionId}.`)
      error.value = err
      throw err
    }

    error.value = null
    try {
      const api = requireSuggestionsApi()
      const updated = await api.accept(
        nodeId,
        suggestionId,
        {
          generation,
          expected_version: resolveVersion(suggestionId, expectedVersion),
          command_id: commandId ?? null,
        },
        idempotencyKey,
      )
      suggestions.value = suggestions.value.map(s => (s.id === suggestionId ? updated : s))
      if (options.editor && !updated.pending_commit) {
        applySuggestionDecision(options.editor, 'accept', { ids: [suggestionId] })
      }
      return updated
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function reject(
    suggestionId: string,
    generation: number,
    expectedVersion?: number,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion> {
    error.value = null
    try {
      const api = requireSuggestionsApi()
      const updated = await api.reject(
        nodeId,
        suggestionId,
        {
          generation,
          expected_version: resolveVersion(suggestionId, expectedVersion),
          command_id: commandId ?? null,
        },
        idempotencyKey,
      )
      const needsRefresh = !updated.pending_commit && suggestions.value.some(s => s.conflict_with_id === suggestionId)
      suggestions.value = suggestions.value.map(s => s.id === suggestionId ? updated : s)
      if (options.editor && !updated.pending_commit) {
        applySuggestionDecision(options.editor, 'reject', { ids: [suggestionId] })
      }
      if (needsRefresh) await list(listedProposalId)
      return updated
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function acceptGroup(
    groupId: string,
    generation: number,
    expectedVersions?: Record<string, number>,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion[]> {
    const groupMembers = suggestions.value.filter(s => s.group_id === groupId && s.state === 'open')
    if (groupMembers.some(s => s.conflicted)) {
      const err = new Error(`[Vaulter Review] Cannot accept conflicted suggestion group ${groupId}.`)
      error.value = err
      throw err
    }

    const versions: Record<string, number> = expectedVersions
      ?? Object.fromEntries(groupMembers.map(s => [s.id, s.version]))
    error.value = null
    try {
      const api = requireSuggestionsApi()
      const updatedList = await api.acceptGroup(
        nodeId,
        groupId,
        { generation, expected_versions: versions, command_id: commandId ?? null },
        idempotencyKey,
      )
      const byId = new Map(updatedList.map(s => [s.id, s]))
      suggestions.value = suggestions.value.map(s => byId.get(s.id) ?? s)
      if (options.editor && updatedList.every(s => !s.pending_commit)) {
        applySuggestionDecision(options.editor, 'accept', {
          ids: [],
          groups: [groupId],
          suggestions: groupMembers.map(m => ({ suggestionId: m.id, groupId: m.group_id })),
        })
      }
      return updatedList
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function rejectGroup(
    groupId: string,
    generation: number,
    expectedVersions?: Record<string, number>,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion[]> {
    const groupMembers = suggestions.value.filter(s => s.group_id === groupId && s.state === 'open')
    const versions: Record<string, number> = expectedVersions
      ?? Object.fromEntries(groupMembers.map(s => [s.id, s.version]))
    error.value = null
    try {
      const api = requireSuggestionsApi()
      const updatedList = await api.rejectGroup(
        nodeId,
        groupId,
        { generation, expected_versions: versions, command_id: commandId ?? null },
        idempotencyKey,
      )
      const byId = new Map(updatedList.map(s => [s.id, s]))
      const needsRefresh = updatedList.every(s => !s.pending_commit)
        && suggestions.value.some(s => s.conflict_with_id && byId.has(s.conflict_with_id))
      suggestions.value = suggestions.value.map(s => byId.get(s.id) ?? s)
      if (options.editor && updatedList.every(s => !s.pending_commit)) {
        applySuggestionDecision(options.editor, 'reject', {
          ids: [],
          groups: [groupId],
          suggestions: groupMembers.map(m => ({ suggestionId: m.id, groupId: m.group_id })),
        })
      }
      if (needsRefresh) await list(listedProposalId)
      return updatedList
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function runBulk(
    decision: 'accepted' | 'rejected',
    payload: BulkDecideSuggestionsPayload,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion[]> {
    error.value = null
    try {
      const api = requireSuggestionsApi()
      const fn = decision === 'accepted' ? api.bulkAccept : api.bulkReject
      if (!fn) {
        throw new Error(`[Vaulter Review] ReviewClient does not configure suggestions.${decision === 'accepted' ? 'bulkAccept' : 'bulkReject'}.`)
      }
      const updatedList = await fn(nodeId, payload, idempotencyKey)
      const byId = new Map(updatedList.map(s => [s.id, s]))
      suggestions.value = suggestions.value.map(s => byId.get(s.id) ?? s)
      options.overlay?.invalidateOverlay?.('review')
      return updatedList
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  function acceptAll(generation: number, commandId?: string | null, idempotencyKey?: string): Promise<DocumentSuggestion[]> {
    return runBulk('accepted', { generation, scope: 'all', command_id: commandId ?? null }, idempotencyKey)
  }

  function rejectAll(generation: number, commandId?: string | null, idempotencyKey?: string): Promise<DocumentSuggestion[]> {
    return runBulk('rejected', { generation, scope: 'all', command_id: commandId ?? null }, idempotencyKey)
  }

  function acceptByUser(
    generation: number,
    authorSubject: string,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion[]> {
    return runBulk('accepted', { generation, scope: 'by_user', author_subject: authorSubject, command_id: commandId ?? null }, idempotencyKey)
  }

  function rejectByUser(
    generation: number,
    authorSubject: string,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion[]> {
    return runBulk('rejected', { generation, scope: 'by_user', author_subject: authorSubject, command_id: commandId ?? null }, idempotencyKey)
  }

  function acceptInRange(
    generation: number,
    from: number,
    to: number,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion[]> {
    return runBulk('accepted', { generation, scope: 'in_range', from, to, command_id: commandId ?? null }, idempotencyKey)
  }

  function rejectInRange(
    generation: number,
    from: number,
    to: number,
    commandId?: string | null,
    idempotencyKey?: string,
  ): Promise<DocumentSuggestion[]> {
    return runBulk('rejected', { generation, scope: 'in_range', from, to, command_id: commandId ?? null }, idempotencyKey)
  }

  function setProjection(mode: TrackedProjection): void {
    projection.value = mode
  }

  function projectDoc(doc: ProseMirrorNode, selection?: TrackedSuggestionSelection): ProseMirrorNode {
    return projectTrackedChanges(doc, projection.value, selection)
  }

  return {
    capabilities,
    suggestions,
    projection,
    loading,
    error,
    openSuggestions,
    activeOpenSuggestions,
    staleGenerationSuggestions,
    conflictedSuggestions,
    structuralSuggestions,
    proposalFilter,
    supportsKind,
    supportsNodeTypeChange,
    supportsMark,
    supportsListOperation,
    supportsTableOperation,
    canAccept,
    canReject,
    loadCapabilities,
    list,
    save,
    accept,
    reject,
    acceptGroup,
    rejectGroup,
    acceptAll,
    rejectAll,
    acceptByUser,
    rejectByUser,
    acceptInRange,
    rejectInRange,
    setProjection,
    projectDoc,
  }
}
