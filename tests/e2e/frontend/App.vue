<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Collaboration from '@tiptap/extension-collaboration'
import { HocuspocusProvider } from '@hocuspocus/provider'
import { ySyncPluginKey } from '@tiptap/y-tiptap'
import { BlockId, useVaulterClient } from '@vaulter/vue'
import {
  RangeAnchorHighlight,
  VaulterDocxExchange,
  VaulterHistoryCompare,
  VaulterLocalDocxExport,
  VaulterRangeComments,
  VaulterTrackedChanges,
  VaulterTrackedChangesPanel,
  buildYjsRelativePayload,
  createReviewClient,
  type OverlayEventsSource,
  type ReviewOverlayScope,
  type TipTapDocumentJson,
} from '@vaulter/editor-pro-vue'

const params = new URLSearchParams(window.location.search)
const mode = params.get('mode') ?? 'comments'
const nodeId = params.get('node') ?? ''
const userId = params.get('user') ?? '1'

const vaulterClient = useVaulterClient()
const reviewClient = createReviewClient({
  baseUrl: '/api/vaulter/v1',
  headers: () => ({ 'X-E2E-User': userId }),
})

// Live overlay event bus (local + remote Hub awareness broadcast in comments mode)
const overlayListeners = new Set<(event: { kind: 'review' | 'comments' }) => void>()
const overlayEventsCount = ref(0)
let broadcastOverlay: ((kind: 'review' | 'comments') => void) | null = null

const overlay: OverlayEventsSource = {
  overlayEvents(handler) {
    overlayListeners.add(handler)
    return () => overlayListeners.delete(handler)
  },
  invalidateOverlay(kind) {
    overlayEventsCount.value += 1
    for (const fn of overlayListeners) fn({ kind })
    broadcastOverlay?.(kind)
  },
}

// Shared reactive state
const collabStatus = ref('idle')
const docTick = ref(0)
const currentGeneration = ref(1)
const currentStateVersion = ref(1)
const currentEtag = ref('')
const docContent = ref<TipTapDocumentJson>({ type: 'doc', content: [] })
const editor = shallowRef<Editor | null>(null)
const providerRef = shallowRef<HocuspocusProvider | null>(null)
const selectionAnchor = ref<Record<string, unknown> | undefined>(undefined)
const commentsRef = ref<InstanceType<typeof VaulterRangeComments> | null>(null)
const panelRef = ref<InstanceType<typeof VaulterTrackedChangesPanel> | null>(null)
const pendingReanchorId = ref<string | null>(null)

// History state
const historyMode = ref<'compare' | 'preview'>('compare')
const historyRevisions = ref<Array<{ id: string; number: number; label?: string | null; created_at?: string }>>([])
const historyPins = ref<Array<{ id: string; revision_id: string }>>([])
const historyFrom = ref<TipTapDocumentJson>({ type: 'doc', content: [] })
const historyTo = ref<TipTapDocumentJson>({ type: 'doc', content: [] })
const historySelectedRevId = ref<string>('')

// Review state
const suggesting = ref(false)
const firstRevisionId = ref<string>('')
const reviewSaveStatus = ref('')

// DOCX state
const localExportBytes = ref(0)
const localExportStatus = ref('pending')
const exchangeInitialJobId = ref<string | undefined>(undefined)
const lastExchangeErrorCode = ref('')

const actualityContext = computed(() => {
  void docTick.value
  return {
    currentGeneration: currentGeneration.value,
    ydoc: providerRef.value?.document,
    tiptapDoc: editor.value?.state.doc,
  }
})

async function apiJson(url: string, init: RequestInit = {}) {
  const res = await fetch(url, {
    ...init,
    headers: {
      'Accept': 'application/json',
      'X-E2E-User': userId,
      ...(init.headers ?? {}),
    },
  })
  const text = await res.text()
  const json = text ? JSON.parse(text) : null
  if (!res.ok) {
    throw new Error(`${res.status}: ${text}`)
  }
  return { res, json }
}

function buildAnchorFromSelection(ed: Editor, from: number, to: number) {
  const ydoc = providerRef.value?.document
  if (!ydoc || to <= from) return undefined
  const blockId = String(ed.state.doc.resolve(from).parent?.attrs?.blockId ?? 'blk_1')
  const binding = ySyncPluginKey.getState(ed.state)?.binding
  const mapping = binding?.mapping ?? new Map()
  const payload = buildYjsRelativePayload(ydoc, ydoc.getXmlFragment('default'), from, to, blockId, mapping)
  if (!payload) return undefined
  return {
    generation: currentGeneration.value,
    source_position: 0,
    schema_version: 2,
    block_id: blockId,
    selector_kind: 'yjs_relative',
    selector_payload: payload,
    quote_text: ed.state.doc.textBetween(from, to),
  }
}

