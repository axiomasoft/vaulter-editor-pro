<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Editor } from '@tiptap/core'
import { useTrackedChanges, type UseTrackedChangesOptions } from '../composables/useTrackedChanges'
import type { TrackedProjection } from '../review/trackedProjection'
import type { DocumentSuggestion, SuggestionCapabilities } from '../types/review'

const props = withDefaults(
  defineProps<{
    nodeId: string
    editor?: Editor | null
    generation?: number
    capabilities?: SuggestionCapabilities
    initialSuggestions?: DocumentSuggestion[]
    clientOptions?: UseTrackedChangesOptions
    autoLoad?: boolean
    selectionRange?: { from: number; to: number } | null
    initialProposalId?: string | null
    includeStaleGenerations?: boolean
    resolveAuthor?: (authorSubject: string) => string
  }>(),
  {
    editor: null,
    generation: 1,
    capabilities: undefined,
    initialSuggestions: undefined,
    clientOptions: undefined,
    autoLoad: true,
    selectionRange: null,
    initialProposalId: null,
    includeStaleGenerations: false,
    resolveAuthor: undefined,
  },
)

const emit = defineEmits<{
  accepted: [suggestion: DocumentSuggestion]
  rejected: [suggestion: DocumentSuggestion]
  groupAccepted: [suggestions: DocumentSuggestion[]]
  groupRejected: [suggestions: DocumentSuggestion[]]
  bulkDecided: [suggestions: DocumentSuggestion[]]
  projectionChange: [mode: TrackedProjection]
}>()

const {
  capabilities: activeCapabilities,
  suggestions,
  projection,
  loading,
  error,
  openSuggestions,
  historyCursor,
  loadMoreHistory,
  staleGenerationSuggestions,
  conflictedSuggestions,
  proposalFilter,
  supportsKind,
  supportsNodeTypeChange,
  supportsTableOperation,
  canAccept,
  canReject,
  loadCapabilities,
  list,
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
} = useTrackedChanges(props.nodeId, {
  pageHistory: true,
  ...props.clientOptions,
  editor: props.editor,
  capabilities: props.capabilities ?? props.clientOptions?.capabilities,
  initialSuggestions: props.initialSuggestions ?? props.clientOptions?.initialSuggestions,
  includeStaleGenerations: props.includeStaleGenerations || props.clientOptions?.includeStaleGenerations,
})

if (props.initialProposalId) {
  proposalFilter.value = props.initialProposalId
}

const selectedBulkUser = ref<string>('')

const availableProposals = computed(() => {
  const ids = new Set<string>()
  for (const s of suggestions.value) {
    if (s.proposal_id) ids.add(s.proposal_id)
  }
  return Array.from(ids)
})

const availableAuthors = computed(() => {
  const authors = new Set<string>()
  for (const s of suggestions.value) {
    if (s.state === 'open' && !s.generation_changed) authors.add(s.author_subject)
  }
  return Array.from(authors)
})

const visibleSuggestions = computed(() => {
  const base = suggestions.value.filter(s => !s.generation_changed)
  if (!proposalFilter.value) return base
  return base.filter(s => s.proposal_id === proposalFilter.value)
})

function authorLabel(authorSubject: string): string {
  return props.resolveAuthor?.(authorSubject) ?? authorSubject
}

const projections: Array<{ mode: TrackedProjection; label: string }> = [
  { mode: 'original', label: 'Original' },
  { mode: 'review', label: 'Review' },
  { mode: 'proposed', label: 'Proposed' },
]

const openGroups = computed(() => {
  const byGroup = new Map<string, DocumentSuggestion[]>()
  for (const s of openSuggestions.value) {
    const arr = byGroup.get(s.group_id) ?? []
    arr.push(s)
    byGroup.set(s.group_id, arr)
  }
  return Array.from(byGroup.entries()).map(([groupId, items]) => ({
    groupId,
    items,
    hasConflict: items.some(i => i.conflicted),
    hasPendingCommit: items.some(i => i.pending_commit),
  }))
})

function switchProjection(mode: TrackedProjection): void {
  setProjection(mode)
  emit('projectionChange', mode)
}

async function handleAccept(item: DocumentSuggestion): Promise<void> {
  if (!canAccept(item)) return
  try {
    const updated = await accept(item.id, item.generation ?? props.generation, item.version)
    emit('accepted', updated)
  } catch {
    // Error ref is populated by useTrackedChanges for alert rendering.
  }
}

async function handleReject(item: DocumentSuggestion): Promise<void> {
  if (!canReject(item)) return
  try {
    const updated = await reject(item.id, item.generation ?? props.generation, item.version)
    emit('rejected', updated)
  } catch {
    // Error ref is populated by useTrackedChanges for alert rendering.
  }
}

