import {
  AlignmentType,
  CommentRangeEnd,
  CommentRangeStart,
  CommentReference,
  Document,
  ExternalHyperlink,
  Footer,
  Header,
  HeadingLevel,
  InsertedTextRun,
  DeletedTextRun,
  LevelFormat,
  Packer,
  PageBreak,
  Paragraph,
  ShadingType,
  Table,
  TableCell,
  TableRow,
  TextRun,
  UnderlineType as DocxUnderlineType,
  VerticalAlign,
  WidthType,
  type ICommentOptions,
  type IParagraphOptions,
  type IRunOptions,
  type ISectionOptions,
  type ParagraphChild,
} from 'docx'
import { fflateUnzipRaw, fflateZipRaw } from './zipHelpers'
import {
  VAULTER_DOCX_CUSTOM_XML_NS,
  type DocxProfileId,
  type HeaderFooterContent,
  type IntermediateBlock,
  type IntermediateDiscussion,
  type IntermediateDocument,
  type IntermediateInline,
  type IntermediateSection,
  type ParagraphAttrs,
  type TextAlignment,
} from '../model/types'
import { getProfileCapabilities, type ProfileFeatureOptions } from '../model/profiles'
import { LossReportBuilder } from '../model/lossReport'
import { OoxmlIdAllocator } from './ids'
import { isSafeHyperlinkUrl, lineHeightToOoxml } from './units'
import { decodeUtf8, encodeUtf8, escapeXml, findChild, findChildren, getAttr, parseXmlPart } from './xmlParser'

export interface ExportOoxmlOptions extends ProfileFeatureOptions {
  profile?: DocxProfileId
}

interface AllocatedCommentMeta {
  discussionId: string
  commentId: string
  wId: number
  parentWId?: number
  paraId: string
  parentParaId?: string
  durableId: string
  resolved: boolean
  author: string
  initials?: string | null
  date: Date
  bodyParagraphs: string[]
  anchorState: 'attached' | 'detached' | 'historical'
  quoteText?: string | null
  blockId?: string | null
}

function mapAlignment(align: TextAlignment | null | undefined): (typeof AlignmentType)[keyof typeof AlignmentType] | undefined {
  switch (align) {
    case 'left':
      return AlignmentType.LEFT
    case 'center':
      return AlignmentType.CENTER
    case 'right':
      return AlignmentType.RIGHT
    case 'both':
    case 'justify':
      return AlignmentType.BOTH
    default:
      return undefined
  }
}

function mapHeadingLevel(level: number): (typeof HeadingLevel)[keyof typeof HeadingLevel] {
  switch (level) {
    case 1:
      return HeadingLevel.HEADING_1
    case 2:
      return HeadingLevel.HEADING_2
    case 3:
      return HeadingLevel.HEADING_3
    case 4:
      return HeadingLevel.HEADING_4
    case 5:
      return HeadingLevel.HEADING_5
    default:
      return HeadingLevel.HEADING_6
  }
}

function mapUnderline(type: string | null | undefined): (typeof DocxUnderlineType)[keyof typeof DocxUnderlineType] {
  switch (type) {
    case 'double':
      return DocxUnderlineType.DOUBLE
    case 'thick':
      return DocxUnderlineType.THICK
    case 'dotted':
      return DocxUnderlineType.DOTTED
    case 'dash':
      return DocxUnderlineType.DASH
    case 'wave':
      return DocxUnderlineType.WAVE
    case 'none':
      return DocxUnderlineType.NONE
    default:
      return DocxUnderlineType.SINGLE
  }
}

function buildRunStyleOptions(inline: Extract<IntermediateInline, { type: 'text' }>): IRunOptions {
  const opts: Record<string, unknown> = {
    text: inline.text,
  }

  for (const mark of inline.marks) {
    switch (mark.type) {
      case 'bold':
        opts.bold = true
        break
      case 'italic':
        opts.italics = true
        break
      case 'underline':
        opts.underline = { type: mapUnderline(mark.underlineType) }
        break
      case 'strike':
        opts.strike = true
        break
      case 'code':
        opts.font = 'Courier New'
        break
      case 'superscript':
        opts.superScript = true
        break
      case 'subscript':
        opts.subScript = true
        break
      case 'highlight':
        opts.shading = { fill: mark.colorHex, type: ShadingType.CLEAR, color: 'auto' }
        break
      case 'textStyle':
        if (mark.fontFamily) opts.font = mark.fontFamily
        if (mark.fontSizeHalfPt) opts.size = mark.fontSizeHalfPt
        if (mark.colorHex) opts.color = mark.colorHex
        if (mark.backgroundColorHex) {
          opts.shading = { fill: mark.backgroundColorHex, type: ShadingType.CLEAR, color: 'auto' }
        }
        break
      case 'link':
        // Link wrapper handles hyperlink element; style link visually when not overridden.
        if (!opts.color) opts.color = '0563C1'
        if (!opts.underline) opts.underline = { type: DocxUnderlineType.SINGLE }
        break
    }
  }

  return opts as IRunOptions
}