function selectPhrase(phrase: string) {
  const ed = editor.value
  if (!ed) return
  let foundFrom = -1
  let foundTo = -1
  ed.state.doc.descendants((node, pos) => {
    if (foundFrom >= 0 || !node.isText || !node.text) return
    const idx = node.text.indexOf(phrase)
    if (idx >= 0) {
      foundFrom = pos + idx
      foundTo = foundFrom + phrase.length
    }
  })
  if (foundFrom >= 0) {
    ed.chain().focus().setTextSelection({ from: foundFrom, to: foundTo }).run()
    selectionAnchor.value = buildAnchorFromSelection(ed, foundFrom, foundTo)
  }
}

function prependTextToFirstParagraph(prefix: string) {
  const ed = editor.value
  if (!ed) return
  let insertPos = 1
  ed.state.doc.descendants((node, pos) => {
    if (insertPos === 1 && node.isText) {
      insertPos = pos
    }
  })
  ed.chain().focus().insertContentAt(insertPos, prefix).run()
}

async function initCommentsMode() {
  collabStatus.value = 'connecting'
  const { json } = await apiJson(`/api/vaulter/v1/documents/${nodeId}/collaboration/session`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ mode: 'write' }),
  })
  const session = json.data
  currentGeneration.value = session.generation

  let nonce = 0
  const seenRemoteNonces = new Set<string>()

  const provider = new HocuspocusProvider({
    url: session.endpoint,
    name: session.options.room,
    token: session.token,
    onSynced: () => {
      collabStatus.value = session.mode === 'read' ? 'connected:readonly' : 'connected:writable'
      if (!editor.value) {
        editor.value = new Editor({
          extensions: [
            StarterKit.configure({ undoRedo: false }),
            BlockId,
            Collaboration.configure({ document: provider.document }),
            RangeAnchorHighlight,
          ],
          onUpdate: () => {
            docTick.value += 1
          },
          onSelectionUpdate: ({ editor: ed }) => {
            const { from, to } = ed.state.selection
            if (to > from) {
              selectionAnchor.value = buildAnchorFromSelection(ed, from, to)
            }
          },
        })
      }
    },
    onAwarenessUpdate: ({ states }) => {
      for (const st of states) {
        const ov = st?.overlayVersion as { id?: string; kind?: 'review' | 'comments' } | undefined
        if (ov?.id && !seenRemoteNonces.has(ov.id)) {
          seenRemoteNonces.add(ov.id)
          if (!ov.id.startsWith(`${provider.document.clientID}:`)) {
            overlayEventsCount.value += 1
            for (const fn of overlayListeners) fn({ kind: ov.kind ?? 'comments' })
          }
        }
      }
    },
  })

  broadcastOverlay = (kind) => {
    nonce += 1
    const id = `${provider.document.clientID}:${nonce}`
    seenRemoteNonces.add(id)
    provider.setAwarenessField('overlayVersion', { id, kind })
  }

  providerRef.value = provider
}

async function initHistoryMode() {
  const { json: revsJson } = await apiJson(`/api/vaulter/v1/documents/${nodeId}/revisions`)
  historyRevisions.value = revsJson.data ?? []
  const { json: stateJson } = await apiJson(`/e2e/document/${nodeId}/state`)
  historyPins.value = stateJson.pins ?? []

  if (historyRevisions.value.length >= 2) {
    const rev1 = historyRevisions.value[historyRevisions.value.length - 1]
    const revLatest = historyRevisions.value[0]
    historySelectedRevId.value = String(rev1.id)
    await loadCompare(String(rev1.id), String(revLatest.id))
  } else {
    const { json: contentJson } = await apiJson(`/api/vaulter/v1/documents/${nodeId}/content`)
    historyFrom.value = contentJson.data.content
    historyTo.value = contentJson.data.content
  }
}

async function loadCompare(fromId: string, toId: string) {
  const data = await reviewClient.pins.compare(nodeId, fromId, toId)
  historyFrom.value = data.from.content as TipTapDocumentJson
  historyTo.value = data.to.content as TipTapDocumentJson
}

async function handlePin(revisionId: string) {
  const pin = await reviewClient.pins.pin(nodeId, revisionId)
  historyPins.value = [...historyPins.value, pin]
}

async function handleUnpin(revisionId: string) {
  await reviewClient.pins.unpin(nodeId, revisionId)
  historyPins.value = historyPins.value.filter((p) => String(p.revision_id) !== String(revisionId))
}

