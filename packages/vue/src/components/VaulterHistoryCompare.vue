<script setup lang="ts">
import { Extension, getSchema, Node, type Extensions } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { TableKit } from '@tiptap/extension-table'
import { Node as ProseMirrorNode, Schema } from '@tiptap/pm/model'
import { EditorState, Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet, EditorView } from '@tiptap/pm/view'
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { computeStructuralDiff, type StructuralDiffResult } from '../compare/structuralDiff'
import type { CompareLayout, DocumentRevisionSummary, RevisionPin } from '../types/review'

const props = withDefaults(
  defineProps<{
    /** Base immutable revision TipTap JSON content (or single preview content when `mode === 'preview'`). */
    fromContent: Record<string, unknown>
    /** Target immutable revision TipTap JSON content (ignored when `mode === 'preview'`). */
    toContent?: Record<string, unknown> | null
    mode?: 'compare' | 'preview'
    extensions?: Extensions
    nodeId?: string
    revisions?: DocumentRevisionSummary[]
    pins?: RevisionPin[]
    initialLayout?: CompareLayout
  }>(),
  {
    toContent: null,
    mode: 'compare',
    extensions: undefined,
    nodeId: undefined,
    revisions: () => [],
    pins: () => [],
    initialLayout: 'inline',
  },
)

const emit = defineEmits<{
  compared: [result: StructuralDiffResult]
  layoutChange: [layout: CompareLayout]
  pin: [revisionId: string]
  unpin: [revisionId: string]
  restore: [revisionId: string]
  selectRevision: [revisionId: string]
}>()

const ReviewBlockId = Extension.create({
  name: 'reviewBlockId',
  addGlobalAttributes() {
    return [{
      types: ['paragraph', 'heading'],
      attributes: {
        blockId: {
          default: null,
          renderHTML: ({ blockId }) => typeof blockId === 'string' && blockId !== '' ? { 'data-block-id': blockId } : {},
        },
      },
    }]
  },
})

const ReviewImage = Node.create({
  name: 'image',
  group: 'block',
  addAttributes() {
    return {
      src: { default: null },
      alt: { default: null },
      title: { default: null },
      width: { default: null },
      height: { default: null },
    }
  },
  renderHTML({ HTMLAttributes }) {
    return ['img', HTMLAttributes]
  },
})

const ReviewTableCellAlign = Extension.create({
  name: 'reviewTableCellAlign',
  addGlobalAttributes() {
    return [
      {
        types: ['tableCell', 'tableHeader'],
        attributes: {
          align: { default: null },
        },
      },
    ]
  },
})

function createIsolatedSchema(custom?: Extensions): Schema {
  if (custom && custom.length > 0) {
    return getSchema(custom)
  }
  return getSchema([
    StarterKit.configure({
      link: { openOnClick: false },
    }),
    TableKit,
    ReviewBlockId,
    ReviewImage,
    ReviewTableCellAlign,
  ])
}

const COMPARE_DECORATION_KEY = new PluginKey('vaulterIsolatedCompareDecorations')

const SAFE_URL_PROTOCOLS = new Set(['http:', 'https:', 'mailto:'])

function isSafePreviewUrl(raw: unknown): boolean {
  if (typeof raw !== 'string') {
    return false
  }
  // eslint-disable-next-line no-control-regex
  const cleaned = raw.replace(/[\u0000-\u0020\u007f]+/g, '').trim()
  if (cleaned === '') {
    return false
  }
  try {
    const parsed = new URL(cleaned)
    return SAFE_URL_PROTOCOLS.has(parsed.protocol.toLowerCase())
  } catch {
    return false
  }
}

function sanitizeTipTapNode(node: unknown): unknown {
  if (Array.isArray(node)) {
    return node.map(sanitizeTipTapNode).filter((n) => n !== null)
  }
  if (node === null || typeof node !== 'object') {
    return node
  }
  const obj = node as Record<string, unknown>
  if (obj.type === 'image') {
    const attrs = (obj.attrs ?? {}) as Record<string, unknown>
    if (!isSafePreviewUrl(attrs.src)) {
      return null
    }
  }
  const next: Record<string, unknown> = { ...obj }
  if (Array.isArray(obj.marks)) {
    const safeMarks = obj.marks.filter((mark) => {
      if (mark === null || typeof mark !== 'object') {
        return false
      }
      const m = mark as Record<string, unknown>
      if (m.type === 'link') {
        const attrs = (m.attrs ?? {}) as Record<string, unknown>
        return isSafePreviewUrl(attrs.href)
      }
      return true
    })
    if (safeMarks.length > 0) {
      next.marks = safeMarks
    } else {
      delete next.marks
    }
  }
  if (Array.isArray(obj.content)) {
    next.content = sanitizeTipTapNode(obj.content)
  }
  return next
}

