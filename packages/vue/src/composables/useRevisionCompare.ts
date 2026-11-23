import { ref, shallowRef, type Ref, type ShallowRef } from 'vue'
import { useReviewClient, type ReviewClient } from '../api/reviewClient'
import type { PinReason, RevisionCompare, RevisionPin, RevisionPreview } from '../types/review'

export interface UseRevisionCompareOptions {
  client?: ReviewClient
}

export interface UseRevisionCompareReturn {
  pins: ShallowRef<RevisionPin[]>
  previewData: ShallowRef<RevisionPreview | null>
  compareData: ShallowRef<RevisionCompare | null>
  loading: Ref<boolean>
  error: Ref<unknown>
  listPins: (includeExpired?: boolean) => Promise<RevisionPin[]>
  pinRevision: (revisionId: string, reason?: PinReason, expiresAt?: string | null) => Promise<RevisionPin>
  unpinRevision: (revisionId: string, releaseRetention?: boolean) => Promise<void>
  loadPreview: (revisionId: string) => Promise<RevisionPreview>
  loadCompare: (fromRevisionId: string, toRevisionId?: string | null) => Promise<RevisionCompare>
}

export function useRevisionCompare(
  nodeId: string,
  options: UseRevisionCompareOptions = {},
): UseRevisionCompareReturn {
  const client = options.client ?? useReviewClient()
  const pins = shallowRef<RevisionPin[]>([])
  const previewData = shallowRef<RevisionPreview | null>(null)
  const compareData = shallowRef<RevisionCompare | null>(null)
  const loading = ref(false)
  const error = ref<unknown>(null)

  async function listPins(includeExpired = false): Promise<RevisionPin[]> {
    loading.value = true
    error.value = null
    try {
      const items = await client.pins.list(nodeId, includeExpired)
      pins.value = items
      return items
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function pinRevision(
    revisionId: string,
    reason: PinReason = 'manual_version',
    expiresAt: string | null = null,
  ): Promise<RevisionPin> {
    const pinned = await client.pins.pin(nodeId, revisionId, { pin_reason: reason, expires_at: expiresAt })
    pins.value = [pinned, ...pins.value.filter((p) => p.revision_id !== revisionId)]
    return pinned
  }

  async function unpinRevision(revisionId: string, releaseRetention = false): Promise<void> {
    await client.pins.unpin(nodeId, revisionId, releaseRetention)
    pins.value = pins.value.filter((p) => p.revision_id !== revisionId)
  }

  async function loadPreview(revisionId: string): Promise<RevisionPreview> {
    loading.value = true
    error.value = null
    try {
      const data = await client.pins.preview(nodeId, revisionId)
      previewData.value = data
      return data
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  async function loadCompare(fromRevisionId: string, toRevisionId: string | null = null): Promise<RevisionCompare> {
    loading.value = true
    error.value = null
    try {
      const data = await client.pins.compare(nodeId, fromRevisionId, toRevisionId)
      compareData.value = data
      return data
    } catch (caught) {
      error.value = caught
      throw caught
    } finally {
      loading.value = false
    }
  }

  return {
    pins,
    previewData,
    compareData,
    loading,
    error,
    listPins,
    pinRevision,
    unpinRevision,
    loadPreview,
    loadCompare,
  }
}