function buildParagraphChildren(
  inlines: readonly IntermediateInline[],
  commentWIdByDiscussionId: ReadonlyMap<string, number[]>,
  allocator: OoxmlIdAllocator,
  revisionIdMap: Map<string, number>,
  enableRevisions: boolean,
  location: string,
  diagnostics: LossReportBuilder,
): ParagraphChild[] {
  const children: ParagraphChild[] = []
  const openComments = new Set<string>()
  const emittedReferences = new Set<string>()

  const closeCommentsNotIn = (nextSet: ReadonlySet<string>) => {
    for (const dId of [...openComments]) {
      if (!nextSet.has(dId)) {
        const wIds = commentWIdByDiscussionId.get(dId) ?? []
        for (const wId of wIds) {
          children.push(new CommentRangeEnd(wId))
          if (!emittedReferences.has(`${dId}:${wId}`)) {
            children.push(new CommentReference(wId))
            emittedReferences.add(`${dId}:${wId}`)
          }
        }
        openComments.delete(dId)
      }
    }
  }

  const openCommentsIn = (nextSet: ReadonlySet<string>) => {
    for (const dId of nextSet) {
      if (!openComments.has(dId)) {
        const wIds = commentWIdByDiscussionId.get(dId) ?? []
        for (const wId of wIds) {
          children.push(new CommentRangeStart(wId))
        }
        if (wIds.length > 0) {
          openComments.add(dId)
        }
      }
    }
  }

  for (let i = 0; i < inlines.length; i += 1) {
    const inline = inlines[i]
    const inlineLoc = `${location}.inline[${i}]`

    if (inline.type === 'unsupportedInline') {
      closeCommentsNotIn(new Set())
      if (inline.mandatory) {
        diagnostics.block(
          'UNSUPPORTED_MANDATORY_INLINE',
          inline.elementName,
          inlineLoc,
          `Mandatory inline element "${inline.elementName}" cannot be exported in this profile.`,
        )
      }
      continue
    }

    const desiredComments = new Set(
      'commentIds' in inline && inline.commentIds
        ? inline.commentIds.filter((id) => commentWIdByDiscussionId.has(id))
        : [],
    )

    closeCommentsNotIn(desiredComments)
    openCommentsIn(desiredComments)

    if (inline.type === 'hardBreak') {
      children.push(new TextRun({ break: 1 }))
      continue
    }

    if (inline.type === 'bookmark') {
      // Bookmark anchors represented via text fallback if needed, or skipped cleanly.
      continue
    }

    if (inline.type === 'image') {
      diagnostics.warn(
        'IMAGE_EXPORT_PLACEHOLDER',
        'image',
        inlineLoc,
        'degraded',
        `Image "${inline.src}" was replaced with alt-text fallback in local offline DOCX export.`,
      )
      children.push(new TextRun({ text: inline.alt ? `[Image: ${inline.alt}]` : '[Image]' }))
      continue
    }

    const linkMark = inline.marks.find((m): m is Extract<typeof m, { type: 'link' }> => m.type === 'link')
    const baseOpts = buildRunStyleOptions(inline)

    if (enableRevisions && inline.formattingChange) {
      const fcRevId = allocator.allocateRevisionId(inline.formattingChange.ooxmlId)
      ;(baseOpts as Record<string, unknown>).revision = {
        id: fcRevId,
        author: inline.formattingChange.author,
        date: inline.formattingChange.date,
      }
    }

    let runNode: ParagraphChild
    if (enableRevisions && inline.revision) {
      let revNum = revisionIdMap.get(inline.revision.id)
      if (revNum === undefined) {
        revNum = allocator.allocateRevisionId(inline.revision.ooxmlId)
        revisionIdMap.set(inline.revision.id, revNum)
      }
      if (inline.revision.kind === 'insert') {
        runNode = new InsertedTextRun({
          ...baseOpts,
          id: revNum,
          author: inline.revision.author,
          date: inline.revision.date,
        })
      } else {
        runNode = new DeletedTextRun({
          ...baseOpts,
          id: revNum,
          author: inline.revision.author,
          date: inline.revision.date,
        })
      }
    } else {
      runNode = new TextRun(baseOpts)
    }

    if (linkMark) {
      if (!isSafeHyperlinkUrl(linkMark.href)) {
        diagnostics.block(
          'UNSAFE_HYPERLINK_TARGET',
          'link.href',
          inlineLoc,
          `Blocked unsafe hyperlink URL "${linkMark.href}" during DOCX export.`,
        )
        children.push(runNode)
      } else {
        children.push(
          new ExternalHyperlink({
            link: linkMark.href,
            children: [runNode],
          }),
        )
      }
    } else {
      children.push(runNode)
    }
  }

  closeCommentsNotIn(new Set())
  return children
}

