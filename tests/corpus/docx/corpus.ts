import type {
  DocxDiscussionInput,
  DocxSectionInput,
  SourceMarker,
  TipTapDoc,
} from '../../../packages/docx/src/model/types'

export interface CorpusFixtureSpec {
  id: 'D1' | 'D2' | 'D3' | 'D4'
  title: string
  document: TipTapDoc
  sections?: DocxSectionInput[]
  discussions?: DocxDiscussionInput[]
  sourceMarker: SourceMarker
}

const text = (
  value: string,
  marks?: Array<{ type: string; attrs?: Record<string, unknown> }>,
) => ({
  type: 'text',
  text: value,
  ...(marks ? { marks } : {}),
})

const paragraph = (
  value: string,
  attrs?: Record<string, unknown>,
  marks?: Array<{ type: string; attrs?: Record<string, unknown> }>,
) => ({
  type: 'paragraph',
  ...(attrs ? { attrs } : {}),
  content: [text(value, marks)],
})

/**
 * D1: Body + Table with column widths, header row (w:tblHeader),
 * 2x2 merge (colspan=2, rowspan=2), bold and hyperlink inside merged cell,
 * heading, lists (bullet & ordered start=3), spacing/indent, and hardBreak.
 */
export const CORPUS_D1: CorpusFixtureSpec = {
  id: 'D1',
  title: 'Body and Table 2x2 Merge',
  sourceMarker: {
    nodeId: 'corpus-d1',
    generation: 1,
    position: 10,
    schemaVersion: 2,
    contentHash: 'corpus-d1-v1',
  },
  document: {
    type: 'doc',
    content: [
      {
        type: 'heading',
        attrs: { blockId: 'd1_h1', level: 2 },
        content: [text('D1 Contract Schedule')],
      },
      {
        type: 'paragraph',
        attrs: {
          blockId: 'd1_p1',
          textAlign: 'center',
          indent: 24,
          spacingBefore: 12,
          spacingAfter: 6,
        },
        content: [
          text('Plain clause '),
          text('Bold clause', [{ type: 'bold' }]),
          text(' and '),
          text('External ref', [
            { type: 'link', attrs: { href: 'https://example.test/d1-spec' } },
          ]),
        ],
      },
      {
        type: 'bulletList',
        content: [
          { type: 'listItem', content: [paragraph('Bullet item one', { blockId: 'd1_b1' })] },
          { type: 'listItem', content: [paragraph('Bullet item two', { blockId: 'd1_b2' })] },
        ],
      },
      {
        type: 'orderedList',
        attrs: { start: 3 },
        content: [
          { type: 'listItem', content: [paragraph('Ordered start three', { blockId: 'd1_o1' })] },
          { type: 'listItem', content: [paragraph('Ordered four', { blockId: 'd1_o2' })] },
        ],
      },
      {
        type: 'table',
        attrs: {
          blockId: 'd1_tbl',
          width: 360,
          gridColsDxa: [1800, 1800, 1800],
        },
        content: [
          {
            type: 'tableRow',
            attrs: { isHeader: true, height: 24, heightRule: 'atLeast' },
            content: [
              {
                type: 'tableHeader',
                attrs: {
                  colspan: 2,
                  rowspan: 2,
                  colwidth: [120, 120],
                  background: '#ddeeff',
                  verticalAlign: 'middle',
                },
                content: [
                  {
                    type: 'paragraph',
                    attrs: { blockId: 'd1_merged' },
                    content: [
                      text('Bold merged cell ', [{ type: 'bold' }]),
                      text('Clause link', [
                        { type: 'bold' },
                        { type: 'link', attrs: { href: 'https://example.test/merged-cell' } },
                      ]),
                    ],
                  },
                ],
              },
              {
                type: 'tableHeader',
                attrs: { colspan: 1, rowspan: 1, colwidth: [120] },
                content: [paragraph('Row 1 side cell', { blockId: 'd1_r1c3' })],
              },
            ],
          },
          {
            type: 'tableRow',
            content: [
              {
                type: 'tableCell',
                attrs: { colspan: 1, rowspan: 1, colwidth: [120] },
                content: [paragraph('Row 2 side cell', { blockId: 'd1_r2c3' })],
              },
            ],
          },
        ],
      },
      {
        type: 'paragraph',
        attrs: { blockId: 'd1_p2' },
        content: [text('Line one'), { type: 'hardBreak' }, text('Line two')],
      },
    ],
  },
}