async function initReviewMode() {
  const { res, json } = await apiJson(`/api/vaulter/v1/documents/${nodeId}/content`)
  currentEtag.value = res.headers.get('etag') ?? `"${json.data.state_version}"`
  docContent.value = json.data.content
  currentGeneration.value = json.data.collab_epoch ?? 0
  currentStateVersion.value = json.data.state_version ?? 1

  const { json: stateJson } = await apiJson(`/e2e/document/${nodeId}/state`)
  currentGeneration.value = stateJson.generation ?? 0
  const { json: revsJson } = await apiJson(`/api/vaulter/v1/documents/${nodeId}/revisions`)
  const revs = revsJson.data ?? []
  firstRevisionId.value = revs.length > 0 ? String(revs[revs.length - 1].id) : String(stateJson.current_revision_id)

  editor.value = new Editor({
    content: docContent.value,
    extensions: [
      StarterKit,
      BlockId,
      VaulterTrackedChanges.configure({
        author: () => userId,
        enabled: () => suggesting.value,
        createId: () => crypto.randomUUID(),
        onSuggestions: () => {},
        onUnsupportedEdit: () => {},
      }),
    ],
  })
}

function toggleSuggesting() {
  suggesting.value = !suggesting.value
}

async function saveTrackedSuggestion(kind: 'insert' | 'delete', blockId: string, text: string) {
  const ed = editor.value
  if (!ed) return
  if (kind === 'insert') {
    ed.chain().focus().insertContentAt(2, text).run()
  }
  try {
    const saved = await reviewClient.suggestions.save(
      nodeId,
      {
        id: crypto.randomUUID(),
        group_id: crypto.randomUUID(),
        generation: currentGeneration.value,
        base_position: currentStateVersion.value,
        schema_version: 2,
        kind,
        target_block_id: blockId,
        from: 0,
        to: kind === 'delete' ? text.length : 0,
        ...(kind === 'delete' ? { before: text } : { after: text }),
      },
      `e2e-${userId}-${Date.now()}-${Math.random()}`,
    )
    reviewSaveStatus.value = `saved:${saved.id}`
    await panelRef.value?.list()
  } catch (e) {
    reviewSaveStatus.value = `err:${e instanceof Error ? e.message : String(e)}`
  }
}

async function restoreFirstRevision() {
  const { res: preRes, json: preJson } = await apiJson(`/api/vaulter/v1/documents/${nodeId}/content`)
  const freshEtag = preRes.headers.get('etag') ?? `"${preJson.data.state_version}"`
  await apiJson(
    `/api/vaulter/v1/documents/${nodeId}/revisions/${firstRevisionId.value}/restore`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'If-Match': freshEtag,
      },
      body: JSON.stringify({}),
    },
  )
  const { json: stateJson } = await apiJson(`/e2e/document/${nodeId}/state`)
  currentEtag.value = `"${stateJson.state_version}"`
  currentGeneration.value = stateJson.generation
  await panelRef.value?.list(undefined, true)
}

async function initDocxMode() {
  const { json } = await apiJson(`/api/vaulter/v1/documents/${nodeId}/content`)
  docContent.value = json.data.content
  currentGeneration.value = json.data.collab_epoch ?? 0
}

function handleLocalExported(payload: { bytes: Uint8Array }) {
  localExportBytes.value = payload.bytes.byteLength
  localExportStatus.value = 'exported'
}

async function confirmReanchor() {
  const targetId = pendingReanchorId.value ?? commentsRef.value?.comments?.[0]?.id
  if (!targetId || !selectionAnchor.value || !commentsRef.value) return
  await commentsRef.value.reanchor(targetId, selectionAnchor.value as never)
  pendingReanchorId.value = null
  overlay.invalidateOverlay('comments')
}

onMounted(async () => {
  window.addEventListener('pagehide', () => {
    providerRef.value?.destroy()
  })
  if (mode === 'comments') await initCommentsMode()
  else if (mode === 'history') await initHistoryMode()
  else if (mode === 'review') await initReviewMode()
  else if (mode === 'docx' || mode === 'files') await initDocxMode()
})

onBeforeUnmount(() => {
  editor.value?.destroy()
  providerRef.value?.destroy()
})
</script>