function buildParagraphOptions(
  attrs: ParagraphAttrs,
  children: ParagraphChild[],
  allocator: OoxmlIdAllocator,
  revisionIdMap: Map<string, number>,
  enableRevisions: boolean,
  headingLevel?: number,
): IParagraphOptions {
  const indent: Record<string, number> = {}
  if (attrs.indentLeftDxa !== null && attrs.indentLeftDxa !== undefined) {
    indent.left = attrs.indentLeftDxa
  }
  if (attrs.indentRightDxa !== null && attrs.indentRightDxa !== undefined) {
    indent.right = attrs.indentRightDxa
  }
  if (attrs.indentFirstLineDxa !== null && attrs.indentFirstLineDxa !== undefined) {
    if (attrs.indentFirstLineDxa >= 0) indent.firstLine = attrs.indentFirstLineDxa
    else indent.hanging = Math.abs(attrs.indentFirstLineDxa)
  }

  const spacing: Record<string, unknown> = {}
  if (attrs.spacingBeforeDxa !== null && attrs.spacingBeforeDxa !== undefined) {
    spacing.before = attrs.spacingBeforeDxa
  }
  if (attrs.spacingAfterDxa !== null && attrs.spacingAfterDxa !== undefined) {
    spacing.after = attrs.spacingAfterDxa
  }
  if (attrs.lineSpacing !== null && attrs.lineSpacing !== undefined) {
    const lineInfo = lineHeightToOoxml(attrs.lineSpacing, attrs.lineRule ?? 'auto')
    spacing.line = lineInfo.line
    spacing.lineRule = lineInfo.lineRule
  }

  let joinDeletion: { id: number; author: string; date: string } | undefined
  if (enableRevisions && attrs.blockJoinDeletion) {
    let revNum = revisionIdMap.get(attrs.blockJoinDeletion.id)
    if (revNum === undefined) {
      revNum = allocator.allocateRevisionId(attrs.blockJoinDeletion.ooxmlId)
      revisionIdMap.set(attrs.blockJoinDeletion.id, revNum)
    }
    joinDeletion = {
      id: revNum,
      author: attrs.blockJoinDeletion.author,
      date: attrs.blockJoinDeletion.date,
    }
  }

  const opts: Record<string, unknown> = {
    children,
  }
  if (headingLevel !== undefined) {
    opts.heading = mapHeadingLevel(headingLevel)
  } else if (attrs.styleId) {
    opts.style = attrs.styleId
  }
  const alignment = mapAlignment(attrs.textAlign)
  if (alignment) opts.alignment = alignment
  if (Object.keys(indent).length > 0) opts.indent = indent
  if (Object.keys(spacing).length > 0) opts.spacing = spacing
  if (attrs.contextualSpacing !== null && attrs.contextualSpacing !== undefined) {
    opts.contextualSpacing = attrs.contextualSpacing
  }
  if (attrs.keepNext !== null && attrs.keepNext !== undefined) opts.keepNext = attrs.keepNext
  if (attrs.keepLines !== null && attrs.keepLines !== undefined) opts.keepLines = attrs.keepLines
  if (attrs.list) {
    opts.numbering = {
      reference: `vaulter-list-${attrs.list.numId}`,
      level: attrs.list.level,
    }
  }
  if (joinDeletion) {
    opts.run = { deletion: joinDeletion }
  }

  return opts as IParagraphOptions
}

function convertBlocksToDocx(
  blocks: readonly IntermediateBlock[],
  commentWIdByDiscussionId: ReadonlyMap<string, number[]>,
  allocator: OoxmlIdAllocator,
  revisionIdMap: Map<string, number>,
  enableRevisions: boolean,
  location: string,
  diagnostics: LossReportBuilder,
): (Paragraph | Table)[] {
  const out: (Paragraph | Table)[] = []

  for (let i = 0; i < blocks.length; i += 1) {
    const block = blocks[i]
    const loc = `${location}.block[${i}]`

    if (block.type === 'paragraph') {
      const children = buildParagraphChildren(
        block.inlines,
        commentWIdByDiscussionId,
        allocator,
        revisionIdMap,
        enableRevisions,
        loc,
        diagnostics,
      )
      out.push(
        new Paragraph(
          buildParagraphOptions(block.attrs, children, allocator, revisionIdMap, enableRevisions),
        ),
      )
    } else if (block.type === 'heading') {
      const children = buildParagraphChildren(
        block.inlines,
        commentWIdByDiscussionId,
        allocator,
        revisionIdMap,
        enableRevisions,
        loc,
        diagnostics,
      )
      out.push(
        new Paragraph(
          buildParagraphOptions(block.attrs, children, allocator, revisionIdMap, enableRevisions, block.level),
        ),
      )
    } else if (block.type === 'pageBreak') {
      out.push(new Paragraph({ children: [new PageBreak()] }))
    } else if (block.type === 'horizontalRule') {
      out.push(new Paragraph({ thematicBreak: true }))
    } else if (block.type === 'codeBlock') {
      const lines = block.text.split(/\r?\n/)
      for (const line of lines) {
        out.push(new Paragraph({ children: [new TextRun({ text: line, font: 'Courier New' })] }))
      }
    } else if (block.type === 'blockquote') {
      const inner = convertBlocksToDocx(
        block.blocks,
        commentWIdByDiscussionId,
        allocator,
        revisionIdMap,
        enableRevisions,
        loc,
        diagnostics,
      )
      out.push(...inner)
    } else if (block.type === 'table') {
      const rows = block.rows.map((row, rIdx) => {
        const cells = row.cells.map((cell, cIdx) => {
          const cellChildren = convertBlocksToDocx(
            cell.blocks,
            commentWIdByDiscussionId,
            allocator,
            revisionIdMap,
            enableRevisions,
            `${loc}.row[${rIdx}].cell[${cIdx}]`,
            diagnostics,
          )
          const cellWidth =
            cell.width ??
            (cell.colwidthDxa && cell.colwidthDxa.length > 0
              ? { unit: 'dxa' as const, value: cell.colwidthDxa.reduce((a, b) => a + b, 0) }
              : undefined)

          return new TableCell({
            children: cellChildren.length > 0 ? cellChildren : [new Paragraph({})],
            ...(cell.colspan > 1 ? { columnSpan: cell.colspan } : {}),
            ...(cell.rowspan > 1 ? { rowSpan: cell.rowspan } : {}),
            ...(cellWidth
              ? {
                  width: {
                    size: cellWidth.unit === 'pct' ? Math.round((cellWidth.value / 50) * 100) / 100 : cellWidth.value,
                    type: cellWidth.unit === 'pct' ? WidthType.PERCENTAGE : WidthType.DXA,
                  },
                }
              : {}),
            ...(cell.backgroundHex
              ? {
                  shading: {
                    fill: cell.backgroundHex,
                    type: ShadingType.CLEAR,
                    color: 'auto',
                  },
                }
              : {}),
            ...(cell.verticalAlign
              ? {
                  verticalAlign:
                    cell.verticalAlign === 'center'
                      ? VerticalAlign.CENTER
                      : cell.verticalAlign === 'bottom'
                        ? VerticalAlign.BOTTOM
                        : VerticalAlign.TOP,
                }
              : {}),
          })
        })

        return new TableRow({
          children: cells,
          ...(row.isHeader ? { tableHeader: true } : {}),
          ...(row.heightDxa != null
            ? {
                height: {
                  value: row.heightDxa,
                  rule: row.heightRule === 'exact' ? 'exact' : row.heightRule === 'auto' ? 'auto' : 'atLeast',
                },
              }
            : {}),
        })
      })

      const tableOpts: Record<string, unknown> = {
        rows,
        columnWidths: block.gridColsDxa,
      }
      if (block.width) {
        tableOpts.width = {
          size: block.width.unit === 'pct' ? Math.round((block.width.value / 50) * 100) / 100 : block.width.value,
          type: block.width.unit === 'pct' ? WidthType.PERCENTAGE : WidthType.DXA,
        }
      }
      if (block.indentDxa != null) {
        tableOpts.indent = { size: block.indentDxa, type: WidthType.DXA }
      }
      out.push(new Table(tableOpts as ConstructorParameters<typeof Table>[0]))
    } else if (block.type === 'unsupportedBlock') {
      if (block.mandatory) {
        diagnostics.block(
          'UNSUPPORTED_MANDATORY_BLOCK',
          block.elementName,
          loc,
          block.reason,
        )
      } else {
        diagnostics.warn(
          'UNSUPPORTED_BLOCK_DROPPED',
          block.elementName,
          loc,
          'dropped',
          block.reason,
        )
      }
    }
  }

  return out
}