function formatAttrVal(v: unknown): string {
  if (v === null || v === undefined) {
    return 'none'
  }
  return typeof v === 'object' ? JSON.stringify(v) : String(v)
}

function buildCompareDecorations(doc: ProseMirrorNode, diff: StructuralDiffResult | null): DecorationSet {
  if (!diff || !diff.hasChanges) {
    return DecorationSet.empty
  }

  const size = doc.content.size
  const decorations: Decoration[] = []

  for (const span of diff.inlineSpans) {
    if (span.kind === 'insert') {
      const from = Math.max(0, Math.min(size, span.fromB))
      const to = Math.max(from, Math.min(size, span.toB))
      if (to > from) {
        decorations.push(
          Decoration.inline(from, to, {
            class: 'vaulter-diff-insert',
            'data-diff-kind': 'insert',
          }),
        )
      }
    } else if (span.kind === 'mark_change') {
      const from = Math.max(0, Math.min(size, span.fromB))
      const to = Math.max(from, Math.min(size, span.toB))
      if (to > from) {
        decorations.push(
          Decoration.inline(from, to, {
            class: 'vaulter-diff-mark-change',
            'data-diff-kind': 'mark_change',
          }),
        )
      }
    } else if (span.kind === 'delete' && span.text.length > 0) {
      const pos = Math.max(0, Math.min(size, span.fromB))
      decorations.push(
        Decoration.widget(
          pos,
          () => {
            const el = document.createElement('span')
            el.className = 'vaulter-diff-delete'
            el.dataset.diffKind = 'delete'
            el.textContent = span.text
            return el
          },
          { side: -1 },
        ),
      )
    }
  }

  for (const attrChange of diff.attrChanges) {
    const pos = Math.max(0, Math.min(size, attrChange.fromB))
    decorations.push(
      Decoration.widget(
        pos,
        () => {
          const badge = document.createElement('span')
          badge.className = 'vaulter-diff-attr-badge'
          badge.dataset.diffKind = 'attr_change'
          badge.dataset.attr = attrChange.attr
          badge.textContent = `${attrChange.nodeType}.${attrChange.attr}: ${formatAttrVal(attrChange.oldValue)} -> ${formatAttrVal(attrChange.newValue)}`
          return badge
        },
        { side: -1 },
      ),
    )
  }

  return DecorationSet.create(doc, decorations)
}

const mountEl = ref<HTMLElement | null>(null)
const fromMountEl = ref<HTMLElement | null>(null)
const view = shallowRef<EditorView | null>(null)
const fromView = shallowRef<EditorView | null>(null)
const layout = ref<CompareLayout>(props.initialLayout)
const diffResult = shallowRef<StructuralDiffResult | null>(null)
const fromDoc = shallowRef<ProseMirrorNode | null>(null)
const toDoc = shallowRef<ProseMirrorNode | null>(null)

const pinnedIds = computed(() => new Set(props.pins.map((p) => p.revision_id)))

function setLayout(next: CompareLayout): void {
  if (layout.value === next) return
  layout.value = next
  emit('layoutChange', next)
}

const insertCount = computed(() => diffResult.value?.inlineSpans.filter((s) => s.kind === 'insert').length ?? 0)
const deleteCount = computed(() => diffResult.value?.inlineSpans.filter((s) => s.kind === 'delete').length ?? 0)
const markChangeCount = computed(() => diffResult.value?.inlineSpans.filter((s) => s.kind === 'mark_change').length ?? 0)
const attrChangeCount = computed(() => diffResult.value?.attrChanges.length ?? 0)

