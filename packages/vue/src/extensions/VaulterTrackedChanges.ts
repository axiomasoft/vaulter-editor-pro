import { Extension, Mark } from '@tiptap/core'
import type { Editor } from '@tiptap/core'
import { isHistoryTransaction } from '@tiptap/pm/history'
import type { Node } from '@tiptap/pm/model'
import type { EditorState, Transaction } from '@tiptap/pm/state'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Mapping } from '@tiptap/pm/transform'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import { ySyncPluginKey, yUndoPluginKey } from '@tiptap/y-tiptap'
import type { Map as YMap, Transaction as YTransaction } from 'yjs'
import { trackStructure } from '../review/trackStructure'
import { trackText } from '../review/trackText'
import type { TextRun } from '../review/trackText'
import {
  findBlockPosById,
  getDocSnapshot,
  registerDocSnapshot,
  type TextSuggestionMetadata,
  type TrackedDocSnapshotState,
  type TrackedStructuralRecord,
} from '../review/structuralReverter'
import {
  REVIEW_DECISION_META,
  REVIEW_SUGGESTION_MARK,
  suggestionMarker,
  UNTRACKED_TRANSACTION,
  type TrackedStructuralSuggestion,
  type TrackedSuggestion,
  type TrackedTextSuggestion,
} from '../review/trackedProjection'
import { DEFAULT_REVIEW_CAPABILITIES, type SuggestionCapabilities } from '../types/review'

export interface TrackedChangesPluginState {
  structural: readonly TrackedStructuralRecord[]
  textMeta: Readonly<Record<string, TextSuggestionMetadata>>
}

export const REVIEW_TRACKED_PLUGIN_KEY = new PluginKey<TrackedChangesPluginState>('vaulterTrackedChanges')
export const REVIEW_STRUCTURE_META = 'vaulter:reviewStructureUpdate'

export interface TrackedChangesOptions {
  /** A stable local identity for grouping. The server determines persisted authorship. */
  author: () => string
  /** Enabled tracking requires the editor's text blocks to carry stable blockId attributes. */
  enabled: () => boolean
  /** Runtime capabilities constraining structural tracking & UI controls. */
  capabilities?: () => SuggestionCapabilities
  createId: () => string
  onSuggestions: (suggestions: readonly TrackedTextSuggestion[]) => void
  onStructuralSuggestions?: (suggestions: readonly TrackedStructuralSuggestion[]) => void
  onStateChange?: (state: TrackedDocSnapshotState) => void
  onConflict?: (conflict: { suggestion: TrackedSuggestion; conflictWithId: string; reason: string }) => void
  onUnsupportedEdit: (transaction: Transaction, reason?: string) => void
}

interface Composition {
  base: EditorState
  frame: number | null
}

interface TrackedChangesStorage {
  composition: Composition | null
  run: TextRun | null
}

const dispatchMeta = 'vaulter:compositionSuggestions'
const sharedStructureMeta = 'vaulter:sharedReviewStructure'
const passthrough = [ySyncPluginKey, yUndoPluginKey, 'y-undo', 'history', UNTRACKED_TRANSACTION] as const

interface SharedStructuralRecord {
  order: number
  record: TrackedStructuralRecord
}

function sharedRecords(state: EditorState): YMap<SharedStructuralRecord> | undefined {
  const sync = ySyncPluginKey.getState(state)
  if (!sync) return undefined
  const field = [...sync.doc.share].find(([, type]) => Object.is(type, sync.type))?.[0]
  return field === undefined ? undefined : sync.doc.getMap(`vaulter:reviewStructure:${field}`)
}

function readSharedRecords(records: YMap<SharedStructuralRecord>): TrackedStructuralRecord[] {
  return [...records.entries()]
    .filter(([id, item]) => Number.isSafeInteger(item?.order) && item.record?.suggestionId === id
      && typeof item.record.blockId === 'string' && typeof item.record.kind === 'string'
      && item.record.payload !== null && typeof item.record.payload === 'object')
    .sort(([aId, a], [bId, b]) => a.order - b.order || (aId < bId ? -1 : aId > bId ? 1 : 0))
    .map(([, item]) => item.record)
}