function buildHeaderFooterPart(
  content: HeaderFooterContent | null | undefined,
  allocator: OoxmlIdAllocator,
  revisionIdMap: Map<string, number>,
  enableRevisions: boolean,
  location: string,
  diagnostics: LossReportBuilder,
): { blocks: (Paragraph | Table)[] } | undefined {
  if (!content) {
    return undefined
  }
  const blocks = convertBlocksToDocx(
    content.blocks,
    new Map(),
    allocator,
    revisionIdMap,
    enableRevisions,
    location,
    diagnostics,
  )
  return { blocks: blocks.length > 0 ? blocks : [new Paragraph({})] }
}

function prepareComments(
  discussions: readonly IntermediateDiscussion[],
  threadedComments: boolean,
  allocator: OoxmlIdAllocator,
  diagnostics: LossReportBuilder,
): {
  allocated: AllocatedCommentMeta[]
  commentWIdByDiscussionId: Map<string, number[]>
  docxComments: ICommentOptions[]
} {
  const allocated: AllocatedCommentMeta[] = []
  const commentWIdByDiscussionId = new Map<string, number[]>()
  const docxComments: ICommentOptions[] = []

  for (const discussion of discussions) {
    if (discussion.comments.length === 0) {
      continue
    }
    if (!threadedComments && discussion.comments.length > 1) {
      const alreadyLogged = diagnostics
        .all()
        .some(
          (diag) =>
            diag.code === 'LEGACY_COMMENTS_FLATTENED' &&
            diag.location === `discussion:${discussion.id}`,
        )
      if (!alreadyLogged) {
        diagnostics.warn(
          'LEGACY_COMMENTS_FLATTENED',
          'comment_replies',
          `discussion:${discussion.id}`,
          'degraded',
          `Flattened ${discussion.comments.length - 1} reply comment(s) into root comment for legacy profile.`,
        )
      }
    }

    const root = discussion.comments[0]
    const replies = discussion.comments.slice(1)
    const paraIdByCommentId = new Map<string, string>()
    const wIdByCommentId = new Map<string, number>()

    if (!threadedComments) {
      const rootWId = allocator.allocateCommentId(root.ooxml?.wId)
      const rootParaId = allocator.allocateParaId(root.id, root.ooxml?.paraId)
      const rootDurableId = allocator.allocateDurableId(root.id, root.ooxml?.durableId)
      const flattenedParagraphs = [
        ...root.bodyParagraphs,
        ...replies.flatMap((rep) => rep.bodyParagraphs.map((line) => `[${rep.author}] ${line}`)),
      ]

      allocated.push({
        discussionId: discussion.id,
        commentId: root.id,
        wId: rootWId,
        paraId: rootParaId,
        durableId: rootDurableId,
        resolved: discussion.resolved,
        author: root.author,
        initials: root.initials,
        date: new Date(root.createdAt),
        bodyParagraphs: flattenedParagraphs,
        anchorState: discussion.anchorState,
        quoteText: discussion.quoteText,
        blockId: discussion.blockId,
      })

      docxComments.push({
        id: rootWId,
        author: root.author,
        ...(root.initials ? { initials: root.initials } : {}),
        date: new Date(root.createdAt),
        children: flattenedParagraphs.map((line) => new Paragraph({ children: [new TextRun({ text: line })] })),
      })

      if (discussion.anchorState === 'attached') {
        commentWIdByDiscussionId.set(discussion.id, [rootWId])
      }
      continue
    }

    const items = [root, ...replies]
    const anchorWIds: number[] = []

    for (let idx = 0; idx < items.length; idx += 1) {
      const c = items[idx]
      const wId = allocator.allocateCommentId(c.ooxml?.wId)
      const paraId = allocator.allocateParaId(c.id, c.ooxml?.paraId)
      const durableId = allocator.allocateDurableId(c.id, c.ooxml?.durableId)
      paraIdByCommentId.set(c.id, paraId)
      wIdByCommentId.set(c.id, wId)

      const parentId = c.parentId ?? (idx > 0 ? root.id : null)
      const parentWId = parentId ? wIdByCommentId.get(parentId) : undefined
      const parentParaId = parentId ? paraIdByCommentId.get(parentId) : undefined

      if (idx === 0 && discussion.anchorState === 'attached') {
        anchorWIds.push(wId)
      }

      allocated.push({
        discussionId: discussion.id,
        commentId: c.id,
        wId,
        parentWId,
        paraId,
        parentParaId,
        durableId,
        resolved: idx === 0 ? discussion.resolved : false,
        author: c.author,
        initials: c.initials,
        date: new Date(c.createdAt),
        bodyParagraphs: c.bodyParagraphs.length > 0 ? c.bodyParagraphs : [''],
        anchorState: discussion.anchorState,
        quoteText: discussion.quoteText,
        blockId: discussion.blockId,
      })

      docxComments.push({
        id: wId,
        author: c.author,
        ...(c.initials ? { initials: c.initials } : {}),
        date: new Date(c.createdAt),
        ...(parentWId !== undefined ? { parentId: parentWId } : {}),
        resolved: idx === 0 ? discussion.resolved : false,
        durableId,
        children: (c.bodyParagraphs.length > 0 ? c.bodyParagraphs : ['']).map(
          (line) => new Paragraph({ children: [new TextRun({ text: line })] }),
        ),
      })
    }

    if (anchorWIds.length > 0) {
      commentWIdByDiscussionId.set(discussion.id, anchorWIds)
    }
  }

  return { allocated, commentWIdByDiscussionId, docxComments }
}

