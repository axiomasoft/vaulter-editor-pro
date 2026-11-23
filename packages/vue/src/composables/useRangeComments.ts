import { computed, ref, shallowRef, type ComputedRef, type Ref, type ShallowRef } from 'vue'
import { useReviewClient, type AddAnchoredCommentPayload, type ReviewClient } from '../api/reviewClient'
import type {
  AnchorActuality,
  AnchoredComment,
  CommentAnchorInput,
  CommentFilterStatus,
  PageMeta,
} from '../types/review'
import { evaluateAnchorActuality, type ActualityContext } from '../anchors/snapshotUtf16'

export interface UseRangeCommentsOptions {
  client?: ReviewClient
  initialFilter?: CommentFilterStatus
}

export interface CommentThreadItem {
  root: AnchoredComment
  replies: AnchoredComment[]
  actuality: AnchorActuality
  resolved: boolean
  detached: boolean
}

export interface UseRangeCommentsReturn {
  comments: ShallowRef<AnchoredComment[]>
  filter: Ref<CommentFilterStatus>
  cursor: ShallowRef<PageMeta | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  threads: (actualityCtx: ActualityContext) => CommentThreadItem[]
  filteredThreads: (actualityCtx: ActualityContext) => CommentThreadItem[]
  list: (status?: CommentFilterStatus) => Promise<AnchoredComment[]>
  loadMore: () => Promise<AnchoredComment[]>
  add: (input: AddAnchoredCommentPayload, idempotencyKey?: string) => Promise<AnchoredComment>
  reply: (rootCommentId: string, body: string, idempotencyKey?: string) => Promise<AnchoredComment>
  resolve: (commentId: string, etag?: string, idempotencyKey?: string) => Promise<AnchoredComment>
  reopen: (commentId: string, etag?: string, idempotencyKey?: string) => Promise<AnchoredComment>
  reanchor: (
    commentId: string,
    anchor: CommentAnchorInput,
    anchorEtag?: string,
    idempotencyKey?: string,
  ) => Promise<AnchoredComment>
  remove: (commentId: string, etag?: string) => Promise<void>
  activeCount: ComputedRef<number>
  resolvedCount: ComputedRef<number>
  detachedCount: ComputedRef<number>
}

export function isDetachedAnchor(comment: AnchoredComment): boolean {
  return comment.anchor !== null
    && (comment.anchor.anchor_state === 'detached' || comment.anchor.anchor_state === 'historical')
}

