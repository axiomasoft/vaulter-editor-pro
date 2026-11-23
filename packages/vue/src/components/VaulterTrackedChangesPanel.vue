<script setup lang="ts">
import { computed, onMounted } from 'vue'
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
  }>(),
  {
    editor: null,
    generation: 1,
    capabilities: undefined,
    initialSuggestions: undefined,
    clientOptions: undefined,
    autoLoad: true,
  },
)

const emit = defineEmits<{
  accepted: [suggestion: DocumentSuggestion]
  rejected: [suggestion: DocumentSuggestion]
  groupAccepted: [suggestions: DocumentSuggestion[]]
  groupRejected: [suggestions: DocumentSuggestion[]]
  projectionChange: [mode: TrackedProjection]
}>()

const {
  capabilities: activeCapabilities,
  suggestions,
  projection,
  loading,
  error,
  openSuggestions,
  conflictedSuggestions,
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
  setProjection,
} = useTrackedChanges(props.nodeId, {
  ...props.clientOptions,
  editor: props.editor,
  capabilities: props.capabilities ?? props.clientOptions?.capabilities,
  initialSuggestions: props.initialSuggestions ?? props.clientOptions?.initialSuggestions,
})

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
    const updated = await accept(item.id, item.generation || props.generation, item.version)
    emit('accepted', updated)
  } catch {
    // Error ref is populated by useTrackedChanges for alert rendering.
  }
}

async function handleReject(item: DocumentSuggestion): Promise<void> {
  if (!canReject(item)) return
  try {
    const updated = await reject(item.id, item.generation || props.generation, item.version)
    emit('rejected', updated)
  } catch {
    // Error ref is populated by useTrackedChanges for alert rendering.
  }
}

async function handleAcceptGroup(groupId: string, items: DocumentSuggestion[]): Promise<void> {
  if (items.some(i => !canAccept(i))) return
  const gen = items[0]?.generation || props.generation
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
  const gen = items[0]?.generation || props.generation
  const versions = Object.fromEntries(items.map(i => [i.id, i.version]))
  try {
    const updated = await rejectGroup(groupId, gen, versions)
    emit('groupRejected', updated)
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
      void list().catch(() => undefined)
    }
  }
})

defineExpose({
  capabilities: activeCapabilities,
  suggestions,
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
        v-for="item in suggestions"
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
          <span class="vaulter-tracked-changes__author">{{ item.author_subject }}</span>
          <span class="vaulter-tracked-changes__state">{{ item.state }}</span>
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
  </aside>
</template>