function renderCommentsXml(comments: readonly AllocatedCommentMeta[]): string {
  const wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
  const w14Ns = 'http://schemas.microsoft.com/office/word/2010/wordml'
  const mcNs = 'http://schemas.openxmlformats.org/markup-compatibility/2006'
  const body = comments
    .map((c) => {
      const paras = c.bodyParagraphs.map((text, idx) => {
        const isLast = idx === c.bodyParagraphs.length - 1
        const pAttrs = isLast ? ` w14:paraId="${c.paraId}" w14:textId="${c.paraId}"` : ''
        return `<w:p${pAttrs}><w:r><w:t xml:space="preserve">${escapeXml(text)}</w:t></w:r></w:p>`
      })
      const initialsAttr = c.initials ? ` w:initials="${escapeXml(c.initials)}"` : ''
      return `<w:comment w:id="${c.wId}" w:author="${escapeXml(c.author)}" w:date="${escapeXml(c.date.toISOString())}"${initialsAttr}>${paras.join('')}</w:comment>`
    })
    .join('')

  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:comments xmlns:w="${wNs}" xmlns:w14="${w14Ns}" xmlns:mc="${mcNs}" mc:Ignorable="w14">${body}</w:comments>`
}

function renderCommentsExtendedXml(comments: readonly AllocatedCommentMeta[]): string {
  const w15Ns = 'http://schemas.microsoft.com/office/word/2012/wordml'
  const mcNs = 'http://schemas.openxmlformats.org/markup-compatibility/2006'
  const body = comments
    .map((c) => {
      const parentAttr = c.parentParaId ? ` w15:paraIdParent="${c.parentParaId}"` : ''
      const doneVal = c.resolved ? '1' : '0'
      return `<w15:commentEx w15:paraId="${c.paraId}"${parentAttr} w15:done="${doneVal}"/>`
    })
    .join('')

  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w15:commentsEx xmlns:w15="${w15Ns}" xmlns:mc="${mcNs}" mc:Ignorable="w15">${body}</w15:commentsEx>`
}

