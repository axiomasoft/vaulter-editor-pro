import { inject, provide, type InjectionKey } from 'vue'
import type {
  AnchoredComment,
  ApplyExchangeImportPayload,
  ApplyExchangeReceipt,
  CommentAnchorInput,
  CommentFilterStatus,
  CursorPage,
  DecideGroupSuggestionsPayload,
  DecideSuggestionPayload,
  DocumentSuggestion,
  ExchangeJob,
  PinReason,
  RegisterExchangeJobPayload,
  RevisionCompare,
  RevisionPin,
  RevisionPreview,
  SaveSuggestionPayload,
  SuggestionCapabilities,
} from '../types/review'

export interface ReviewClientOptions {
  /** Base URL of the Vaulter REST API v1 prefix (default: `/api/vaulter/v1`). */
  baseUrl?: string
  /** Extra request headers or header factory. */
  headers?: Record<string, string> | (() => Record<string, string>)
  /** Optional CSRF token sent as `X-CSRF-TOKEN`. */
  csrfToken?: string
  /** Custom `fetch` implementation. */
  fetch?: typeof fetch
}

export class ReviewApiError extends Error {
  readonly status: number
  readonly code: string
  readonly title: string
  readonly detail?: string

  constructor(init: { status: number; code: string; title: string; detail?: string }) {
    super(init.detail || init.title || `HTTP ${init.status}`)
    this.name = 'ReviewApiError'
    this.status = init.status
    this.code = init.code
    this.title = init.title
    this.detail = init.detail
  }
}

export interface AddAnchoredCommentPayload {
  body: string
  parent_id?: string | null
  anchor?: CommentAnchorInput | null
}

export interface ReviewClient {
  pins: {
    list(nodeId: string, includeExpired?: boolean): Promise<RevisionPin[]>
    pin(nodeId: string, revisionId: string, input?: { pin_reason?: PinReason; expires_at?: string | null }): Promise<RevisionPin>
    unpin(nodeId: string, revisionId: string, releaseRetention?: boolean): Promise<void>
    preview(nodeId: string, revisionId: string): Promise<RevisionPreview>
    compare(nodeId: string, fromRevisionId: string, toRevisionId?: string | null): Promise<RevisionCompare>
  }
  comments: {
    list(nodeId: string, query?: { status?: CommentFilterStatus; cursor?: string | null; per_page?: number }): Promise<CursorPage<AnchoredComment>>
    add(nodeId: string, input: AddAnchoredCommentPayload, idempotencyKey?: string): Promise<AnchoredComment>
    resolve(nodeId: string, commentId: string, etag: string, idempotencyKey?: string): Promise<AnchoredComment>
    reopen(nodeId: string, commentId: string, etag: string, idempotencyKey?: string): Promise<AnchoredComment>
    reanchor(nodeId: string, commentId: string, anchor: CommentAnchorInput, anchorEtag: string, idempotencyKey?: string): Promise<AnchoredComment>
    remove(nodeId: string, commentId: string, etag: string): Promise<void>
  }
  exchange: {
    register(nodeId: string, input: RegisterExchangeJobPayload | FormData, idempotencyKey?: string): Promise<ExchangeJob>
    status(nodeId: string, jobId: string, includePreview?: boolean): Promise<ExchangeJob>
    fileUrl(nodeId: string, jobId: string): string
    download(nodeId: string, jobId: string): Promise<Blob>
    apply(nodeId: string, jobId: string, input: ApplyExchangeImportPayload, idempotencyKey?: string): Promise<ApplyExchangeReceipt>
  }
  suggestions?: {
    capabilities(nodeId?: string): Promise<SuggestionCapabilities>
    list(nodeId: string, query?: { proposal_id?: string | null }): Promise<DocumentSuggestion[]>
    save(nodeId: string, input: SaveSuggestionPayload, idempotencyKey?: string): Promise<DocumentSuggestion>
    accept(nodeId: string, suggestionId: string, payload: DecideSuggestionPayload, idempotencyKey?: string): Promise<DocumentSuggestion>
    reject(nodeId: string, suggestionId: string, payload: DecideSuggestionPayload, idempotencyKey?: string): Promise<DocumentSuggestion>
    acceptGroup(nodeId: string, groupId: string, payload: DecideGroupSuggestionsPayload, idempotencyKey?: string): Promise<DocumentSuggestion[]>
    rejectGroup(nodeId: string, groupId: string, payload: DecideGroupSuggestionsPayload, idempotencyKey?: string): Promise<DocumentSuggestion[]>
  }
}

