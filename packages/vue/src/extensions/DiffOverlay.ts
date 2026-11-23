import { Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'
import type { AttrChangeEntry, InlineDiffSpan, StructuralDiffResult } from '../compare/structuralDiff'

export interface DiffOverlayOptions {
  diff: StructuralDiffResult | null
}

const DIFF_OVERLAY_KEY = new PluginKey('vaulterDiffOverlay')

function formatAttrValue(val: unknown): string {
  if (val === null || val === undefined) {
    return 'none'
  }
  if (Array.isArray(val) || typeof val === 'object') {
    return JSON.stringify(val)
  }
  return String(val)
}

function buildDecorations(docSize: number, doc: Parameters<typeof DecorationSet.create>[0], diff: StructuralDiffResult | null): DecorationSet {
  if (!diff || !diff.hasChanges) {
    return DecorationSet.empty
  }

  const decorations: Decoration[] = []

  for (const span of diff.inlineSpans as InlineDiffSpan[]) {
    if (span.kind === 'insert') {
      const from = Math.max(0, Math.min(docSize, span.fromB))
      const to = Math.max(from, Math.min(docSize, span.toB))
      if (to > from) {
        const markSummary = span.marksAfter.map((m) => m.type).join(',')
        decorations.push(
          Decoration.inline(from, to, {
            class: 'vaulter-diff-insert',
            'data-diff-kind': 'insert',
            ...(markSummary ? { 'data-diff-marks': markSummary } : {}),
          }),
        )
      }
    } else if (span.kind === 'mark_change') {
      const from = Math.max(0, Math.min(docSize, span.fromB))
      const to = Math.max(from, Math.min(docSize, span.toB))
      if (to > from) {
        decorations.push(
          Decoration.inline(from, to, {
            class: 'vaulter-diff-mark-change',
            'data-diff-kind': 'mark_change',
            'data-marks-before': JSON.stringify(span.marksBefore),
            'data-marks-after': JSON.stringify(span.marksAfter),
          }),
        )
      }
    } else if (span.kind === 'delete' && span.text.length > 0) {
      const anchorPos = Math.max(0, Math.min(docSize, span.fromB))
      decorations.push(
        Decoration.widget(
          anchorPos,
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

  for (const attrChange of diff.attrChanges as AttrChangeEntry[]) {
    const pos = Math.max(0, Math.min(docSize, attrChange.fromB))
    decorations.push(
      Decoration.widget(
        pos,
        () => {
          const badge = document.createElement('span')
          badge.className = 'vaulter-diff-attr-badge'
          badge.dataset.diffKind = 'attr_change'
          badge.dataset.attr = attrChange.attr
          badge.textContent = `${attrChange.nodeType}.${attrChange.attr}: ${formatAttrValue(attrChange.oldValue)} -> ${formatAttrValue(attrChange.newValue)}`
          return badge
        },
        { side: -1 },
      ),
    )
  }

  return DecorationSet.create(doc, decorations)
}

/**
 * Read-only decoration overlay for two-stage structural diff inside an isolated `EditorView`.
 * Never mutates the underlying ProseMirror document or dispatches live updates.
 */
export const DiffOverlay = Extension.create<DiffOverlayOptions>({
  name: 'vaulterDiffOverlay',

  addOptions() {
    return {
      diff: null,
    }
  },

  addProseMirrorPlugins() {
    const getDiff = (): StructuralDiffResult | null => this.options.diff

    return [
      new Plugin({
        key: DIFF_OVERLAY_KEY,
        props: {
          decorations(state) {
            return buildDecorations(state.doc.content.size, state.doc, getDiff())
          },
        },
      }),
    ]
  },
})