function renderCommentsIdsXml(comments: readonly AllocatedCommentMeta[]): string {
  const w16cidNs = 'http://schemas.microsoft.com/office/word/2016/wordml/cid'
  const mcNs = 'http://schemas.openxmlformats.org/markup-compatibility/2006'
  const body = comments
    .map((c) => `<w16cid:commentId w16cid:paraId="${c.paraId}" w16cid:durableId="${c.durableId}"/>`)
    .join('')

  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w16cid:commentsIds xmlns:w16cid="${w16cidNs}" xmlns:mc="${mcNs}" mc:Ignorable="w16cid">${body}</w16cid:commentsIds>`
}

function renderReviewCustomXml(
  model: IntermediateDocument,
  allocatedComments: readonly AllocatedCommentMeta[],
  revisionIdMap: ReadonlyMap<string, number>,
): string {
  const replacements = model.replacementGroups
    .map((g) => {
      const delNum = revisionIdMap.get(g.deletionRevisionId) ?? ''
      const insNum = revisionIdMap.get(g.insertionRevisionId) ?? ''
      const authorAttr = g.author ? ` author="${escapeXml(g.author)}"` : ''
      const dateAttr = g.date ? ` date="${escapeXml(g.date)}"` : ''
      return `<replacement groupId="${escapeXml(g.groupId)}" deletionId="${escapeXml(g.deletionRevisionId)}" insertionId="${escapeXml(g.insertionRevisionId)}" deletionOoxmlId="${delNum}" insertionOoxmlId="${insNum}"${authorAttr}${dateAttr}/>`
    })
    .join('')

  const detachedDiscussions = model.discussions
    .filter((d) => d.anchorState !== 'attached' || d.blockId || d.quoteText)
    .map((d) => {
      const rootMeta = allocatedComments.find((c) => c.discussionId === d.id)
      const wIdAttr = rootMeta ? ` wId="${rootMeta.wId}"` : ''
      const quoteAttr = d.quoteText ? ` quoteText="${escapeXml(d.quoteText)}"` : ''
      const blockAttr = d.blockId ? ` blockId="${escapeXml(d.blockId)}"` : ''
      const genAttr = d.generation != null ? ` generation="${d.generation}"` : ''
      const posAttr = d.sourcePosition != null ? ` sourcePosition="${d.sourcePosition}"` : ''
      return `<discussion id="${escapeXml(d.id)}" anchorState="${escapeXml(d.anchorState)}" resolved="${d.resolved ? '1' : '0'}"${wIdAttr}${quoteAttr}${blockAttr}${genAttr}${posAttr}/>`
    })
    .join('')

  const markerAttr = model.sourceMarker ? ` sourceMarker="${escapeXml(JSON.stringify(model.sourceMarker))}"` : ''
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><review xmlns="${VAULTER_DOCX_CUSTOM_XML_NS}" schema="1"${markerAttr}>${replacements}${detachedDiscussions}</review>`
}

function upsertRelationship(
  relsXml: string,
  typeUri: string,
  target: string,
): string {
  const root = parseXmlPart(relsXml, 'document.xml.rels')
  const existing = findChildren(root, 'Relationship')
  const usedIds = new Set<string>()
  const filtered: Array<{ id: string; type: string; target: string; targetMode?: string }> = []

  for (const rel of existing) {
    const id = getAttr(rel, 'Id') ?? ''
    const type = getAttr(rel, 'Type') ?? ''
    const tgt = getAttr(rel, 'Target') ?? ''
    const mode = getAttr(rel, 'TargetMode')
    usedIds.add(id)
    if (type === typeUri && tgt === target) {
      return relsXml
    }
    if (type !== typeUri) {
      filtered.push({ id, type, target: tgt, targetMode: mode })
    }
  }

  let nextNum = 1
  while (usedIds.has(`rId${nextNum}`)) {
    nextNum += 1
  }
  filtered.push({ id: `rId${nextNum}`, type: typeUri, target })

  const items = filtered
    .map((r) => {
      const modeAttr = r.targetMode ? ` TargetMode="${escapeXml(r.targetMode)}"` : ''
      return `<Relationship Id="${escapeXml(r.id)}" Type="${escapeXml(r.type)}" Target="${escapeXml(r.target)}"${modeAttr}/>`
    })
    .join('')
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${items}</Relationships>`
}

function removeRelationshipByType(relsXml: string, typeUri: string): string {
  const root = parseXmlPart(relsXml, 'document.xml.rels')
  const existing = findChildren(root, 'Relationship')
  const items = existing
    .filter((rel) => getAttr(rel, 'Type') !== typeUri)
    .map((rel) => {
      const id = getAttr(rel, 'Id') ?? ''
      const type = getAttr(rel, 'Type') ?? ''
      const tgt = getAttr(rel, 'Target') ?? ''
      const mode = getAttr(rel, 'TargetMode')
      const modeAttr = mode ? ` TargetMode="${escapeXml(mode)}"` : ''
      return `<Relationship Id="${escapeXml(id)}" Type="${escapeXml(type)}" Target="${escapeXml(tgt)}"${modeAttr}/>`
    })
    .join('')
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${items}</Relationships>`
}

function upsertContentTypeOverride(contentTypesXml: string, partName: string, contentType: string): string {
  const root = parseXmlPart(contentTypesXml, '[Content_Types].xml')
  const defaults = findChildren(root, 'Default')
  const overrides = findChildren(root, 'Override').filter(
    (ov) => getAttr(ov, 'PartName') !== partName,
  )

  const defaultTags = defaults
    .map(
      (d) =>
        `<Default Extension="${escapeXml(getAttr(d, 'Extension') ?? '')}" ContentType="${escapeXml(getAttr(d, 'ContentType') ?? '')}"/>`,
    )
    .join('')
  const overrideTags = [
    ...overrides.map(
      (o) =>
        `<Override PartName="${escapeXml(getAttr(o, 'PartName') ?? '')}" ContentType="${escapeXml(getAttr(o, 'ContentType') ?? '')}"/>`,
    ),
    `<Override PartName="${escapeXml(partName)}" ContentType="${escapeXml(contentType)}"/>`,
  ].join('')

  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">${defaultTags}${overrideTags}</Types>`
}