async function handleAcceptGroup(groupId: string, items: DocumentSuggestion[]): Promise<void> {
  if (items.some(i => !canAccept(i))) return
  const gen = items[0]?.generation ?? props.generation
  const versions = Object.fromEntries(items.map(i => [i.id, i.version]))
  try {
    const updated = await acceptGroup(groupId, gen, versions)
    emit('groupAccepted', updated)
  } catch {
    // Error ref populated
  }
}

async function handleRejectGroup(groupId: string, items: DocumentSuggestion[]): Promise<void> {
  if (items.some(i => !canReject(i))) return
  const gen = items[0]?.generation ?? props.generation
  const versions = Object.fromEntries(items.map(i => [i.id, i.version]))
  try {
    const updated = await rejectGroup(groupId, gen, versions)
    emit('groupRejected', updated)
  } catch {
    // Error ref populated
  }
}

async function handleBulk(action: () => Promise<DocumentSuggestion[]>): Promise<void> {
  try {
    const updated = await action()
    emit('bulkDecided', updated)
  } catch {
    // Error ref populated
  }
}

onMounted(() => {
  if (props.autoLoad) {
    if (!props.capabilities) {
      void loadCapabilities().catch(() => undefined)
    }
    if (!props.initialSuggestions) {
      void list(props.initialProposalId, props.includeStaleGenerations).catch(() => undefined)
    }
  }
})

defineExpose({
  capabilities: activeCapabilities,
  suggestions,
  visibleSuggestions,
  staleGenerationSuggestions,
  proposalFilter,
  projection,
  loading,
  error,
  openSuggestions,
  conflictedSuggestions,
  supportsKind,
  supportsNodeTypeChange,
  supportsTableOperation,
  loadCapabilities,
  list,
  accept: handleAccept,
  reject: handleReject,
  acceptGroup: handleAcceptGroup,
  rejectGroup: handleRejectGroup,
  acceptAll: () => handleBulk(() => acceptAll(props.generation)),
  rejectAll: () => handleBulk(() => rejectAll(props.generation)),
  setProjection: switchProjection,
})
</script>