function buildQueryString(query?: Record<string, string | number | boolean | null | undefined>): string {
  if (!query) {
    return ''
  }
  const params = new URLSearchParams()
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== null) {
      params.append(k, String(v))
    }
  }
  const s = params.toString()
  return s ? `?${s}` : ''
}

export function createReviewClient(options: ReviewClientOptions = {}): ReviewClient {
  const baseUrl = (options.baseUrl ?? '/api/vaulter/v1').replace(/\/+$/, '')
  const fetchImpl = options.fetch ?? globalThis.fetch.bind(globalThis)

  async function request<T>(
    method: string,
    path: string,
    init?: {
      query?: Record<string, string | number | boolean | null | undefined>
      json?: unknown
      formData?: FormData
      etag?: string
      idempotencyKey?: string
    },
  ): Promise<T> {
    const provided = typeof options.headers === 'function' ? options.headers() : options.headers ?? {}
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...provided,
      ...(options.csrfToken ? { 'X-CSRF-TOKEN': options.csrfToken } : {}),
      ...(init?.json !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...(init?.etag ? { 'If-Match': init.etag } : {}),
      ...(init?.idempotencyKey ? { 'Idempotency-Key': init.idempotencyKey } : {}),
    }

    const body = init?.formData ?? (init?.json !== undefined ? JSON.stringify(init.json) : undefined)

    const res = await fetchImpl(`${baseUrl}${path}${buildQueryString(init?.query)}`, {
      method,
      credentials: 'same-origin',
      headers,
      body,
    })

    if (!res.ok) {
      await throwProblemError(res)
    }

    if (res.status === 204) {
      return undefined as T
    }

    return (await res.json()) as T
  }

  async function throwProblemError(res: Response): Promise<never> {
    let body: Record<string, unknown> = {}
    try {
      body = (await res.json()) as Record<string, unknown>
    } catch {
      // non-JSON problem response
    }
    throw new ReviewApiError({
      status: res.status,
      code: typeof body.code === 'string' ? body.code : `http_${res.status}`,
      title: typeof body.title === 'string' ? body.title : res.statusText || 'Request failed',
      detail: typeof body.detail === 'string' ? body.detail : undefined,
    })
  }

  return {
    pins: {
      list: async (nodeId, includeExpired) =>
        (await request<{ data: RevisionPin[] }>('GET', `/review/documents/${nodeId}/pins`, {
          query: includeExpired ? { include_expired: true } : undefined,
        })).data,
      pin: async (nodeId, revisionId, input) =>
        (await request<{ data: RevisionPin }>('POST', `/review/documents/${nodeId}/revisions/${revisionId}/pin`, {
          json: input ?? {},
        })).data,
      unpin: (nodeId, revisionId, releaseRetention) =>
        request<void>('DELETE', `/review/documents/${nodeId}/revisions/${revisionId}/pin`, {
          query: releaseRetention ? { release_retention: true } : undefined,
        }),
      preview: async (nodeId, revisionId) =>
        (await request<{ data: RevisionPreview }>('GET', `/review/documents/${nodeId}/revisions/${revisionId}/preview`)).data,
      compare: async (nodeId, fromRevisionId, toRevisionId) =>
        (await request<{ data: RevisionCompare }>('GET', `/review/documents/${nodeId}/compare`, {
          query: { from: fromRevisionId, ...(toRevisionId ? { to: toRevisionId } : {}) },
        })).data,
    },
    comments: {
      list: (nodeId, query) =>
        request<CursorPage<AnchoredComment>>('GET', `/review/nodes/${nodeId}/comments`, {
          query: query ? { status: query.status, cursor: query.cursor, per_page: query.per_page } : undefined,
        }),
      add: async (nodeId, input, idempotencyKey) =>
        (await request<{ data: AnchoredComment }>('POST', `/review/nodes/${nodeId}/comments`, {
          json: input,
          idempotencyKey,
        })).data,
      resolve: async (nodeId, commentId, etag, idempotencyKey) =>
        (await request<{ data: AnchoredComment }>('POST', `/review/nodes/${nodeId}/comments/${commentId}/resolve`, {
          etag,
          idempotencyKey,
        })).data,
      reopen: async (nodeId, commentId, etag, idempotencyKey) =>
        (await request<{ data: AnchoredComment }>('POST', `/review/nodes/${nodeId}/comments/${commentId}/reopen`, {
          etag,
          idempotencyKey,
        })).data,
      reanchor: async (nodeId, commentId, anchor, anchorEtag, idempotencyKey) =>
        (await request<{ data: AnchoredComment }>('POST', `/review/nodes/${nodeId}/comments/${commentId}/reanchor`, {
          json: anchor,
          etag: anchorEtag,
          idempotencyKey,
        })).data,
      remove: (nodeId, commentId, etag) =>
        request<void>('DELETE', `/review/nodes/${nodeId}/comments/${commentId}`, { etag }),
    },
    exchange: {
      register: async (nodeId, input, idempotencyKey) => {
        const isForm = typeof FormData !== 'undefined' && input instanceof FormData
        return (
          await request<{ data: ExchangeJob }>('POST', `/documents/${nodeId}/exchange-jobs`, {
            ...(isForm ? { formData: input } : { json: input }),
            idempotencyKey,
          })
        ).data
      },
      status: async (nodeId, jobId, includePreview = true) =>
        (
          await request<{ data: ExchangeJob }>('GET', `/documents/${nodeId}/exchange-jobs/${jobId}`, {
            query: includePreview ? { include_preview: 1 } : { include_preview: 0 },
          })
        ).data,
      fileUrl: (nodeId, jobId) => `${baseUrl}/documents/${nodeId}/exchange-jobs/${jobId}/file`,
      download: async (nodeId, jobId) => {
        const provided = typeof options.headers === 'function' ? options.headers() : options.headers ?? {}
        const res = await fetchImpl(`${baseUrl}/documents/${nodeId}/exchange-jobs/${jobId}/file`, {
          method: 'GET',
          credentials: 'same-origin',
          headers: {
            ...provided,
            ...(options.csrfToken ? { 'X-CSRF-TOKEN': options.csrfToken } : {}),
          },
        })
        if (!res.ok) {
          await throwProblemError(res)
        }
        return res.blob()
      },
      apply: async (nodeId, jobId, input, idempotencyKey) =>
        (
          await request<{ data: ApplyExchangeReceipt }>('POST', `/documents/${nodeId}/exchange-jobs/${jobId}/apply`, {
            json: input,
            idempotencyKey,
          })
        ).data,
    },
    suggestions: {
      capabilities: async (nodeId) =>
        (
          await request<{ data: SuggestionCapabilities }>(
            'GET',
            nodeId ? `/review/documents/${nodeId}/suggestions/capabilities` : '/review/suggestions/capabilities',
          )
        ).data,
      list: async (nodeId, query) =>
        (
          await request<{ data: DocumentSuggestion[] }>('GET', `/review/documents/${nodeId}/suggestions`, {
            query: query?.proposal_id ? { proposal_id: query.proposal_id } : undefined,
          })
        ).data,
      save: async (nodeId, input, idempotencyKey) =>
        (
          await request<{ data: DocumentSuggestion }>('POST', `/review/documents/${nodeId}/suggestions`, {
            json: input,
            idempotencyKey,
          })
        ).data,
      accept: async (nodeId, suggestionId, payload, idempotencyKey) =>
        (
          await request<{ data: DocumentSuggestion }>('POST', `/review/documents/${nodeId}/suggestions/${suggestionId}/accept`, {
            json: payload,
            idempotencyKey,
          })
        ).data,
      reject: async (nodeId, suggestionId, payload, idempotencyKey) =>
        (
          await request<{ data: DocumentSuggestion }>('POST', `/review/documents/${nodeId}/suggestions/${suggestionId}/reject`, {
            json: payload,
            idempotencyKey,
          })
        ).data,
      acceptGroup: async (nodeId, groupId, payload, idempotencyKey) =>
        (
          await request<{ data: DocumentSuggestion[] }>('POST', `/review/documents/${nodeId}/suggestions/groups/${groupId}/accept`, {
            json: payload,
            idempotencyKey,
          })
        ).data,
      rejectGroup: async (nodeId, groupId, payload, idempotencyKey) =>
        (
          await request<{ data: DocumentSuggestion[] }>('POST', `/review/documents/${nodeId}/suggestions/groups/${groupId}/reject`, {
            json: payload,
            idempotencyKey,
          })
        ).data,
    },
  }
}

export const vaulterReviewClientKey: InjectionKey<ReviewClient> = Symbol('vaulter.review.client')

export function provideReviewClient(clientOrOptions?: ReviewClient | ReviewClientOptions): ReviewClient {
  const client = clientOrOptions && 'comments' in clientOrOptions
    ? clientOrOptions
    : createReviewClient(clientOrOptions)
  provide(vaulterReviewClientKey, client)
  return client
}

export function useReviewClient(): ReviewClient {
  const injected = inject(vaulterReviewClientKey, null)
  return injected ?? createReviewClient()
}
