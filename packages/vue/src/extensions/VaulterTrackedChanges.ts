import { Extension, Mark } from '@tiptap/core'
import type { Editor } from '@tiptap/core'
import type { Node } from '@tiptap/pm/model'
import type { EditorState, Transaction } from '@tiptap/pm/state'
import { Plugin } from '@tiptap/pm/state'
import { Mapping } from '@tiptap/pm/transform'
import { ySyncPluginKey, yUndoPluginKey } from '@tiptap/y-tiptap'
import { trackText } from '../review/trackText'
import type { TextRun } from '../review/trackText'
import { REVIEW_SUGGESTION_MARK, UNTRACKED_TRANSACTION } from '../review/trackedProjection'
import type { TrackedTextSuggestion } from '../review/trackedProjection'

export interface TrackedChangesOptions {
  /** A stable local identity for grouping. The server determines persisted authorship. */
  author: () => string
  /** Enabled tracking requires the editor's text blocks to carry stable blockId attributes. */
  enabled: () => boolean
  createId: () => string
  onSuggestions: (suggestions: readonly TrackedTextSuggestion[]) => void
  onUnsupportedEdit: (transaction: Transaction) => void
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
const passthrough = [ySyncPluginKey, yUndoPluginKey, 'y-undo', 'history', UNTRACKED_TRANSACTION] as const

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
    return [
      mark.attrs.kind === 'delete' ? 'del' : 'ins',
      {
        'data-suggestion-id': mark.attrs.suggestionId,
        ...(mark.attrs.parentId ? { 'data-suggestion-parent': mark.attrs.parentId } : {}),
      },
      0,
    ]
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

export const VaulterTrackedChanges = Extension.create<TrackedChangesOptions, TrackedChangesStorage>({
  name: 'vaulterTrackedChanges',
  priority: 1000,

  addOptions() {
    return {
      author: () => '',
      enabled: () => true,
      createId: () => crypto.randomUUID(),
      onSuggestions: () => {},
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
    const bypass = passthrough.some(key => transaction.getMeta(key) !== undefined)
    const composing = this.storage.composition
    if (bypass) {
      if (transaction.docChanged) {
        this.storage.run = null
        // A remote replacement or history replay is authoritative over a local draft.
        clearComposition(this.storage)
      }
      if (composing && !transaction.docChanged) locally(this.editor, () => next(transaction))
      else next(transaction)
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
    const result = trackText(this.editor.state, transaction, this.options.author(), this.storage.run, this.options.createId)
    if (!result) {
      this.options.onUnsupportedEdit(transaction)
      return
    }
    this.storage.run = result.run
    next(result.transaction)
    this.options.onSuggestions(result.suggestions)
  },

  addProseMirrorPlugins() {
    return [new Plugin({
      props: {
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
              const result = trackText(base, diff, this.options.author(), this.storage.run, this.options.createId)
              if (!result) {
                this.options.onUnsupportedEdit(diff)
                return
              }
              view.dispatch(result.transaction.setMeta(UNTRACKED_TRANSACTION, true).setMeta(dispatchMeta, result.suggestions))
              this.storage.run = result.run
            })
            return false
          },
        },
      },
      view: () => ({ destroy: () => clearComposition(this.storage) }),
    })]
  },
})
