import { Extension } from '@tiptap/core'
import { Plugin, PluginKey } from '@tiptap/pm/state'
import { Decoration, DecorationSet } from '@tiptap/pm/view'

export interface ActiveRangeDecoration {
  commentId: string
  from: number
  to: number
  resolved: boolean
  active?: boolean
}

export interface RangeAnchorHighlightOptions {
  ranges: () => ActiveRangeDecoration[]
}

export const RANGE_ANCHOR_PLUGIN_KEY = new PluginKey('vaulterRangeAnchorHighlight')

/**
 * Read-only decoration renderer highlighting resolved coordinates of active/selected comment ranges.
 */
export const RangeAnchorHighlight = Extension.create<RangeAnchorHighlightOptions>({
  name: 'vaulterRangeAnchorHighlight',

  addOptions() {
    return {
      ranges: () => [],
    }
  },

  addProseMirrorPlugins() {
    const getRanges = this.options.ranges

    return [
      new Plugin({
        key: RANGE_ANCHOR_PLUGIN_KEY,
        props: {
          decorations(state) {
            const size = state.doc.content.size
            const decorations: Decoration[] = []
            for (const item of getRanges()) {
              const from = Math.max(0, Math.min(size, item.from))
              const to = Math.max(from, Math.min(size, item.to))
              if (to <= from) {
                continue
              }
              decorations.push(
                Decoration.inline(from, to, {
                  class: item.active ? 'vaulter-comment-range is-active' : 'vaulter-comment-range',
                  'data-comment-id': item.commentId,
                  'data-resolved': String(item.resolved),
                }),
              )
            }
            return DecorationSet.create(state.doc, decorations)
          },
        },
      }),
    ]
  },
})
