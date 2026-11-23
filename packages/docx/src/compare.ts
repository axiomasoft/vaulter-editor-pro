import type {
  IntermediateBlock,
  IntermediateDocument,
  IntermediateInline,
  LossReport,
} from './model/types'
import { resolveEffectiveSectionHeadersFooters } from './ooxml/importer'

export interface SemanticTableSummary {
  rows: number
  cols: number
  gridColsDxa: number[]
  headerRows: number
  cells: Array<{
    text: string
    colspan: number
    rowspan: number
    bold: boolean
    links: string[]
    backgroundHex: string | null
    verticalAlign: string | null
  }>
}

export interface SemanticReport {
  sections: Array<{
    id: string
    firstPageVariant: boolean
    explicitHeadersFooters: Record<string, string | undefined>
    effectiveHeadersFooters: Record<string, string | undefined>
  }>
  explicitPageBreaks: number
  tables: SemanticTableSummary[]
  paragraphs: string[]
  headings: Array<{ level: number; text: string }>
  lists: Array<{ kind: 'bullet' | 'ordered'; start: number; text: string }>
  discussions: Array<{
    id: string
    anchorState: string
    resolved: boolean
    comments: Array<{
      author: string
      text: string
      isReply: boolean
    }>
  }>
  revisions: {
    insertions: Array<{ text: string; author: string; groupId: string | null }>
    deletions: Array<{ text: string; author: string; groupId: string | null }>
    replacementGroups: Array<{ groupId: string; deletionRevisionId: string; insertionRevisionId: string }>
    formattingRevisions: number
    blockJoinDeletions: number
  }
  lossReport?: {
    profile: string
    blocked: boolean
    diagnosticCodes: string[]
  }
}

function inlinePlainText(inlines: readonly IntermediateInline[]): string {
  return inlines
    .map((i) => (i.type === 'text' ? i.text : i.type === 'hardBreak' ? '\n' : ''))
    .join('')
}

export function extractSemanticSummary(
  model: IntermediateDocument,
  report?: LossReport,
): SemanticReport {
  const effectiveSections = resolveEffectiveSectionHeadersFooters(model.sections)
  const sections = effectiveSections.map((s) => ({
    id: s.sectionId,
    firstPageVariant: s.firstPageVariant,
    explicitHeadersFooters: { ...s.explicit },
    effectiveHeadersFooters: { ...s.effective },
  }))

  let explicitPageBreaks = 0
  const tables: SemanticTableSummary[] = []
  const paragraphs: string[] = []
  const headings: Array<{ level: number; text: string }> = []
  const lists: Array<{ kind: 'bullet' | 'ordered'; start: number; text: string }> = []
  const insertions: Array<{ text: string; author: string; groupId: string | null }> = []
  const deletions: Array<{ text: string; author: string; groupId: string | null }> = []
  let blockJoinDeletions = 0

  const scanInlines = (inlines: readonly IntermediateInline[]) => {
    for (const inline of inlines) {
      if (inline.type === 'text' && inline.revision) {
        const entry = {
          text: inline.text,
          author: inline.revision.author,
          groupId: inline.revision.groupId ?? null,
        }
        if (inline.revision.kind === 'insert') insertions.push(entry)
        else deletions.push(entry)
      }
    }
  }

  const visitBlocks = (blocks: readonly IntermediateBlock[]) => {
    for (const block of blocks) {
      if (block.type === 'pageBreak') {
        explicitPageBreaks += 1
      } else if (block.type === 'paragraph') {
        if (block.attrs.blockJoinDeletion) {
          blockJoinDeletions += 1
        }
        scanInlines(block.inlines)
        const text = inlinePlainText(block.inlines)
        if (block.attrs.list) {
          lists.push({
            kind: block.attrs.list.kind,
            start: block.attrs.list.start ?? 1,
            text,
          })
        } else {
          paragraphs.push(text)
        }
      } else if (block.type === 'heading') {
        if (block.attrs.blockJoinDeletion) {
          blockJoinDeletions += 1
        }
        scanInlines(block.inlines)
        headings.push({
          level: block.level,
          text: inlinePlainText(block.inlines),
        })
      } else if (block.type === 'table') {
        const headerRows = block.rows.filter((r) => r.isHeader).length
        const cells: SemanticTableSummary['cells'] = []
        for (const row of block.rows) {
          for (const cell of row.cells) {
            let text = ''
            let bold = false
            const links: string[] = []
            for (const b of cell.blocks) {
              if (b.type === 'paragraph' || b.type === 'heading') {
                text += inlinePlainText(b.inlines)
                for (const inl of b.inlines) {
                  if (inl.type === 'text') {
                    if (inl.marks.some((m) => m.type === 'bold')) bold = true
                    for (const m of inl.marks) {
                      if (m.type === 'link') links.push(m.href)
                    }
                  }
                }
              }
            }
            cells.push({
              text,
              colspan: cell.colspan,
              rowspan: cell.rowspan,
              bold,
              links,
              backgroundHex: cell.backgroundHex ?? null,
              verticalAlign: cell.verticalAlign ?? null,
            })
          }
        }
        tables.push({
          rows: block.rows.length,
          cols: block.gridColsDxa.length,
          gridColsDxa: block.gridColsDxa,
          headerRows,
          cells,
        })
      } else if (block.type === 'blockquote') {
        visitBlocks(block.blocks)
      }
    }
  }

  for (const sec of model.sections) {
    visitBlocks(sec.blocks)
  }

  const discussions = model.discussions.map((d) => ({
    id: d.id,
    anchorState: d.anchorState,
    resolved: d.resolved,
    comments: d.comments.map((c, idx) => ({
      author: c.author,
      text: c.bodyParagraphs.join('\n'),
      isReply: idx > 0 || Boolean(c.parentId),
    })),
  }))

  return {
    sections,
    explicitPageBreaks,
    tables,
    paragraphs,
    headings,
    lists,
    discussions,
    revisions: {
      insertions,
      deletions,
      replacementGroups: model.replacementGroups.map((g) => ({
        groupId: g.groupId,
        deletionRevisionId: g.deletionRevisionId,
        insertionRevisionId: g.insertionRevisionId,
      })),
      formattingRevisions: model.formattingRevisions.length,
      blockJoinDeletions,
    },
    ...(report
      ? {
          lossReport: {
            profile: report.profile,
            blocked: report.blocked,
            diagnosticCodes: report.diagnostics.map((d) => d.code),
          },
        }
      : {}),
  }
}

export function compareSemanticReports(a: SemanticReport, b: SemanticReport): {
  equal: boolean
  differences: string[]
} {
  const differences: string[] = []
  const keys: Array<keyof SemanticReport> = [
    'sections',
    'explicitPageBreaks',
    'tables',
    'paragraphs',
    'headings',
    'lists',
    'discussions',
    'revisions',
    'lossReport',
  ]
  for (const key of keys) {
    const left = JSON.stringify(a[key] ?? null)
    const right = JSON.stringify(b[key] ?? null)
    if (left !== right) {
      differences.push(`${key} differs`)
    }
  }
  return {
    equal: differences.length === 0,
    differences,
  }
}