export function useRangeComments(nodeId: string, options: UseRangeCommentsOptions = {}): UseRangeCommentsReturn {
  const client = options.client ?? useReviewClient()
  const comments = shallowRef<AnchoredComment[]>([])
  const filter = ref<CommentFilterStatus>(options.initialFilter ?? 'all')
  const cursor = shallowRef<PageMeta | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)

  const roots = computed(() => comments.value.filter((c) => c.parent_id === null))
  const resolvedCount = computed(() => roots.value.filter((c) => c.resolved_at !== null).length)
  const detachedCount = computed(() => roots.value.filter((c) => c.resolved_at === null && isDetachedAnchor(c)).length)
  const activeCount = computed(() => roots.value.filter((c) => c.resolved_at === null && !isDetachedAnchor(c)).length)

  async function list(status?: CommentFilterStatus): Promise<AnchoredComment[]> {
    const activeFilter = status ?? filter.value
    loading.value = true
    error.value = null
    try {
      const page = await client.comments.list(nodeId, { status: activeFilter })
      comments.value = page.data
      cursor.value = page.meta
      return page.data
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function loadMore(): Promise<AnchoredComment[]> {
    const next = cursor.value?.next_cursor
    if (!next) {
      return comments.value
    }
    loading.value = true
    error.value = null
    try {
      const page = await client.comments.list(nodeId, { status: filter.value, cursor: next })
      comments.value = [...comments.value, ...page.data]
      cursor.value = page.meta
      return comments.value
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function add(input: AddAnchoredCommentPayload, idempotencyKey?: string): Promise<AnchoredComment> {
    error.value = null
    try {
      const created = await client.comments.add(nodeId, input, idempotencyKey)
      comments.value = [...comments.value, created]
      return created
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  function reply(rootCommentId: string, body: string, idempotencyKey?: string): Promise<AnchoredComment> {
    return add({ body, parent_id: rootCommentId, anchor: null }, idempotencyKey)
  }

  function resolveEtag(commentId: string, explicit?: string): string {
    if (explicit) {
      return explicit
    }
    const found = comments.value.find((c) => c.id === commentId)
    if (!found) {
      throw new Error(`[Vaulter Review] Comment ${commentId} not found in loaded thread list.`)
    }
    return found.etag
  }

  async function resolve(commentId: string, etag?: string, idempotencyKey?: string): Promise<AnchoredComment> {
    error.value = null
    try {
      const updated = await client.comments.resolve(nodeId, commentId, resolveEtag(commentId, etag), idempotencyKey)
      comments.value = comments.value.map((c) => (c.id === commentId ? updated : c))
      return updated
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function reopen(commentId: string, etag?: string, idempotencyKey?: string): Promise<AnchoredComment> {
    error.value = null
    try {
      const updated = await client.comments.reopen(nodeId, commentId, resolveEtag(commentId, etag), idempotencyKey)
      comments.value = comments.value.map((c) => (c.id === commentId ? updated : c))
      return updated
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function reanchor(
    commentId: string,
    anchor: CommentAnchorInput,
    anchorEtag?: string,
    idempotencyKey?: string,
  ): Promise<AnchoredComment> {
    const existing = comments.value.find((c) => c.id === commentId)
    const tag = anchorEtag ?? existing?.anchor?.etag ?? existing?.etag
    if (!tag) {
      throw new Error(`[Vaulter Review] Anchor ETag required to reanchor comment ${commentId}.`)
    }
    error.value = null
    try {
      const updated = await client.comments.reanchor(nodeId, commentId, anchor, tag, idempotencyKey)
      comments.value = comments.value.map((c) => (c.id === commentId ? updated : c))
      return updated
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  async function remove(commentId: string, etag?: string): Promise<void> {
    error.value = null
    try {
      await client.comments.remove(nodeId, commentId, resolveEtag(commentId, etag))
      comments.value = comments.value.filter((c) => c.id !== commentId && c.parent_id !== commentId)
    } catch (caught) {
      error.value = caught
      throw caught
    }
  }

  function threads(actualityCtx: ActualityContext): CommentThreadItem[] {
    const repliesByRoot = new Map<string, AnchoredComment[]>()
    for (const item of comments.value) {
      if (item.parent_id !== null) {
        const listForRoot = repliesByRoot.get(item.parent_id) ?? []
        listForRoot.push(item)
        repliesByRoot.set(item.parent_id, listForRoot)
      }
    }

    return roots.value.map((root) => {
      const actuality = evaluateAnchorActuality(root, actualityCtx)
      const resolved = root.resolved_at !== null
      const detached = isDetachedAnchor(root) || actuality.status === 'detached' || actuality.status === 'historical'
      return {
        root,
        replies: repliesByRoot.get(root.id) ?? [],
        actuality,
        resolved,
        detached,
      }
    })
  }

  function filteredThreads(actualityCtx: ActualityContext): CommentThreadItem[] {
    const all = threads(actualityCtx)
    if (filter.value === 'all') {
      return all
    }
    return all.filter((item) => {
      if (filter.value === 'resolved') {
        return item.resolved
      }
      if (filter.value === 'detached') {
        return !item.resolved && item.detached
      }
      return !item.resolved && !item.detached
    })
  }

  return {
    comments,
    filter,
    cursor,
    loading,
    error,
    threads,
    filteredThreads,
    list,
    loadMore,
    add,
    reply,
    resolve,
    reopen,
    reanchor,
    remove,
    activeCount,
    resolvedCount,
    detachedCount,
  }
}