function removeContentTypeOverride(contentTypesXml: string, partName: string): string {
  const root = parseXmlPart(contentTypesXml, '[Content_Types].xml')
  const defaults = findChildren(root, 'Default')
  const overrides = findChildren(root, 'Override').filter(
    (ov) => getAttr(ov, 'PartName') !== partName,
  )
  const defaultTags = defaults
    .map(
      (d) =>
        `<Default Extension="${escapeXml(getAttr(d, 'Extension') ?? '')}" ContentType="${escapeXml(getAttr(d, 'ContentType') ?? '')}"/>`,
    )
    .join('')
  const overrideTags = overrides
    .map(
      (o) =>
        `<Override PartName="${escapeXml(getAttr(o, 'PartName') ?? '')}" ContentType="${escapeXml(getAttr(o, 'ContentType') ?? '')}"/>`,
    )
    .join('')
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">${defaultTags}${overrideTags}</Types>`
}

export async function exportIntermediateToDocx(
  model: IntermediateDocument,
  options: ExportOoxmlOptions,
  diagnostics: LossReportBuilder,
): Promise<Uint8Array | null> {
  const profile = options.profile ?? 'discussions'
  const capabilities = getProfileCapabilities(profile, options)
  const allocator = new OoxmlIdAllocator()
  const revisionIdMap = new Map<string, number>()

  const { allocated, commentWIdByDiscussionId, docxComments } = capabilities.discussionsEnabled
    ? prepareComments(model.discussions, capabilities.threadedComments, allocator, diagnostics)
    : { allocated: [], commentWIdByDiscussionId: new Map<string, number[]>(), docxComments: [] }

  const sections: ISectionOptions[] = model.sections.map((sec: IntermediateSection, secIdx: number) => {
    const loc = `section[${secIdx}]`
    const children = convertBlocksToDocx(
      sec.blocks,
      commentWIdByDiscussionId,
      allocator,
      revisionIdMap,
      capabilities.revisionsEnabled,
      loc,
      diagnostics,
    )

    const hdrDefault = buildHeaderFooterPart(
      sec.headers.default,
      allocator,
      revisionIdMap,
      capabilities.revisionsEnabled,
      `${loc}.header.default`,
      diagnostics,
    )
    const hdrFirst = buildHeaderFooterPart(
      sec.headers.first,
      allocator,
      revisionIdMap,
      capabilities.revisionsEnabled,
      `${loc}.header.first`,
      diagnostics,
    )
    const hdrEven = buildHeaderFooterPart(
      sec.headers.even,
      allocator,
      revisionIdMap,
      capabilities.revisionsEnabled,
      `${loc}.header.even`,
      diagnostics,
    )

    const ftrDefault = buildHeaderFooterPart(
      sec.footers.default,
      allocator,
      revisionIdMap,
      capabilities.revisionsEnabled,
      `${loc}.footer.default`,
      diagnostics,
    )
    const ftrFirst = buildHeaderFooterPart(
      sec.footers.first,
      allocator,
      revisionIdMap,
      capabilities.revisionsEnabled,
      `${loc}.footer.first`,
      diagnostics,
    )
    const ftrEven = buildHeaderFooterPart(
      sec.footers.even,
      allocator,
      revisionIdMap,
      capabilities.revisionsEnabled,
      `${loc}.footer.even`,
      diagnostics,
    )

    const headers: Record<string, Header> = {}
    if (hdrDefault) headers.default = new Header({ children: hdrDefault.blocks })
    if (hdrFirst) headers.first = new Header({ children: hdrFirst.blocks })
    if (hdrEven) headers.even = new Header({ children: hdrEven.blocks })

    const footers: Record<string, Footer> = {}
    if (ftrDefault) footers.default = new Footer({ children: ftrDefault.blocks })
    if (ftrFirst) footers.first = new Footer({ children: ftrFirst.blocks })
    if (ftrEven) footers.even = new Footer({ children: ftrEven.blocks })

    const titlePage = sec.titlePg ?? Boolean(hdrFirst || ftrFirst)

    return {
      properties: {
        type: sec.type ?? 'nextPage',
        titlePage,
        ...(sec.pageSize
          ? {
              page: {
                size: {
                  width: sec.pageSize.widthDxa,
                  height: sec.pageSize.heightDxa,
                  ...(sec.pageSize.orientation ? { orientation: sec.pageSize.orientation } : {}),
                },
                ...(sec.margins
                  ? {
                      margin: {
                        ...(sec.margins.topDxa != null ? { top: sec.margins.topDxa } : {}),
                        ...(sec.margins.rightDxa != null ? { right: sec.margins.rightDxa } : {}),
                        ...(sec.margins.bottomDxa != null ? { bottom: sec.margins.bottomDxa } : {}),
                        ...(sec.margins.leftDxa != null ? { left: sec.margins.leftDxa } : {}),
                        ...(sec.margins.headerDxa != null ? { header: sec.margins.headerDxa } : {}),
                        ...(sec.margins.footerDxa != null ? { footer: sec.margins.footerDxa } : {}),
                        ...(sec.margins.gutterDxa != null ? { gutter: sec.margins.gutterDxa } : {}),
                      },
                    }
                  : {}),
              },
            }
          : {}),
      },
      ...(Object.keys(headers).length > 0 ? { headers } : {}),
      ...(Object.keys(footers).length > 0 ? { footers } : {}),
      children,
    }
  })

  if (diagnostics.all().some((d) => d.severity === 'error')) {
    return null
  }

  const numberingConfig = model.numbering.map((def) => ({
    reference: `vaulter-list-${def.numId}`,
    levels: Array.from({ length: 9 }, (_, level) => ({
      level,
      format:
        def.kind === 'bullet'
          ? LevelFormat.BULLET
          : def.format === 'lowerLetter'
            ? LevelFormat.LOWER_LETTER
            : def.format === 'upperRoman'
              ? LevelFormat.UPPER_ROMAN
              : LevelFormat.DECIMAL,
      text: def.kind === 'bullet' ? '•' : `%${level + 1}.`,
      alignment: AlignmentType.LEFT,
      start: level === 0 ? def.start : 1,
    })),
  }))

  const hasEvenHeadersOrFooters =
    model.metadata.evenAndOddHeaders ||
    model.sections.some((s) => Boolean(s.headers.even || s.footers.even))

  const doc = new Document({
    title: model.metadata.title ?? undefined,
    creator: model.metadata.creator ?? 'Vaulter Editor Pro',
    lastModifiedBy: model.metadata.lastModifiedBy ?? 'Vaulter Editor Pro',
    evenAndOddHeaderAndFooters: hasEvenHeadersOrFooters,
    features: { trackRevisions: capabilities.revisionsEnabled },
    numbering: { config: numberingConfig },
    ...(docxComments.length > 0 ? { comments: { children: docxComments } } : {}),
    sections,
  })

  const rawBuffer = await Packer.toArrayBuffer(doc)
  const zipEntries = fflateUnzipRaw(new Uint8Array(rawBuffer))

  let relsXml = decodeUtf8(zipEntries['word/_rels/document.xml.rels'])
  let contentTypesXml = decodeUtf8(zipEntries['[Content_Types].xml'])

  const relComment = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments'
  const relCommentExt = 'http://schemas.microsoft.com/office/2011/relationships/commentsExtended'
  const relCommentIds = 'http://schemas.microsoft.com/office/2016/09/relationships/commentsIds'
  const relCustomXml = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml'

  if (allocated.length > 0) {
    zipEntries['word/comments.xml'] = encodeUtf8(renderCommentsXml(allocated))
    relsXml = upsertRelationship(relsXml, relComment, 'comments.xml')
    contentTypesXml = upsertContentTypeOverride(
      contentTypesXml,
      '/word/comments.xml',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml',
    )

    if (capabilities.threadedComments) {
      zipEntries['word/commentsExtended.xml'] = encodeUtf8(renderCommentsExtendedXml(allocated))
      zipEntries['word/commentsIds.xml'] = encodeUtf8(renderCommentsIdsXml(allocated))
      relsXml = upsertRelationship(relsXml, relCommentExt, 'commentsExtended.xml')
      relsXml = upsertRelationship(relsXml, relCommentIds, 'commentsIds.xml')
      contentTypesXml = upsertContentTypeOverride(
        contentTypesXml,
        '/word/commentsExtended.xml',
        'application/vnd.ms-word.commentsExtended+xml',
      )
      contentTypesXml = upsertContentTypeOverride(
        contentTypesXml,
        '/word/commentsIds.xml',
        'application/vnd.ms-word.commentsIds+xml',
      )
    } else {
      delete zipEntries['word/commentsExtended.xml']
      delete zipEntries['word/commentsIds.xml']
      relsXml = removeRelationshipByType(relsXml, relCommentExt)
      relsXml = removeRelationshipByType(relsXml, relCommentIds)
      contentTypesXml = removeContentTypeOverride(contentTypesXml, '/word/commentsExtended.xml')
      contentTypesXml = removeContentTypeOverride(contentTypesXml, '/word/commentsIds.xml')
    }
  }

  const needsCustomXml =
    (capabilities.revisionsEnabled && model.replacementGroups.length > 0) ||
    model.discussions.some((d) => d.anchorState !== 'attached' || Boolean(d.blockId || d.quoteText)) ||
    model.sourceMarker !== null

  if (needsCustomXml) {
    zipEntries['customXml/item1.xml'] = encodeUtf8(
      renderReviewCustomXml(model, allocated, revisionIdMap),
    )
    relsXml = upsertRelationship(relsXml, relCustomXml, '../customXml/item1.xml')
    contentTypesXml = upsertContentTypeOverride(
      contentTypesXml,
      '/customXml/item1.xml',
      'application/xml',
    )
  }

  // Ensure any comments.xml part generated by docx still adheres to w14:paraId range if needed,
  // and verify document.xml has valid structure.
  if (zipEntries['word/document.xml'] && !capabilities.discussionsEnabled) {
    // Nothing extra needed since comments weren't passed.
  } else if (zipEntries['word/comments.xml'] && capabilities.threadedComments) {
    const parsedComments = parseXmlPart(decodeUtf8(zipEntries['word/comments.xml']), 'word/comments.xml')
    const commentNodes = findChildren(parsedComments, 'comment')
    for (const cNode of commentNodes) {
      const pNodes = findChildren(cNode, 'p')
      const lastP = pNodes[pNodes.length - 1]
      if (!lastP || !getAttr(lastP, 'w14:paraId', 'paraId')) {
        diagnostics.block(
          'COMMENT_PARA_ID_MISSING',
          'comments.paraId',
          'word/comments.xml',
          'Last paragraph in comment is missing required w14:paraId.',
        )
        return null
      }
      void findChild(lastP, 'r')
    }
  }

  zipEntries['word/_rels/document.xml.rels'] = encodeUtf8(relsXml)
  zipEntries['[Content_Types].xml'] = encodeUtf8(contentTypesXml)

  return fflateZipRaw(zipEntries)
}