function dispatchWithSharedRecords(
  editor: Editor,
  transaction: Transaction,
  next: (transaction: Transaction) => void,
): void {
  const records = sharedRecords(editor.state)
  if (!records?.doc) {
    next(transaction)
    return
  }
  const before = REVIEW_TRACKED_PLUGIN_KEY.getState(editor.state)?.structural ?? []
  records.doc.transact(yTransaction => {
    yTransaction.meta.set('addToHistory', transaction.getMeta('addToHistory') !== false)
    next(transaction)
    const after = REVIEW_TRACKED_PLUGIN_KEY.getState(editor.state)?.structural ?? []
    const byId = new Map(after.map(record => [record.suggestionId, record]))
    for (const record of before) {
      if (!byId.has(record.suggestionId)) records.delete(record.suggestionId)
    }
    let order = 0
    for (const item of records.values()) {
      if (Number.isSafeInteger(item?.order)) order = Math.max(order, item.order)
    }
    for (const record of after) {
      const existing = records.get(record.suggestionId)
      if (existing?.record !== record) {
        records.set(record.suggestionId, { order: existing?.order ?? ++order, record })
      }
    }
  }, ySyncPluginKey)
}

const SuggestionMark = Mark.create({
  name: REVIEW_SUGGESTION_MARK,
  inclusive: false,
  excludes: '',
  keepOnSplit: false,

  addAttributes() {
    return {
      suggestionId: { default: null, rendered: false },
      kind: { default: null, rendered: false },
      parentId: { default: null, rendered: false },
      groupId: { default: null, rendered: false },
      author: { default: null, rendered: false },
      markName: { default: null, rendered: false },
      markAttrs: { default: null, rendered: false },
      operation: { default: null, rendered: false },
    }
  },

  parseHTML() {
    return ['ins', 'del'].map(tag => ({
      tag: `${tag}[data-suggestion-id]`,
      priority: 60,
      getAttrs: (element: HTMLElement) => ({
        suggestionId: element.getAttribute('data-suggestion-id'),
        kind: tag === 'ins' ? 'insert' : 'delete',
        parentId: element.getAttribute('data-suggestion-parent'),
      }),
    }))
  },

  renderHTML({ mark }) {
    const attrs: Record<string, string> = {
      'data-suggestion-id': String(mark.attrs.suggestionId ?? ''),
    }
    if (mark.attrs.parentId) {
      attrs['data-suggestion-parent'] = String(mark.attrs.parentId)
    }
    if (mark.attrs.kind === 'mark_change') {
      attrs['data-suggestion-kind'] = 'mark_change'
      if (mark.attrs.markName) attrs['data-suggestion-mark'] = String(mark.attrs.markName)
      return ['span', attrs, 0]
    }
    return [mark.attrs.kind === 'delete' ? 'del' : 'ins', attrs, 0]
  },
})

function locally(editor: Editor, update: () => void): void {
  const binding = ySyncPluginKey.getState(editor.state)?.binding
  if (binding) {
    // The sync plugin uses this same mutex for its outbound view updates.
    binding.mux(update, update)
  } else {
    update()
  }
}

function clearComposition(storage: TrackedChangesStorage): void {
  const frame = storage.composition?.frame
  if (frame != null) cancelAnimationFrame(frame)
  storage.composition = null
}

function splitsSurrogate(doc: Node, position: number): boolean {
  return /^[\uDC00-\uDFFF]/.test(doc.resolve(position).nodeAfter?.text ?? '')
}

function collectCurrentSuggestions(
  doc: Node,
  pluginState: TrackedChangesPluginState,
): TrackedSuggestion[] {
  const results: TrackedSuggestion[] = [...pluginState.structural]
  const seen = new Set<string>(pluginState.structural.map(s => s.suggestionId))

  // Collect open text suggestions anchored inside doc blocks using textMeta where available
  const textAgg = new Map<string, TrackedTextSuggestion>()
  doc.descendants(block => {
    if (!block.isTextblock || typeof block.attrs?.blockId !== 'string') return undefined
    const blockId = block.attrs.blockId
    let offset = 0
    block.forEach(node => {
      for (const mark of node.marks) {
        const marker = suggestionMarker(mark)
        if (!marker || marker.kind === 'mark_change' || seen.has(marker.suggestionId)) continue
        const meta = pluginState.textMeta[marker.suggestionId]
        const prev = textAgg.get(marker.suggestionId)
        const text = node.text ?? ''
        const end = offset + node.nodeSize
        if (!prev) {
          textAgg.set(marker.suggestionId, {
            suggestionId: marker.suggestionId,
            groupId: meta?.groupId ?? marker.groupId ?? marker.suggestionId,
            parentId: marker.parentId,
            kind: marker.kind,
            blockId,
            from: offset,
            to: marker.kind === 'insert' ? offset : end,
            before: marker.kind === 'delete' ? text : '',
            after: marker.kind === 'insert' ? text : '',
            author: meta?.author ?? marker.author ?? '',
            conflicted: meta?.conflicted ?? false,
            conflictReason: meta?.conflictReason ?? null,
            conflictWithId: meta?.conflictWithId ?? null,
          })
        } else {
          if (marker.kind === 'delete') {
            prev.to = end
            prev.before += text
            prev.kind = prev.after ? 'replace' : 'delete'
          } else {
            prev.after += text
            prev.kind = prev.before ? 'replace' : 'insert'
          }
        }
      }
      offset += node.nodeSize
    })
    return false
  })

  for (const s of textAgg.values()) {
    results.push(s)
  }
  return results
}