<template>
  <main :data-mode="mode" :data-user="userId">
    <header style="margin-bottom: 12px; display: flex; gap: 12px; align-items: center;">
      <span data-testid="collab-status" :data-status="collabStatus">{{ collabStatus }}</span>
      <span data-testid="overlay-events-count">{{ overlayEventsCount }}</span>
      <span data-testid="current-generation">{{ currentGeneration }}</span>
    </header>

    <section v-if="mode === 'comments'" class="e2e-grid">
      <div>
        <div style="margin-bottom: 8px; display: flex; gap: 8px;">
          <button data-testid="select-target-phrase" type="button" @click="selectPhrase('target clause')">Select Target</button>
          <button data-testid="select-second-paragraph" type="button" @click="selectPhrase('Second paragraph')">Select Second</button>
          <button data-testid="prepend-prefix" type="button" @click="prependTextToFirstParagraph('Prefix added by peer ')">Prepend Prefix</button>
          <button data-testid="confirm-reanchor" type="button" @click="confirmReanchor">Confirm Reanchor</button>
        </div>
        <EditorContent v-if="editor" :editor="editor" data-testid="collab-editor" />
      </div>
      <VaulterRangeComments
        ref="commentsRef"
        :node-id="nodeId"
        :actuality-context="actualityContext"
        :client-options="{ client: reviewClient, overlay }"
        :selection-anchor="selectionAnchor"
        @resolved="overlay.invalidateOverlay('comments')"
        @reopened="overlay.invalidateOverlay('comments')"
        @replied="overlay.invalidateOverlay('comments')"
        @created="overlay.invalidateOverlay('comments')"
        @request-reanchor="(c) => { pendingReanchorId = c.id }"
      />
    </section>

    <section v-else-if="mode === 'history'">
      <div style="margin-bottom: 8px; display: flex; gap: 8px;">
        <button
          data-testid="toggle-preview-mode"
          type="button"
          @click="historyMode = historyMode === 'compare' ? 'preview' : 'compare'"
        >
          Mode: {{ historyMode }}
        </button>
      </div>
      <VaulterHistoryCompare
        :mode="historyMode"
        :from-content="historyFrom"
        :to-content="historyTo"
        :node-id="nodeId"
        :revisions="historyRevisions"
        :pins="historyPins"
        initial-layout="side-by-side"
        @pin="handlePin"
        @unpin="handleUnpin"
        @select-revision="(revId) => loadCompare(String(revId), String(historyRevisions[0]?.id ?? revId))"
      />
    </section>

    <section v-else-if="mode === 'review'" class="e2e-grid">
      <div>
        <div style="margin-bottom: 8px; display: flex; gap: 8px; flex-wrap: wrap;">
          <button data-testid="toggle-suggesting" type="button" @click="toggleSuggesting">
            Suggesting: {{ suggesting ? 'ON' : 'OFF' }}
          </button>
          <button data-testid="save-insert-blk1" type="button" @click="saveTrackedSuggestion('insert', 'blk_1', ' [proposed]')">
            Save Insert
          </button>
          <button data-testid="save-delete-blk2" type="button" @click="saveTrackedSuggestion('delete', 'blk_2', 'Second')">
            Save Delete
          </button>
          <button data-testid="restore-rev-1" type="button" @click="restoreFirstRevision">
            Restore Rev 1
          </button>
          <span data-testid="review-save-status">{{ reviewSaveStatus }}</span>
        </div>
        <EditorContent v-if="editor" :editor="editor" data-testid="review-editor" />
      </div>
      <VaulterTrackedChangesPanel
        ref="panelRef"
        :node-id="nodeId"
        :editor="editor"
        :generation="currentGeneration"
        :client-options="{ client: reviewClient, overlay }"
        :auto-load="true"
        :include-stale-generations="true"
      />
    </section>

    <section v-else-if="mode === 'docx' || mode === 'files'">
      <div v-if="mode === 'docx'" style="margin-bottom: 16px;">
        <VaulterLocalDocxExport
          :content="docContent"
          :confirmed="true"
          :accessible="true"
          :enable-discussions="true"
          :enable-revisions="true"
          default-profile="discussions"
          :auto-preview="true"
          @exported="handleLocalExported"
        />
        <div data-testid="local-export-bytes">{{ localExportBytes }}</div>
        <div data-testid="local-export-status">{{ localExportStatus }}</div>
      </div>
      <VaulterDocxExchange
        :node-id="nodeId"
        :client="reviewClient"
        :initial-job-id="exchangeInitialJobId"
        default-profile="discussions"
        :auto-reconnect="true"
        @error="(err) => { lastExchangeErrorCode = (err as { code?: string })?.code ?? String(err) }"
      />
      <span data-testid="exchange-error-code">{{ lastExchangeErrorCode }}</span>
    </section>
  </main>
</template>