<template>
  <aside class="vaulter-tracked-changes" aria-label="Tracked suggestions">
    <div class="vaulter-tracked-changes__projections" role="tablist">
      <button
        v-for="p in projections"
        :key="p.mode"
        type="button"
        role="tab"
        :aria-selected="projection === p.mode"
        :data-projection="p.mode"
        class="vaulter-tracked-changes__projection-btn"
        @click="switchProjection(p.mode)"
      >
        {{ p.label }}
      </button>
    </div>

    <div class="vaulter-tracked-changes__capabilities">
      <span
        v-for="kind in activeCapabilities.structural_kinds"
        :key="kind"
        :data-capability-kind="kind"
        class="vaulter-tracked-changes__capability-chip"
      >
        {{ kind }}
      </span>
      <span
        v-if="supportsKind('table_change')"
        data-capability-table-mode="text_only"
        class="vaulter-tracked-changes__capability-chip"
      >
        table:{{ activeCapabilities.table_mode }}
      </span>
      <div
        v-if="supportsNodeTypeChange()"
        data-capability-node-type-change="enabled"
        class="vaulter-tracked-changes__node-type-controls"
      >
        Node type changes enabled
      </div>
    </div>

    <div v-if="loading" class="vaulter-tracked-changes__loading">Loading suggestions…</div>

    <div class="vaulter-tracked-changes__toolbar">
      <label class="vaulter-tracked-changes__proposal-filter">
        Proposal:
        <select v-model="proposalFilter" data-testid="proposal-filter">
          <option :value="null">All proposals</option>
          <option v-for="pid in availableProposals" :key="pid" :value="pid">{{ pid }}</option>
        </select>
      </label>

      <div class="vaulter-tracked-changes__bulk-actions">
        <button type="button" data-action="accept-all" @click="handleBulk(() => acceptAll(generation))">
          Accept all
        </button>
        <button type="button" data-action="reject-all" @click="handleBulk(() => rejectAll(generation))">
          Reject all
        </button>
        <template v-if="availableAuthors.length > 0">
          <select v-model="selectedBulkUser" data-testid="bulk-user-select">
            <option value="">Select user…</option>
            <option v-for="u in availableAuthors" :key="u" :value="u">{{ authorLabel(u) }}</option>
          </select>
          <button
            type="button"
            data-action="accept-by-user"
            :disabled="!selectedBulkUser"
            @click="selectedBulkUser && handleBulk(() => acceptByUser(generation, selectedBulkUser))"
          >
            Accept by user
          </button>
          <button
            type="button"
            data-action="reject-by-user"
            :disabled="!selectedBulkUser"
            @click="selectedBulkUser && handleBulk(() => rejectByUser(generation, selectedBulkUser))"
          >
            Reject by user
          </button>
        </template>
        <template v-if="selectionRange">
          <button
            type="button"
            data-action="accept-in-range"
            @click="handleBulk(() => acceptInRange(generation, selectionRange!.from, selectionRange!.to))"
          >
            Accept in range
          </button>
          <button
            type="button"
            data-action="reject-in-range"
            @click="handleBulk(() => rejectInRange(generation, selectionRange!.from, selectionRange!.to))"
          >
            Reject in range
          </button>
        </template>
      </div>
    </div>

    <div v-if="error" class="vaulter-tracked-changes__error" role="alert">
      {{ error instanceof Error ? error.message : String(error) }}
    </div>

    <div v-if="openGroups.length > 0" class="vaulter-tracked-changes__groups">
      <div
        v-for="group in openGroups"
        :key="group.groupId"
        :data-group-id="group.groupId"
        class="vaulter-tracked-changes__group-bar"
      >
        <span class="vaulter-tracked-changes__group-label">
          Group {{ group.groupId }} ({{ group.items.length }})
        </span>
        <button
          type="button"
          class="vaulter-tracked-changes__group-accept"
          :disabled="group.hasConflict || group.hasPendingCommit"
          @click="handleAcceptGroup(group.groupId, group.items)"
        >
          Accept group
        </button>
        <button
          type="button"
          class="vaulter-tracked-changes__group-reject"
          :disabled="group.hasPendingCommit"
          @click="handleRejectGroup(group.groupId, group.items)"
        >
          Reject group
        </button>
      </div>
    </div>

    <ul class="vaulter-tracked-changes__list">
      <li
        v-for="item in visibleSuggestions"
        :key="item.id"
        class="vaulter-tracked-changes__item"
        :data-suggestion-id="item.id"
        :data-kind="item.kind"
        :data-state="item.state"
        :data-author="item.author_subject"
        :data-conflicted="item.conflicted ? 'true' : 'false'"
      >
        <div class="vaulter-tracked-changes__meta">
          <span class="vaulter-tracked-changes__kind">{{ item.kind }}</span>
          <span class="vaulter-tracked-changes__author">
            <slot
              name="author"
              :author-subject="item.author_subject"
              :label="authorLabel(item.author_subject)"
            >
              {{ authorLabel(item.author_subject) }}
            </slot>
          </span>
          <span class="vaulter-tracked-changes__state">{{ item.state }}</span>
          <span v-if="item.proposal_id" class="vaulter-tracked-changes__proposal-badge" :data-proposal-id="item.proposal_id">
            proposal:{{ item.proposal_id }}
          </span>
          <span v-if="item.pending_commit" class="vaulter-tracked-changes__pending" data-pending-commit="true">
            pending commit
          </span>
        </div>

        <div v-if="item.conflicted" class="vaulter-tracked-changes__conflict" role="status">
          Conflict with {{ item.conflict_with_id }} ({{ item.conflict_reason ?? 'overlap' }})
        </div>

        <div class="vaulter-tracked-changes__actions">
          <button
            type="button"
            class="vaulter-tracked-changes__accept"
            :disabled="!canAccept(item)"
            @click="handleAccept(item)"
          >
            Accept
          </button>
          <button
            type="button"
            class="vaulter-tracked-changes__reject"
            :disabled="!canReject(item)"
            @click="handleReject(item)"
          >
            Reject
          </button>
        </div>
      </li>
    </ul>

    <button v-if="historyCursor" type="button" data-action="more-history" :disabled="loading" @click="loadMoreHistory().catch(() => undefined)">
      Load more history
    </button>

    <section
      v-if="staleGenerationSuggestions.length > 0"
      class="vaulter-tracked-changes__stale"
      data-testid="stale-generation-suggestions"
    >
      <h4>Requires re-approval (generation_changed)</h4>
      <ul>
        <li
          v-for="item in staleGenerationSuggestions"
          :key="item.id"
          :data-suggestion-id="item.id"
          data-generation-changed="true"
        >
          <span>{{ item.kind }} ({{ authorLabel(item.author_subject) }})</span>
          <button
            type="button"
            data-action="reject-stale"
            :disabled="!canReject(item)"
            @click="handleReject(item)"
          >
            Dismiss
          </button>
        </li>
      </ul>
    </section>
  </aside>
</template>