function buildDecorations(doc: Node, state: TrackedChangesPluginState): DecorationSet {
  const decorations: Decoration[] = []
  for (const rec of state.structural) {
    if (rec.kind === 'mark_change') continue
    const hit = findBlockPosById(doc, rec.blockId)
    if (!hit) continue
    decorations.push(
      Decoration.node(hit.pos, hit.pos + hit.node.nodeSize, {
        class: rec.conflicted ? 'vaulter-structural-suggestion is-conflicted' : 'vaulter-structural-suggestion',
        'data-structural-suggestion-id': rec.suggestionId,
        'data-structural-kind': rec.kind,
        ...(rec.conflicted ? { 'data-conflicted': 'true' } : {}),
      }),
    )
  }
  return DecorationSet.create(doc, decorations)
}

export const VaulterTrackedChanges = Extension.create<TrackedChangesOptions, TrackedChangesStorage>({
  name: 'vaulterTrackedChanges',
  priority: 1000,

  addOptions() {
    return {
      author: () => '',
      enabled: () => true,
      capabilities: () => DEFAULT_REVIEW_CAPABILITIES,
      createId: () => crypto.randomUUID(),
      onSuggestions: () => {},
      onStructuralSuggestions: undefined,
      onStateChange: undefined,
      onConflict: undefined,
      onUnsupportedEdit: () => {},
    }
  },

  addStorage() {
    return { composition: null, run: null }
  },

  addExtensions() {
    return [SuggestionMark]
  },

  dispatchTransaction({ transaction, next }) {
    const bypass = isHistoryTransaction(transaction) || passthrough.some(key => transaction.getMeta(key) !== undefined)
    const composing = this.storage.composition
    const currentPluginState = REVIEW_TRACKED_PLUGIN_KEY.getState(this.editor.state)
      ?? getDocSnapshot(this.editor.state.doc)
      ?? { structural: [], textMeta: {} }

    if (bypass) {
      if (transaction.docChanged) {
        this.storage.run = null
        // A remote replacement or history replay is authoritative over a local draft.
        clearComposition(this.storage)
      }
      if (composing && !transaction.docChanged) locally(this.editor, () => next(transaction))
      else if (transaction.getMeta(REVIEW_DECISION_META)) {
        dispatchWithSharedRecords(this.editor, transaction, next)
      } else next(transaction)

      const updatedPluginState = REVIEW_TRACKED_PLUGIN_KEY.getState(this.editor.state)
      if (updatedPluginState) {
        registerDocSnapshot(this.editor.state.doc, updatedPluginState)
        if (transaction.getMeta(REVIEW_DECISION_META) || transaction.getMeta(sharedStructureMeta) || transaction.docChanged) {
          this.options.onStateChange?.(updatedPluginState)
        }
      }
      const suggestions = transaction.getMeta(dispatchMeta) as TrackedTextSuggestion[] | undefined
      if (suggestions) this.options.onSuggestions(suggestions)
      return
    }

    if (composing) {
      locally(this.editor, () => next(transaction.setMeta('addToHistory', false)))
      return
    }
    if (!transaction.docChanged || !this.options.enabled()) {
      if (transaction.docChanged) this.storage.run = null
      next(transaction)
      return
    }

    const capabilities = this.options.capabilities?.() ?? DEFAULT_REVIEW_CAPABILITIES
    const existingSuggestions = collectCurrentSuggestions(this.editor.state.doc, currentPluginState)
    const author = this.options.author()

    const structuralOutcome = trackStructure(
      this.editor.state,
      transaction,
      author,
      this.options.createId,
      capabilities,
      currentPluginState.structural,
      existingSuggestions,
    )

    if (structuralOutcome.status === 'unsupported') {
      this.options.onUnsupportedEdit(transaction, structuralOutcome.reason)
      return
    }

    if (structuralOutcome.status === 'handled') {
      this.storage.run = null
      const res = structuralOutcome.result
      res.transaction.setMeta(REVIEW_STRUCTURE_META, {
        addedRecords: res.addedRecords,
        updatedRecords: res.updatedRecords,
        removedIds: res.removedIds,
      })
      dispatchWithSharedRecords(this.editor, res.transaction, next)

      const nextState = REVIEW_TRACKED_PLUGIN_KEY.getState(this.editor.state)
      if (nextState) {
        registerDocSnapshot(this.editor.state.doc, nextState)
        this.options.onStateChange?.(nextState)
      }
      if (res.added.length > 0) {
        this.options.onStructuralSuggestions?.(res.added)
        for (const s of res.added) {
          if (s.conflicted && s.conflictWithId) {
            this.options.onConflict?.({
              suggestion: s,
              conflictWithId: s.conflictWithId,
              reason: s.conflictReason ?? 'overlapping_suggestion',
            })
          }
        }
      }
      return
    }

    const result = trackText(
      this.editor.state,
      transaction,
      author,
      this.storage.run,
      this.options.createId,
      { existingSuggestions },
    )
    if (!result) {
      this.options.onUnsupportedEdit(transaction, 'unsupported_text_edit')
      return
    }

    this.storage.run = result.run
    const nextMeta: Record<string, TextSuggestionMetadata> = { ...currentPluginState.textMeta }
    for (const s of result.suggestions) {
      nextMeta[s.suggestionId] = {
        author,
        groupId: s.groupId,
        blockId: s.blockId,
        conflicted: s.conflicted,
        conflictReason: s.conflictReason,
        conflictWithId: s.conflictWithId,
      }
    }
    result.transaction.setMeta(REVIEW_STRUCTURE_META, { textMeta: nextMeta })
    next(result.transaction)

    const nextPluginState = REVIEW_TRACKED_PLUGIN_KEY.getState(this.editor.state)
    if (nextPluginState) {
      registerDocSnapshot(this.editor.state.doc, nextPluginState)
      this.options.onStateChange?.(nextPluginState)
    }
    this.options.onSuggestions(result.suggestions)
    for (const s of result.suggestions) {
      if (s.conflicted && s.conflictWithId) {
        this.options.onConflict?.({
          suggestion: s,
          conflictWithId: s.conflictWithId,
          reason: s.conflictReason ?? 'overlapping_suggestion',
        })
      }
    }
  },

  addProseMirrorPlugins() {
    return [new Plugin<TrackedChangesPluginState>({
      key: REVIEW_TRACKED_PLUGIN_KEY,
      state: {
        init: (_config, instance) => {
          const initial: TrackedChangesPluginState = { structural: [], textMeta: {} }
          registerDocSnapshot(instance.doc, initial)
          return initial
        },
        apply: (tr, prev, oldState) => {
          const decisionMeta = tr.getMeta(REVIEW_DECISION_META) as {
            structural: readonly TrackedStructuralRecord[]
            textMeta: Readonly<Record<string, TextSuggestionMetadata>>
          } | undefined
          if (decisionMeta) {
            const decided: TrackedChangesPluginState = {
              structural: decisionMeta.structural,
              textMeta: decisionMeta.textMeta,
            }
            registerDocSnapshot(tr.doc, decided)
            return decided
          }

          const structureMeta = tr.getMeta(REVIEW_STRUCTURE_META) as {
            addedRecords?: TrackedStructuralRecord[]
            updatedRecords?: TrackedStructuralRecord[]
            removedIds?: string[]
            textMeta?: Readonly<Record<string, TextSuggestionMetadata>>
          } | undefined

          const records = sharedRecords(oldState)
          let structural = records && (tr.getMeta(ySyncPluginKey) || tr.getMeta(sharedStructureMeta))
            ? readSharedRecords(records)
            : [...prev.structural]
          let textMeta = prev.textMeta

          if (structureMeta) {
            if (structureMeta.removedIds?.length) {
              const removed = new Set(structureMeta.removedIds)
              structural = structural.filter(r => !removed.has(r.suggestionId))
            }
            if (structureMeta.updatedRecords?.length) {
              const byId = new Map(structureMeta.updatedRecords.map(r => [r.suggestionId, r]))
              structural = structural.map(r => byId.get(r.suggestionId) ?? r)
            }
            if (structureMeta.addedRecords?.length) {
              structural.push(...structureMeta.addedRecords)
            }
            if (structureMeta.textMeta) {
              textMeta = structureMeta.textMeta
            }
          } else if (tr.docChanged) {
            // Preserve live doc snapshot state on text edits / history replay
            if (isHistoryTransaction(tr)) {
              // Filter out structural records whose block/marks are no longer present after undo
              structural = structural.filter(r => {
                if (r.kind === 'mark_change') {
                  let hasMark = false
                  tr.doc.descendants(n => {
                    if (n.marks.some(m => m.attrs?.suggestionId === r.suggestionId)) hasMark = true
                  })
                  return hasMark
                }
                return true
              })
            }
          }

          const nextState: TrackedChangesPluginState = { structural, textMeta }
          registerDocSnapshot(tr.doc, nextState)
          return nextState
        },
      },
      props: {
        decorations: state => {
          const pluginState = REVIEW_TRACKED_PLUGIN_KEY.getState(state)
          if (!pluginState || pluginState.structural.length === 0) return DecorationSet.empty
          return buildDecorations(state.doc, pluginState)
        },
        handleDOMEvents: {
          compositionstart: view => {
            if (this.options.enabled() && !this.storage.composition) {
              this.storage.composition = { base: view.state, frame: null }
            }
            return false
          },
          compositionend: view => {
            const composing = this.storage.composition
            if (!composing || composing.frame !== null) return false
            composing.frame = requestAnimationFrame(() => {
              if (view.isDestroyed || this.storage.composition !== composing) return
              this.storage.composition = null
              const raw = view.state
              const base = composing.base
              let start = base.doc.content.findDiffStart(raw.doc.content)
              if (start === null) return
              const end = base.doc.content.findDiffEnd(raw.doc.content)
              locally(this.editor, () => view.updateState(base))
              if (!end) return

              // A common suffix can overlap the prefix for repeated text.
              const overlap = Math.max(0, start - Math.min(end.a, end.b))
              if (splitsSurrogate(base.doc, start) || splitsSurrogate(raw.doc, start)) start--
              const baseEnd = end.a + overlap
              const rawEnd = end.b + overlap
              const widen = splitsSurrogate(base.doc, baseEnd) || splitsSurrogate(raw.doc, rawEnd) ? 1 : 0
              const diff = base.tr.replace(start, baseEnd + widen, raw.doc.slice(start, rawEnd + widen))
              diff.setSelection(raw.selection.map(diff.doc, new Mapping()))
              const basePluginState = REVIEW_TRACKED_PLUGIN_KEY.getState(base) ?? { structural: [], textMeta: {} }
              const result = trackText(
                base,
                diff,
                this.options.author(),
                this.storage.run,
                this.options.createId,
                { existingSuggestions: collectCurrentSuggestions(base.doc, basePluginState) },
              )
              if (!result) {
                this.options.onUnsupportedEdit(diff, 'unsupported_composition_edit')
                return
              }
              view.dispatch(result.transaction.setMeta(UNTRACKED_TRANSACTION, true).setMeta(dispatchMeta, result.suggestions))
              this.storage.run = result.run
            })
            return false
          },
        },
      },
      view: view => {
        const records = sharedRecords(view.state)
        const ydoc = records?.doc
        if (records) yUndoPluginKey.getState(view.state)?.undoManager.addToScope(records)
        const refresh = () => {
          if (!records || view.isDestroyed) return
          const next = readSharedRecords(records)
          const previous = REVIEW_TRACKED_PLUGIN_KEY.getState(view.state)?.structural ?? []
          if (next.length === previous.length && next.every((item, index) => item === previous[index])) return
          view.dispatch(view.state.tr
            .setMeta(sharedStructureMeta, true)
            .setMeta(UNTRACKED_TRANSACTION, true)
            .setMeta('addToHistory', false))
        }
        const afterTransaction = (transaction: YTransaction) => {
          if (records && [...transaction.changed.keys()].some(type => Object.is(type, records))) refresh()
        }
        ydoc?.on('afterTransaction', afterTransaction)
        queueMicrotask(refresh)
        return {
          destroy: () => {
            ydoc?.off('afterTransaction', afterTransaction)
            clearComposition(this.storage)
          },
        }
      },
    })]
  },
})
