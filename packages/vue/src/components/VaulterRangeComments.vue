<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { ActualityContext } from '../anchors/snapshotUtf16'
import { useRangeComments, type CommentThreadItem, type UseRangeCommentsOptions } from '../composables/useRangeComments'
import type { AnchoredComment, CommentFilterStatus } from '../types/review'

const props = withDefaults(
  defineProps<{
    nodeId: string
    actualityContext: ActualityContext
    clientOptions?: UseRangeCommentsOptions
    initialFilter?: CommentFilterStatus
    autoLoad?: boolean
    resolveAuthor?: (author: { type: string; id: string }) => string
  }>(),
  {
    clientOptions: undefined,
    initialFilter: 'all',
    autoLoad: true,
    resolveAuthor: undefined,
  },
)

const emit = defineEmits<{
  resolved: [comment: AnchoredComment]
  reopened: [comment: AnchoredComment]
  replied: [comment: AnchoredComment]
  requestReanchor: [comment: AnchoredComment]
  selectComment: [item: CommentThreadItem]
}>()

const {
  comments,
  filter,
  cursor,
  loading,
  error,
  filteredThreads,
  list,
  loadMore,
  reply,
  resolve,
  reopen,
  reanchor,
  activeCount,
  resolvedCount,
  detachedCount,
} = useRangeComments(props.nodeId, {
  ...props.clientOptions,
  initialFilter: props.initialFilter,
})

const replyDrafts = ref<Record<string, string>>({})

const visibleThreads = computed(() => filteredThreads(props.actualityContext))

const filterTabs: Array<{ key: CommentFilterStatus; label: string }> = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'resolved', label: 'Resolved' },
  { key: 'detached', label: 'Detached' },
]

function setFilter(next: CommentFilterStatus): void {
  filter.value = next
}

function authorLabel(author: { type: string; id: string }): string {
  return props.resolveAuthor?.(author) ?? `${author.type}#${author.id}`
}

async function handleResolve(comment: AnchoredComment): Promise<void> {
  try {
    const updated = await resolve(comment.id, comment.etag)
    emit('resolved', updated)
  } catch {
    // Error ref is populated by useRangeComments for alert rendering.
  }
}

async function handleReopen(comment: AnchoredComment): Promise<void> {
  try {
    const updated = await reopen(comment.id, comment.etag)
    emit('reopened', updated)
  } catch {
    // Error ref is populated by useRangeComments for alert rendering.
  }
}

async function handleReply(rootId: string): Promise<void> {
  const draft = (replyDrafts.value[rootId] ?? '').trim()
  if (!draft) {
    return
  }
  try {
    const created = await reply(rootId, draft)
    replyDrafts.value = { ...replyDrafts.value, [rootId]: '' }
    emit('replied', created)
  } catch {
    // Error ref is populated by useRangeComments for alert rendering.
  }
}

function handleRequestReanchor(comment: AnchoredComment): void {
  emit('requestReanchor', comment)
}

onMounted(() => {
  if (props.autoLoad) {
    void list().catch(() => undefined)
  }
})

defineExpose({
  comments,
  filter,
  loading,
  error,
  visibleThreads,
  activeCount,
  resolvedCount,
  detachedCount,
  list,
  loadMore,
  resolve: handleResolve,
  reopen: handleReopen,
  reanchor,
  reply,
})
</script>

<template>
  <aside class="vaulter-range-comments" aria-label="Range comments">
    <div class="vaulter-range-comments__filters" role="tablist">
      <button
        v-for="tab in filterTabs"
        :key="tab.key"
        type="button"
        role="tab"
        :aria-selected="filter === tab.key"
        :data-filter="tab.key"
        class="vaulter-range-comments__tab"
        @click="setFilter(tab.key)"
      >
        {{ tab.label }}
        <span v-if="tab.key === 'active'" class="vaulter-range-comments__count">({{ activeCount }})</span>
        <span v-else-if="tab.key === 'resolved'" class="vaulter-range-comments__count">({{ resolvedCount }})</span>
        <span v-else-if="tab.key === 'detached'" class="vaulter-range-comments__count">({{ detachedCount }})</span>
      </button>
    </div>

    <div v-if="loading" class="vaulter-range-comments__loading">Loading…</div>

    <ul v-else class="vaulter-range-comments__list">
      <li
        v-for="item in visibleThreads"
        :key="item.root.id"
        class="vaulter-range-comments__thread"
        :data-comment-id="item.root.id"
        :data-resolved="item.resolved"
        :data-actuality="item.actuality.status"
        @click="emit('selectComment', item)"
      >
        <div class="vaulter-range-comments__header">
          <span class="vaulter-range-comments__author">
            <slot name="author" :author="item.root.author" :label="authorLabel(item.root.author)">
              {{ authorLabel(item.root.author) }}
            </slot>
          </span>
          <span class="vaulter-range-comments__actuality" :data-status="item.actuality.status">
            {{ item.actuality.status }}
          </span>
        </div>

        <blockquote v-if="item.root.anchor" class="vaulter-range-comments__quote">
          {{ item.actuality.resolvedQuote ?? item.root.anchor.quote_text }}
        </blockquote>

        <p class="vaulter-range-comments__body">{{ item.root.body }}</p>

        <ul v-if="item.replies.length > 0" class="vaulter-range-comments__replies">
          <li
            v-for="rep in item.replies"
            :key="rep.id"
            class="vaulter-range-comments__reply"
            :data-reply-id="rep.id"
          >
            <span class="vaulter-range-comments__author">
              <slot name="author" :author="rep.author" :label="authorLabel(rep.author)">
                {{ authorLabel(rep.author) }}
              </slot>
            </span>:
            {{ rep.body }}
          </li>
        </ul>

        <div class="vaulter-range-comments__actions">
          <button
            v-if="!item.resolved"
            type="button"
            data-action="resolve"
            @click.stop="handleResolve(item.root)"
          >
            Resolve
          </button>
          <button
            v-else
            type="button"
            data-action="reopen"
            @click.stop="handleReopen(item.root)"
          >
            Reopen
          </button>
          <button
            v-if="item.actuality.needsReanchor"
            type="button"
            data-action="reanchor"
            @click.stop="handleRequestReanchor(item.root)"
          >
            Reanchor
          </button>
        </div>

        <form class="vaulter-range-comments__reply-form" @submit.prevent="handleReply(item.root.id)">
          <input
            v-model="replyDrafts[item.root.id]"
            type="text"
            placeholder="Write a reply…"
            class="vaulter-range-comments__reply-input"
          />
          <button type="submit" data-action="reply">Reply</button>
        </form>
      </li>
    </ul>

    <p v-if="error" class="vaulter-range-comments__error" role="alert">
      Could not load comments.
    </p>

    <button
      v-if="cursor?.next_cursor"
      type="button"
      class="vaulter-range-comments__more"
      @click="loadMore"
    >
      Load more
    </button>
  </aside>
</template>
