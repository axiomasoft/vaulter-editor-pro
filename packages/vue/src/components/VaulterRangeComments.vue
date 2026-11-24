<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { ActualityContext } from '../anchors/snapshotUtf16'
import { useRangeComments, type CommentThreadItem, type UseRangeCommentsOptions } from '../composables/useRangeComments'
import type { AnchoredComment, CommentAnchorInput, CommentFilterStatus } from '../types/review'

const props = withDefaults(
  defineProps<{
    nodeId: string
    actualityContext: ActualityContext
    clientOptions?: UseRangeCommentsOptions
    initialFilter?: CommentFilterStatus
    autoLoad?: boolean
    selectionAnchor?: CommentAnchorInput | null
    resolveAuthor?: (author: { type: string; id: string }) => string
  }>(),
  {
    clientOptions: undefined,
    initialFilter: 'all',
    autoLoad: true,
    selectionAnchor: null,
    resolveAuthor: undefined,
  },
)

const emit = defineEmits<{
  created: [comment: AnchoredComment]
  edited: [comment: AnchoredComment]
  archived: [comment: AnchoredComment]
  deleted: [commentId: string]
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
  add,
  reply,
  edit,
  archive,
  resolve,
  reopen,
  reanchor,
  remove,
  activeCount,
  resolvedCount,
  detachedCount,
  archivedCount,
} = useRangeComments(props.nodeId, {
  ...props.clientOptions,
  initialFilter: props.initialFilter,
})

const replyDrafts = ref<Record<string, string>>({})
const newCommentBody = ref('')
const editingCommentId = ref<string | null>(null)
const editDraft = ref('')

const visibleThreads = computed(() => filteredThreads(props.actualityContext))

const filterTabs: Array<{ key: CommentFilterStatus; label: string }> = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'resolved', label: 'Resolved' },
  { key: 'detached', label: 'Detached' },
  { key: 'archived', label: 'Archived' },
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

async function handleCreate(): Promise<void> {
  const body = newCommentBody.value.trim()
  if (!body) {
    return
  }
  try {
    const created = await add({ body, parent_id: null, anchor: props.selectionAnchor ?? null })
    newCommentBody.value = ''
    emit('created', created)
  } catch {
    // Error ref is populated by useRangeComments.
  }
}

function startEdit(comment: AnchoredComment): void {
  editingCommentId.value = comment.id
  editDraft.value = comment.body
}

function cancelEdit(): void {
  editingCommentId.value = null
  editDraft.value = ''
}

async function handleSaveEdit(comment: AnchoredComment): Promise<void> {
  const body = editDraft.value.trim()
  if (!body) {
    return
  }
  try {
    const updated = await edit(comment.id, body, comment.etag)
    editingCommentId.value = null
    editDraft.value = ''
    emit('edited', updated)
  } catch {
    // Error ref is populated by useRangeComments.
  }
}

async function handleArchive(comment: AnchoredComment): Promise<void> {
  try {
    const updated = await archive(comment.id, !comment.archived_at, comment.etag)
    emit('archived', updated)
  } catch {
    // Error ref is populated by useRangeComments.
  }
}

async function handleDelete(comment: AnchoredComment): Promise<void> {
  try {
    await remove(comment.id, comment.etag)
    emit('deleted', comment.id)
  } catch {
    // Error ref is populated by useRangeComments.
  }
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
  archivedCount,
  list,
  loadMore,
  add: handleCreate,
  edit: handleSaveEdit,
  archive: handleArchive,
  remove: handleDelete,
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
        <span v-else-if="tab.key === 'archived'" class="vaulter-range-comments__count">({{ archivedCount }})</span>
      </button>
    </div>

    <form class="vaulter-range-comments__create-form" data-testid="create-comment-form" @submit.prevent="handleCreate">
      <input
        v-model="newCommentBody"
        type="text"
        placeholder="Add anchored comment…"
        class="vaulter-range-comments__create-input"
      />
      <button type="submit" data-action="create">Comment</button>
    </form>

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

        <p v-if="editingCommentId !== item.root.id" class="vaulter-range-comments__body">
          {{ item.root.body }}
          <span v-if="item.root.edited_at" class="vaulter-range-comments__edited">(edited)</span>
          <span v-if="item.archived" class="vaulter-range-comments__archived">(archived)</span>
        </p>
        <form
          v-else
          class="vaulter-range-comments__edit-form"
          data-testid="edit-comment-form"
          @submit.prevent="handleSaveEdit(item.root)"
        >
          <input v-model="editDraft" type="text" class="vaulter-range-comments__edit-input" />
          <button type="submit" data-action="save-edit">Save</button>
          <button type="button" data-action="cancel-edit" @click.stop="cancelEdit">Cancel</button>
        </form>

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
          <button type="button" data-action="edit" @click.stop="startEdit(item.root)">
            Edit
          </button>
          <button type="button" data-action="archive" @click.stop="handleArchive(item.root)">
            {{ item.archived ? 'Unarchive' : 'Archive' }}
          </button>
          <button type="button" data-action="delete" @click.stop="handleDelete(item.root)">
            Delete
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