/**
 * D2: Two sections with distinct default/first/even headers & footers,
 * footer inheritance into Section 2, titlePg, evenAndOddHeaders, and explicit pageBreak.
 */
export const CORPUS_D2: CorpusFixtureSpec = {
  id: 'D2',
  title: 'Multi-Section Headers Footers Inheritance and Page Break',
  sourceMarker: {
    nodeId: 'corpus-d2',
    generation: 1,
    position: 20,
    schemaVersion: 2,
    contentHash: 'corpus-d2-v1',
  },
  document: {
    type: 'doc',
    content: [
      paragraph('Section one body paragraph', { blockId: 'd2_s1_p1' }),
      { type: 'sectionBreak', attrs: { id: 'sec_1' } },
      paragraph('Section two opening paragraph', { blockId: 'd2_s2_p1' }),
      { type: 'pageBreak' },
      paragraph('Section two after explicit page break', { blockId: 'd2_s2_p2' }),
    ],
  },
  sections: [
    {
      id: 'sec_1',
      type: 'nextPage',
      titlePg: false,
      headers: {
        default: 'Section 1 default header',
        even: 'Section 1 even header',
      },
      footers: {
        default: 'Shared default footer',
      },
    },
    {
      id: 'sec_2',
      type: 'nextPage',
      titlePg: true,
      headers: {
        default: 'Section 2 default header',
        first: 'Section 2 first header',
      },
      footers: {
        // default footer omitted intentionally -> inherits "Shared default footer" from section 1!
      },
    },
  ],
}

/**
 * D3: Discussions with range anchor, replies, resolved status, detached anchor,
 * and compatibility with legacy flat comment profile.
 */
export const CORPUS_D3: CorpusFixtureSpec = {
  id: 'D3',
  title: 'Threaded Range Comments and Detached Discussion',
  sourceMarker: {
    nodeId: 'corpus-d3',
    generation: 2,
    position: 30,
    schemaVersion: 2,
    contentHash: 'corpus-d3-v1',
  },
  document: {
    type: 'doc',
    content: [
      {
        type: 'paragraph',
        attrs: { blockId: 'd3_p1' },
        content: [
          text('Clause prefix '),
          text('Annotated clause', [
            { type: 'inlineThread', attrs: { 'data-thread-id': 'thread-resolved' } },
          ]),
          text(' suffix.'),
        ],
      },
      {
        type: 'paragraph',
        attrs: { blockId: 'd3_p2' },
        content: [
          text('Active range ', []),
          text('review target', [
            { type: 'inlineThread', attrs: { 'data-thread-id': 'thread-open' } },
          ]),
          text('.'),
        ],
      },
    ],
  },
  discussions: [
    {
      id: 'thread-resolved',
      anchorState: 'attached',
      quoteText: 'Annotated clause',
      blockId: 'd3_p1',
      sourcePosition: 14,
      generation: 2,
      resolved: true,
      comments: [
        {
          id: 'c-root-1',
          author: 'Reviewer One',
          initials: 'RO',
          createdAt: '2026-11-21T09:00:00Z',
          body: 'Root comment on clause',
          originalWId: 7,
          originalDurableId: '000000AF',
        },
        {
          id: 'c-reply-1',
          parentId: 'c-root-1',
          author: 'Editor Two',
          initials: 'ET',
          createdAt: '2026-11-21T09:05:00Z',
          body: 'Reply confirming resolution',
          originalWId: 8,
          originalDurableId: '000000BF',
        },
      ],
    },
    {
      id: 'thread-open',
      anchorState: 'attached',
      quoteText: 'review target',
      blockId: 'd3_p2',
      sourcePosition: 13,
      generation: 2,
      resolved: false,
      comments: [
        {
          id: 'c-root-2',
          author: 'Reviewer One',
          createdAt: '2026-11-21T09:10:00Z',
          body: 'Open thread awaiting edit',
        },
      ],
    },
    {
      id: 'thread-detached',
      anchorState: 'detached',
      quoteText: 'Removed target phrase',
      blockId: 'd3_deleted_blk',
      sourcePosition: 4,
      generation: 1,
      resolved: false,
      comments: [
        {
          id: 'c-detached-root',
          author: 'Reviewer One',
          createdAt: '2026-11-21T08:30:00Z',
          body: 'Detached discussion preserved after deletion',
        },
        {
          id: 'c-detached-reply',
          parentId: 'c-detached-root',
          author: 'Editor Two',
          createdAt: '2026-11-21T08:35:00Z',
          body: 'Reply preserved on detached thread',
        },
      ],
    },
  ],
}

