import { describe, expect, it } from 'vitest'
import { Extension, getSchema } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { TableKit } from '@tiptap/extension-table'
import { BlockId } from '@vaulter/vue'
import { computeStructuralDiff, textSimhash } from '../src/compare/structuralDiff'

const TableAlignExt = Extension.create({
  name: 'testTableCellAlign',
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

const schema = getSchema([
  StarterKit.configure({ link: { openOnClick: false } }),
  TableKit,
  BlockId,
  TableAlignExt,
])

describe('Two-stage structural diff (prosemirror-changeset + MarkAwareEncoder + attr_change)', () => {
  it.each(['list', 'table'])('reports mark-only edits at document positions inside a %s', (kind) => {
    const makeDoc = (bold: boolean) => {
      const paragraph = {
        type: 'paragraph',
        content: [{ type: 'text', text: 'target', marks: bold ? [{ type: 'bold' }] : [] }],
      }
      const container = kind === 'list'
        ? { type: 'bulletList', content: [{ type: 'listItem', content: [paragraph] }] }
        : { type: 'table', content: [{ type: 'tableRow', content: [{ type: 'tableCell', content: [paragraph] }] }] }
      return schema.nodeFromJSON({ type: 'doc', content: [{ type: 'paragraph' }, container] })
    }

    const before = makeDoc(false)
    const after = makeDoc(true)
    const { inlineSpans } = computeStructuralDiff(before, after)
    expect(inlineSpans).toHaveLength(1)
    const [span] = inlineSpans
    expect(span).toMatchObject({ kind: 'mark_change', text: 'target', marksAfter: [{ type: 'bold' }] })
    expect(before.textBetween(span.fromA, span.toA)).toBe('target')
    expect(after.textBetween(span.fromB, span.toB)).toBe('target')
  })

  it('aligns moved blocks by attrs.blockId and falls back to type + simhash LCS without blockId', () => {
    const docA = schema.nodeFromJSON({
      type: 'doc',
      content: [
        { type: 'paragraph', attrs: { blockId: 'blk_1' }, content: [{ type: 'text', text: 'Alpha paragraph' }] },
        { type: 'paragraph', attrs: { blockId: 'blk_2' }, content: [{ type: 'text', text: 'Beta paragraph' }] },
        { type: 'paragraph', content: [{ type: 'text', text: 'Legacy block without id' }] },
      ],
    })
    const docB = schema.nodeFromJSON({
      type: 'doc',
      content: [
        { type: 'paragraph', attrs: { blockId: 'blk_2' }, content: [{ type: 'text', text: 'Beta paragraph edited' }] },
        { type: 'paragraph', attrs: { blockId: 'blk_1' }, content: [{ type: 'text', text: 'Alpha paragraph' }] },
        { type: 'paragraph', content: [{ type: 'text', text: 'Legacy block without id!' }] },
      ],
    })

    const diff = computeStructuralDiff(docA, docB)
    expect(diff.hasChanges).toBe(true)

    const matchedBlk2 = diff.alignments.find((a) => a.blockId === 'blk_2')
    expect(matchedBlk2?.kind).toBe('matched')
    expect(matchedBlk2?.moved).toBe(true)

    const legacyMatch = diff.alignments.find((a) => a.blockId === null && a.kind === 'matched')
    expect(legacyMatch).toBeDefined()
    expect(diff.inlineSpans.some((s) => s.kind === 'insert' && s.text.includes('edited'))).toBe(true)
    expect(diff.inlineSpans.some((s) => s.kind === 'insert' && s.text.includes('!'))).toBe(true)
    expect(textSimhash('Alpha  Paragraph')).toBe(textSimhash('alpha paragraph'))
  })

  it('detects bold and link href mark changes via MarkAwareEncoder plus heading/table attr_change', () => {
    const docA = schema.nodeFromJSON({
      type: 'doc',
      content: [
        {
          type: 'heading',
          attrs: { blockId: 'blk_h1', level: 1 },
          content: [{ type: 'text', text: 'Title' }],
        },
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_p1' },
          content: [
            { type: 'text', text: 'Amount: 15' },
            { type: 'text', text: ' docs', marks: [{ type: 'link', attrs: { href: 'https://old.example' } }] },
          ],
        },
        {
          type: 'table',
          content: [
            {
              type: 'tableRow',
              content: [
                {
                  type: 'tableCell',
                  attrs: { colspan: 1, rowspan: 1, colwidth: [120], align: 'left' },
                  content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Cell' }] }],
                },
              ],
            },
          ],
        },
      ],
    })

    const docB = schema.nodeFromJSON({
      type: 'doc',
      content: [
        {
          type: 'heading',
          attrs: { blockId: 'blk_h1', level: 2 },
          content: [{ type: 'text', text: 'Title' }],
        },
        {
          type: 'paragraph',
          attrs: { blockId: 'blk_p1' },
          content: [
            { type: 'text', text: 'Amount: ' },
            { type: 'text', text: '15', marks: [{ type: 'bold' }] },
            { type: 'text', text: ' docs', marks: [{ type: 'link', attrs: { href: 'https://new.example' } }] },
          ],
        },
        {
          type: 'table',
          content: [
            {
              type: 'tableRow',
              content: [
                {
                  type: 'tableCell',
                  attrs: { colspan: 1, rowspan: 1, colwidth: [240], align: 'right' },
                  content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Cell' }] }],
                },
              ],
            },
          ],
        },
      ],
    })

    const diff = computeStructuralDiff(docA, docB)

    const markChanges = diff.inlineSpans.filter((s) => s.kind === 'mark_change')
    expect(markChanges.length).toBeGreaterThanOrEqual(2)
    expect(markChanges.some((s) => s.text === '15' && s.marksAfter.some((m) => m.type === 'bold'))).toBe(true)
    expect(
      markChanges.some(
        (s) =>
          s.text.includes('docs')
          && s.marksBefore.some((m) => m.type === 'link' && m.attrs.href === 'https://old.example')
          && s.marksAfter.some((m) => m.type === 'link' && m.attrs.href === 'https://new.example'),
      ),
    ).toBe(true)

    expect(diff.attrChanges.some((a) => a.nodeType === 'heading' && a.attr === 'level' && a.oldValue === 1 && a.newValue === 2)).toBe(true)
    expect(diff.attrChanges.some((a) => a.nodeType === 'tableCell' && a.attr === 'colwidth')).toBe(true)
    expect(diff.attrChanges.some((a) => a.nodeType === 'tableCell' && a.attr === 'align' && a.oldValue === 'left' && a.newValue === 'right')).toBe(true)
  })
})