function renderIsolatedView(): void {
  if (!mountEl.value) {
    return
  }

  const schema = createIsolatedSchema(props.extensions)
  const sanitizedFrom = sanitizeTipTapNode(props.fromContent) as Record<string, unknown>
  const parsedFrom = schema.nodeFromJSON(sanitizedFrom)
  const effectiveToJson = props.mode === 'compare' && props.toContent !== null ? props.toContent : props.fromContent
  const sanitizedTo = sanitizeTipTapNode(effectiveToJson) as Record<string, unknown>
  const parsedTo = schema.nodeFromJSON(sanitizedTo)

  fromDoc.value = parsedFrom
  toDoc.value = parsedTo

  const computedDiff = props.mode === 'compare' && props.toContent !== null
    ? computeStructuralDiff(parsedFrom, parsedTo)
    : computeStructuralDiff(parsedFrom, parsedFrom)

  diffResult.value = computedDiff

  const state = EditorState.create({
    schema,
    doc: parsedTo,
    plugins: [
      new Plugin({
        key: COMPARE_DECORATION_KEY,
        props: {
          decorations(st) {
            return buildCompareDecorations(st.doc, computedDiff)
          },
        },
      }),
    ],
  })

  if (view.value) {
    view.value.updateState(state)
  } else {
    // Isolated read-only EditorView: never bound to Yjs or any live editor, zero cleanup callbacks, zero live updates.
    view.value = new EditorView(mountEl.value, {
      state,
      editable: () => false,
      dispatchTransaction() {
        // Read-only immutable history preview/compare blocks any transaction mutations.
      },
    })
  }

  if (layout.value === 'side-by-side' && props.mode === 'compare' && fromMountEl.value) {
    const fromState = EditorState.create({ schema, doc: parsedFrom })
    if (fromView.value) {
      fromView.value.updateState(fromState)
    } else {
      fromView.value = new EditorView(fromMountEl.value, {
        state: fromState,
        editable: () => false,
        dispatchTransaction() {
          // Read-only base pane.
        },
      })
    }
  } else if (fromView.value) {
    fromView.value.destroy()
    fromView.value = null
  }

  emit('compared', computedDiff)
}

onMounted(() => {
  renderIsolatedView()
})

watch(
  () => [props.fromContent, props.toContent, props.mode, layout.value],
  () => {
    renderIsolatedView()
  },
  { deep: false },
)

onBeforeUnmount(() => {
  view.value?.destroy()
  view.value = null
  fromView.value?.destroy()
  fromView.value = null
})

defineExpose({
  view,
  fromView,
  layout,
  setLayout,
  diffResult,
  fromDoc,
  toDoc,
  insertCount,
  deleteCount,
  markChangeCount,
  attrChangeCount,
})
</script>

<template>
  <div class="vaulter-history-compare" :data-mode="mode" :data-layout="layout" data-readonly="true">
    <div v-if="mode === 'compare'" class="vaulter-history-compare__toolbar">
      <button
        type="button"
        data-layout-option="inline"
        :aria-pressed="layout === 'inline'"
        @click="setLayout('inline')"
      >
        Inline
      </button>
      <button
        type="button"
        data-layout-option="side-by-side"
        :aria-pressed="layout === 'side-by-side'"
        @click="setLayout('side-by-side')"
      >
        Side-by-side
      </button>
    </div>
    <div v-if="mode === 'compare' && diffResult" class="vaulter-history-compare__summary">
      <span class="vaulter-history-compare__stat" data-stat="inserts">+{{ insertCount }}</span>
      <span class="vaulter-history-compare__stat" data-stat="deletes">-{{ deleteCount }}</span>
      <span class="vaulter-history-compare__stat" data-stat="marks">~marks: {{ markChangeCount }}</span>
      <span class="vaulter-history-compare__stat" data-stat="attrs">attrs: {{ attrChangeCount }}</span>
    </div>
    <div class="vaulter-history-compare__body">
      <aside v-if="revisions.length > 0" class="vaulter-history-compare__timeline" data-testid="revision-timeline">
        <ul>
          <li
            v-for="rev in revisions"
            :key="rev.id"
            :data-revision-id="rev.id"
            :data-pinned="pinnedIds.has(rev.id) ? 'true' : 'false'"
          >
            <span>#{{ rev.number }} {{ rev.label || (rev.is_autosave ? 'Autosave' : 'Revision') }}</span>
            <button type="button" data-action="select-revision" @click="emit('selectRevision', rev.id)">
              Compare
            </button>
            <button
              v-if="pinnedIds.has(rev.id)"
              type="button"
              data-action="unpin"
              @click="emit('unpin', rev.id)"
            >
              Unpin
            </button>
            <button
              v-else
              type="button"
              data-action="pin"
              @click="emit('pin', rev.id)"
            >
              Pin
            </button>
            <button type="button" data-action="restore" @click="emit('restore', rev.id)">
              Restore
            </button>
          </li>
        </ul>
      </aside>
      <div class="vaulter-history-compare__panes">
        <div
          v-if="layout === 'side-by-side' && mode === 'compare'"
          ref="fromMountEl"
          class="vaulter-history-compare__surface vaulter-history-compare__surface--base"
          data-pane="base"
        />
        <div ref="mountEl" class="vaulter-history-compare__surface" data-pane="target" />
      </div>
    </div>
  </div>
</template>