/**
 * D4: Tracked revisions (insert, delete with xml:space="preserve", paired replacement with groupId,
 * block_join deletion in pPr/rPr, and native formatting revision example for LossReport).
 */
export const CORPUS_D4: CorpusFixtureSpec = {
  id: 'D4',
  title: 'Tracked Revisions Replacement Group and Formatting Loss Report',
  sourceMarker: {
    nodeId: 'corpus-d4',
    generation: 3,
    position: 42,
    schemaVersion: 2,
    contentHash: 'corpus-d4-v1',
  },
  document: {
    type: 'doc',
    content: [
      {
        type: 'paragraph',
        attrs: { blockId: 'd4_p42' },
        content: [
          text('Payment term '),
          text('15', [
            {
              type: 'suggestion',
              attrs: {
                id: 'rev-del-15',
                type: 'replaceDeletion',
                groupId: 'grp-term-42',
                userId: 'reviewer-1',
                createdAt: '2026-11-21T10:00:00Z',
                userMetadata: { name: 'Synthetic Reviewer' },
              },
            },
          ]),
          text('30', [
            {
              type: 'suggestion',
              attrs: {
                id: 'rev-ins-30',
                type: 'replaceInsertion',
                groupId: 'grp-term-42',
                userId: 'reviewer-1',
                createdAt: '2026-11-21T10:00:00Z',
                userMetadata: { name: 'Synthetic Reviewer' },
              },
            },
          ]),
          text(' days '),
          text(' only ', [
            {
              type: 'suggestion',
              attrs: {
                id: 'rev-del-spaces',
                type: 'delete',
                userId: 'reviewer-1',
                createdAt: '2026-11-21T10:01:00Z',
                userMetadata: { name: 'Synthetic Reviewer' },
              },
            },
          ]),
          text('added clause', [
            {
              type: 'suggestion',
              attrs: {
                id: 'rev-ins-clause',
                type: 'add',
                userId: 'editor-2',
                createdAt: '2026-11-21T10:02:00Z',
                userMetadata: { name: 'Synthetic Editor' },
              },
            },
          ]),
        ],
      },
      {
        type: 'paragraph',
        attrs: {
          blockId: 'd4_p43',
          blockJoinDeletion: {
            id: 'rev-join-1',
            author: 'Synthetic Reviewer',
            date: '2026-11-21T10:03:00Z',
          },
        },
        content: [
          text('Bold formatting proposal', [
            { type: 'bold' },
            {
              type: 'suggestion',
              attrs: {
                id: 'rev-fmt-bold',
                type: 'markChange',
                userId: 'editor-2',
                createdAt: '2026-11-21T10:04:00Z',
                userMetadata: { name: 'Synthetic Editor' },
                markChanges: [{ operation: 'added', markName: 'bold', markAttrs: {} }],
              },
            },
          ]),
        ],
      },
    ],
  },
}

export const ALL_DOCX_CORPUS_FIXTURES: readonly CorpusFixtureSpec[] = [
  CORPUS_D1,
  CORPUS_D2,
  CORPUS_D3,
  CORPUS_D4,
]
