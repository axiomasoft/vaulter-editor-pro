import {
  DOCX_MODEL_SCHEMA,
  type AnchoredCommentLike,
  type BorderSpec,
  type CellBordersSpec,
  type CellMarginsDxa,
  type DocxDiscussionInput,
  type DocxNoteInput,
  type DocxProfileId,
  type DocxProjectionMode,
  type DocxSectionInput,
  type FormattingChangeMeta,
  type FormattingRevisionRecord,
  type HeaderFooterContent,
  type InlineRevisionMeta,
  type IntermediateBlock,
  type IntermediateComment,
  type IntermediateDiscussion,
  type IntermediateDocument,
  type IntermediateInline,
  type IntermediateMark,
  type IntermediateNote,
  type IntermediateSection,
  type IntermediateTableBlock,
  type IntermediateTableCell,
  type IntermediateTableRow,
  type IntermediateTextInline,
  type LossDiagnostic,
  type NumberingDefinition,
  type ParagraphAttrs,
  type ReplacementGroupMetadata,
  type SourceMarker,
  type TableBordersSpec,
  type TextAlignment,
  type TipTapDoc,
  type TipTapMark,
  type TipTapNode,
  type UnderlineType,
} from '../model/types'
import { getProfileCapabilities, type ProfileFeatureOptions } from '../model/profiles'
import { LossReportBuilder } from '../model/lossReport'
import { parseAndValidateImageDataUri } from '../zip/guard'
import {
  halfPointsToPt,
  isSafeHyperlinkUrl,
  normalizeHexColor,
  normalizeNumberingFormat,
  parseFontSizeToHalfPt,
  parseInternalAnchorHref,
  parseOoxmlWidth,
  pixelsToTwips,
  ptToEighthPoints,
  ptToHalfPoints,
  twipsToPixels,
} from '../ooxml/units'

export type DiscussionInput = DocxDiscussionInput | IntermediateDiscussion | AnchoredCommentLike

export interface ToIntermediateOptions extends ProfileFeatureOptions {
  profile?: DocxProfileId
  projection?: DocxProjectionMode
  sourceMarker?: SourceMarker | null
  sections?: DocxSectionInput[]
  discussions?: DiscussionInput[]
  footnotes?: DocxNoteInput[]
  endnotes?: DocxNoteInput[]
  replacementGroups?: ReplacementGroupMetadata[]
  title?: string | null
  creator?: string | null
  evenAndOddHeaders?: boolean
}

export interface ToIntermediateResult {
  model: IntermediateDocument
  diagnostics: LossDiagnostic[]
}

export interface FromIntermediateOptions {
  preserveSectionNodes?: boolean
  includePageBreakNodes?: boolean
}

export interface FromIntermediateResult {
  document: TipTapDoc
  sections: IntermediateSection[]
  discussions: IntermediateDiscussion[]
  replacementGroups: ReplacementGroupMetadata[]
  formattingRevisions: FormattingRevisionRecord[]
  sourceMarker: SourceMarker | null
  model: IntermediateDocument
}

function isAnchoredCommentLike(value: DiscussionInput): value is AnchoredCommentLike {
  return 'parent_id' in value && 'body' in value
}

export function anchoredCommentsToDocxDiscussions(
  comments: readonly AnchoredCommentLike[],
): DocxDiscussionInput[] {
  const roots: AnchoredCommentLike[] = []
  const repliesByRoot = new Map<string, AnchoredCommentLike[]>()

  for (const item of comments) {
    if (item.parent_id === null) {
      roots.push(item)
    } else {
      const list = repliesByRoot.get(item.parent_id) ?? []
      list.push(item)
      repliesByRoot.set(item.parent_id, list)
    }
  }

  return roots.map((root) => {
    const replies = [...(repliesByRoot.get(root.id) ?? [])].sort((a, b) =>
      a.created_at.localeCompare(b.created_at),
    )
    const all = [root, ...replies]
    const anchor = root.anchor
    const anchorState = anchor ? anchor.anchor_state : 'detached'

    return {
      id: root.id,
      anchorState,
      quoteText: anchor?.quote_text ?? null,
      blockId: anchor?.block_id ?? null,
      sourcePosition: anchor?.source_position ?? null,
      generation: anchor?.generation ?? null,
      resolved: root.resolved_at !== null,
      comments: all.map((entry) => ({
        id: entry.id,
        parentId: entry.parent_id,
        author: `${entry.author.type}#${entry.author.id}`,
        createdAt: entry.created_at,
        body: entry.body,
      })),
    }
  })
}

function normalizeAlignment(raw: unknown): TextAlignment | null {
  if (raw === 'left' || raw === 'center' || raw === 'right' || raw === 'both' || raw === 'justify') {
    return raw === 'justify' ? 'both' : raw
  }
  return null
}

function normalizeUnderlineType(raw: unknown): UnderlineType | null {
  if (
    raw === 'single' ||
    raw === 'double' ||
    raw === 'thick' ||
    raw === 'dotted' ||
    raw === 'dash' ||
    raw === 'wave' ||
    raw === 'none'
  ) {
    return raw
  }
  return 'single'
}

function parseMarks(
  rawMarks: readonly TipTapMark[] | undefined,
  location: string,
  diagnostics: LossReportBuilder,
  projection: DocxProjectionMode,
): {
  marks: IntermediateMark[]
  commentIds: string[]
  revision: InlineRevisionMeta | null
  formattingChange: FormattingChangeMeta | null
} {
  const marks: IntermediateMark[] = []
  const commentIds: string[] = []
  let revision: InlineRevisionMeta | null = null
  let formattingChange: FormattingChangeMeta | null = null

  if (!rawMarks) {
    return { marks, commentIds, revision, formattingChange }
  }

  const liveMarks = rawMarks.filter(mark =>
    mark.type === 'reviewSuggestion' && typeof mark.attrs?.suggestionId === 'string'
    && (mark.attrs?.kind === 'insert' || mark.attrs?.kind === 'delete'),
  )
  if (projection === 'review' && liveMarks.length > 1) {
    diagnostics.block(
      'NESTED_LIVE_REVISIONS_UNSUPPORTED',
      'tracked_revisions',
      location,
      'Review export can represent only one live revision per run. Use the original or proposed projection, or resolve the overlapping suggestions.',
    )
  }
  // Keep pending insertions out of the baseline, including in a blocked preview.
  const liveMark = liveMarks.find(mark => mark.attrs?.kind === (projection === 'proposed' ? 'delete' : 'insert'))
    ?? liveMarks[0]

  for (const mark of rawMarks) {
    const attrs = mark.attrs ?? {}
    switch (mark.type) {
      case 'bold':
        marks.push({ type: 'bold' })
        break
      case 'italic':
        marks.push({ type: 'italic' })
        break
      case 'underline':
        marks.push({
          type: 'underline',
          underlineType: attrs.underlineType ? normalizeUnderlineType(attrs.underlineType) : 'single',
        })
        break
      case 'strike':
        marks.push({ type: 'strike' })
        break
      case 'code':
        marks.push({ type: 'code' })
        break
      case 'superscript':
        marks.push({ type: 'superscript' })
        break
      case 'subscript':
        marks.push({ type: 'subscript' })
        break
      case 'highlight': {
        const hex = normalizeHexColor(attrs.color) ?? 'FFFF00'
        marks.push({ type: 'highlight', colorHex: hex })
        break
      }
      case 'link': {
        const href = typeof attrs.href === 'string' ? attrs.href.trim() : ''
        const explicitAnchor = typeof attrs.anchor === 'string' ? parseInternalAnchorHref(`#${attrs.anchor}`) : null
        const internalAnchor = explicitAnchor ?? parseInternalAnchorHref(href)
        if (internalAnchor) {
          marks.push({
            type: 'link',
            href: `#${internalAnchor}`,
            anchor: internalAnchor,
            title: typeof attrs.title === 'string' ? attrs.title : null,
          })
          break
        }
        if (!isSafeHyperlinkUrl(href)) {
          diagnostics.block(
            'UNSAFE_HYPERLINK_TARGET',
            'link.href',
            location,
            `Hyperlink target "${href}" is not an allowed http(s)/mailto URL.`,
          )
          break
        }
        marks.push({
          type: 'link',
          href,
          title: typeof attrs.title === 'string' ? attrs.title : null,
        })
        break
      }
      case 'textStyle': {
        const fontSizeHalfPt = parseFontSizeToHalfPt(attrs.fontSize)
        const colorHex = normalizeHexColor(attrs.color)
        const backgroundColorHex = normalizeHexColor(attrs.backgroundColor)
        const fontFamily = typeof attrs.fontFamily === 'string' && attrs.fontFamily.trim() !== '' ? attrs.fontFamily.trim() : null
        const lineHeight = typeof attrs.lineHeight === 'number' && Number.isFinite(attrs.lineHeight) ? attrs.lineHeight : null
        if (fontFamily || fontSizeHalfPt || colorHex || backgroundColorHex || lineHeight) {
          marks.push({
            type: 'textStyle',
            fontFamily,
            fontSizeHalfPt,
            colorHex,
            backgroundColorHex,
            lineHeight,
          })
        }
        break
      }
      case 'inlineThread':
      case 'reviewAnchor':
      case 'commentAnchor': {
        const threadId =
          attrs['data-thread-id'] ?? attrs.threadId ?? attrs.discussionId ?? attrs.commentId ?? attrs.id
        if (typeof threadId === 'string' && threadId.trim() !== '') {
          commentIds.push(threadId.trim())
        }
        break
      }
      case 'suggestion':
      case 'reviewSuggestion': {
        if (liveMarks.includes(mark)) break
        const rawType = String(attrs.type ?? attrs.kind ?? 'insert')
        const revId = typeof attrs.id === 'string' && attrs.id !== '' ? attrs.id : `rev_${location}`
        const userMeta = (attrs.userMetadata ?? {}) as Record<string, unknown>
        const author =
          typeof userMeta.name === 'string' && userMeta.name.trim() !== ''
            ? userMeta.name.trim()
            : typeof attrs.author === 'string' && attrs.author.trim() !== ''
              ? attrs.author.trim()
              : typeof attrs.userId === 'string' && attrs.userId.trim() !== ''
                ? attrs.userId.trim()
                : 'Unknown Author'
        const date =
          typeof attrs.createdAt === 'string' && attrs.createdAt !== ''
            ? attrs.createdAt
            : '1970-01-01T00:00:00Z'
        const groupId = typeof attrs.groupId === 'string' && attrs.groupId !== '' ? attrs.groupId : null

        if (rawType === 'markChange' || rawType === 'format') {
          const markChanges = Array.isArray(attrs.markChanges)
            ? (attrs.markChanges as FormattingChangeMeta['markChanges'])
            : undefined
          formattingChange = {
            id: revId,
            targetType: 'run',
            author,
            date,
            summary:
              markChanges && markChanges.length > 0
                ? markChanges.map((m) => `${m.operation}:${m.markName}`).join(', ')
                : 'run formatting change',
            markChanges,
          }
        } else if (rawType === 'delete' || rawType === 'remove' || rawType === 'replaceDeletion') {
          revision = {
            id: revId,
            kind: 'delete',
            groupId: groupId ?? (rawType === 'replaceDeletion' ? revId : null),
            author,
            date,
          }
        } else {
          revision = {
            id: revId,
            kind: 'insert',
            groupId: groupId ?? (rawType === 'replaceInsertion' ? revId : null),
            author,
            date,
          }
        }
        break
      }
      default:
        diagnostics.warn(
          'UNSUPPORTED_MARK',
          `mark.${mark.type}`,
          location,
          'dropped',
          `Mark "${mark.type}" has no OOXML mapping and was dropped.`,
        )
        break
    }
  }

  const liveId = liveMark?.attrs?.suggestionId
  const liveKind = liveMark?.attrs?.kind
  if (typeof liveId === 'string' && (liveKind === 'insert' || liveKind === 'delete')) {
    revision = {
      id: liveId,
      kind: liveKind,
      groupId: liveId,
      author: 'Unknown Author',
      date: '1970-01-01T00:00:00Z',
    }
  }
  return { marks, commentIds, revision, formattingChange }
}

function parseParagraphAttrs(node: TipTapNode, listBinding: ParagraphAttrs['list'] = null): ParagraphAttrs {
  const attrs = node.attrs ?? {}
  const indentLeftDxa =
    typeof attrs.indent === 'number' && Number.isFinite(attrs.indent) ? pixelsToTwips(attrs.indent) : null
  const indentFirstLineDxa =
    typeof attrs.firstLineIndent === 'number' && Number.isFinite(attrs.firstLineIndent)
      ? pixelsToTwips(attrs.firstLineIndent)
      : null
  const indentRightDxa =
    typeof attrs.indentRight === 'number' && Number.isFinite(attrs.indentRight)
      ? pixelsToTwips(attrs.indentRight)
      : null
  const spacingBeforeDxa =
    typeof attrs.spacingBefore === 'number' && Number.isFinite(attrs.spacingBefore)
      ? pixelsToTwips(attrs.spacingBefore)
      : null
  const spacingAfterDxa =
    typeof attrs.spacingAfter === 'number' && Number.isFinite(attrs.spacingAfter)
      ? pixelsToTwips(attrs.spacingAfter)
      : null
  const lineSpacing =
    typeof attrs.lineHeight === 'number' && Number.isFinite(attrs.lineHeight) ? attrs.lineHeight : null

  const rawJoinDel = attrs.blockJoinDeletion as Record<string, unknown> | undefined
  const blockJoinDeletion: InlineRevisionMeta | null = rawJoinDel
    ? {
        id: String(rawJoinDel.id ?? 'join_del'),
        kind: 'delete',
        groupId: typeof rawJoinDel.groupId === 'string' ? rawJoinDel.groupId : null,
        author: String(rawJoinDel.author ?? 'Unknown Author'),
        date: String(rawJoinDel.date ?? '1970-01-01T00:00:00Z'),
      }
    : null

  const rawFc = attrs.formattingChange as Record<string, unknown> | undefined
  const formattingChange: FormattingChangeMeta | null =
    rawFc && typeof rawFc === 'object'
      ? {
          id: String(rawFc.id ?? 'ppr_change'),
          targetType: 'paragraph',
          author: String(rawFc.author ?? 'Unknown Author'),
          date: String(rawFc.date ?? '1970-01-01T00:00:00Z'),
          summary: String(rawFc.summary ?? 'paragraph formatting change'),
          ...(typeof rawFc.ooxmlId === 'number' ? { ooxmlId: rawFc.ooxmlId } : {}),
          ...(Array.isArray(rawFc.propertyChanges)
            ? { propertyChanges: rawFc.propertyChanges as FormattingChangeMeta['propertyChanges'] }
            : {}),
          ...(rawFc.previousParagraphProps && typeof rawFc.previousParagraphProps === 'object'
            ? { previousParagraphProps: rawFc.previousParagraphProps as Partial<ParagraphAttrs> }
            : {}),
        }
      : null

  return {
    styleId: typeof attrs.styleId === 'string' ? attrs.styleId : null,
    textAlign: normalizeAlignment(attrs.textAlign),
    indentLeftDxa,
    indentFirstLineDxa,
    indentRightDxa,
    spacingBeforeDxa,
    spacingAfterDxa,
    lineSpacing,
    lineRule: lineSpacing !== null ? 'auto' : null,
    contextualSpacing: typeof attrs.contextualSpacing === 'boolean' ? attrs.contextualSpacing : null,
    keepNext: typeof attrs.keepNext === 'boolean' ? attrs.keepNext : null,
    keepLines: typeof attrs.keepLines === 'boolean' ? attrs.keepLines : null,
    list: listBinding,
    blockJoinDeletion,
    formattingChange,
  }
}

function parseInlines(
  content: readonly TipTapNode[] | undefined,
  location: string,
  diagnostics: LossReportBuilder,
  tracker: ConversionState,
): IntermediateInline[] {
  const projection = tracker.projection
  if (!content) {
    return []
  }
  const result: IntermediateInline[] = []

  for (let i = 0; i < content.length; i += 1) {
    const child = content[i]
    const childLoc = `${location}.inline[${i}]`
    if (child.type === 'text') {
      const parsed = parseMarks(child.marks, childLoc, diagnostics, projection)
      result.push({
        type: 'text',
        text: child.text ?? '',
        marks: parsed.marks,
        ...(parsed.commentIds.length > 0 ? { commentIds: parsed.commentIds } : {}),
        ...(parsed.revision ? { revision: parsed.revision } : {}),
        ...(parsed.formattingChange ? { formattingChange: parsed.formattingChange } : {}),
      })
    } else if (child.type === 'hardBreak') {
      const parsed = parseMarks(child.marks, childLoc, diagnostics, projection)
      result.push({
        type: 'hardBreak',
        ...(parsed.commentIds.length > 0 ? { commentIds: parsed.commentIds } : {}),
        ...(parsed.revision ? { revision: parsed.revision } : {}),
      })
    } else if (child.type === 'bookmark') {
      const name = String(child.attrs?.name ?? child.attrs?.id ?? `bm_${i}`)
      const id = String(child.attrs?.id ?? name)
      result.push({ type: 'bookmark', id, name })
    } else if (child.type === 'footnote' || child.type === 'footnoteReference') {
      const rawId = Number(child.attrs?.id)
      let noteId = Number.isInteger(rawId) && rawId > 0 && !tracker.usedFootnoteIds.has(rawId)
        ? rawId
        : tracker.nextFootnoteId++
      while (tracker.usedFootnoteIds.has(noteId)) {
        noteId = tracker.nextFootnoteId++
      }
      tracker.usedFootnoteIds.add(noteId)
      const noteBlocks: IntermediateBlock[] = []
      if (Array.isArray(child.content) && child.content.length > 0) {
        for (let b = 0; b < child.content.length; b += 1) {
          noteBlocks.push(...convertBlockNode(child.content[b], `${childLoc}.fn[${b}]`, diagnostics, tracker))
        }
      } else if (typeof child.attrs?.text === 'string') {
        noteBlocks.push({
          type: 'paragraph',
          id: null,
          attrs: {},
          inlines: [{ type: 'text', text: child.attrs.text, marks: [] }],
        })
      } else {
        const fromOpt = tracker.inputFootnotes.get(String(child.attrs?.id ?? noteId))
        if (fromOpt) {
          noteBlocks.push(...fromOpt)
        }
      }
      tracker.footnotes.push({ id: noteId, blocks: noteBlocks })
      result.push({ type: 'footnoteRef', id: noteId })
    } else if (child.type === 'endnote' || child.type === 'endnoteReference') {
      const rawId = Number(child.attrs?.id)
      let noteId = Number.isInteger(rawId) && rawId > 0 && !tracker.usedEndnoteIds.has(rawId)
        ? rawId
        : tracker.nextEndnoteId++
      while (tracker.usedEndnoteIds.has(noteId)) {
        noteId = tracker.nextEndnoteId++
      }
      tracker.usedEndnoteIds.add(noteId)
      const noteBlocks: IntermediateBlock[] = []
      if (Array.isArray(child.content) && child.content.length > 0) {
        for (let b = 0; b < child.content.length; b += 1) {
          noteBlocks.push(...convertBlockNode(child.content[b], `${childLoc}.en[${b}]`, diagnostics, tracker))
        }
      } else if (typeof child.attrs?.text === 'string') {
        noteBlocks.push({
          type: 'paragraph',
          id: null,
          attrs: {},
          inlines: [{ type: 'text', text: child.attrs.text, marks: [] }],
        })
      } else {
        const fromOpt = tracker.inputEndnotes.get(String(child.attrs?.id ?? noteId))
        if (fromOpt) {
          noteBlocks.push(...fromOpt)
        }
      }
      tracker.endnotes.push({ id: noteId, blocks: noteBlocks })
      result.push({ type: 'endnoteRef', id: noteId })
    } else if (child.type === 'image') {
      const src = typeof child.attrs?.src === 'string' ? child.attrs.src : ''
      if (src.startsWith('data:')) {
        parseAndValidateImageDataUri(src)
      } else if (!isSafeHyperlinkUrl(src)) {
        diagnostics.warn(
          'IMAGE_ASSET_UNRESOLVED',
          'image',
          childLoc,
          'degraded',
          `Image "${src}" cannot be fetched offline during local conversion.`,
        )
      }
      const rawCrop = child.attrs?.crop as Record<string, unknown> | undefined
      const cropTop = typeof rawCrop?.top === 'number' ? rawCrop.top : typeof child.attrs?.cropTop === 'number' ? child.attrs.cropTop : null
      const cropBottom = typeof rawCrop?.bottom === 'number' ? rawCrop.bottom : typeof child.attrs?.cropBottom === 'number' ? child.attrs.cropBottom : null
      const cropLeft = typeof rawCrop?.left === 'number' ? rawCrop.left : typeof child.attrs?.cropLeft === 'number' ? child.attrs.cropLeft : null
      const cropRight = typeof rawCrop?.right === 'number' ? rawCrop.right : typeof child.attrs?.cropRight === 'number' ? child.attrs.cropRight : null
      const hasCrop = cropTop !== null || cropBottom !== null || cropLeft !== null || cropRight !== null

      result.push({
        type: 'image',
        src,
        alt: typeof child.attrs?.alt === 'string' ? child.attrs.alt : null,
        title: typeof child.attrs?.title === 'string' ? child.attrs.title : null,
        widthPx: typeof child.attrs?.width === 'number' ? child.attrs.width : null,
        heightPx: typeof child.attrs?.height === 'number' ? child.attrs.height : null,
        ...(hasCrop
          ? {
              crop: {
                ...(cropTop !== null ? { top: cropTop } : {}),
                ...(cropBottom !== null ? { bottom: cropBottom } : {}),
                ...(cropLeft !== null ? { left: cropLeft } : {}),
                ...(cropRight !== null ? { right: cropRight } : {}),
              },
            }
          : {}),
      })
    } else {
      diagnostics.block(
        'UNSUPPORTED_INLINE_NODE',
        `inline.${child.type}`,
        childLoc,
        `Mandatory inline node "${child.type}" is not supported.`,
      )
      result.push({
        type: 'unsupportedInline',
        elementName: child.type,
        mandatory: true,
      })
    }
  }

  return result
}

interface ConversionState {
  nextNumId: number
  definitions: NumberingDefinition[]
  projection: DocxProjectionMode
  nextFootnoteId: number
  usedFootnoteIds: Set<number>
  footnotes: IntermediateNote[]
  inputFootnotes: Map<string, IntermediateBlock[]>
  nextEndnoteId: number
  usedEndnoteIds: Set<number>
  endnotes: IntermediateNote[]
  inputEndnotes: Map<string, IntermediateBlock[]>
}

function convertListNode(
  listNode: TipTapNode,
  kind: 'bullet' | 'ordered',
  level: number,
  numId: number,
  location: string,
  diagnostics: LossReportBuilder,
  tracker: ConversionState,
): IntermediateBlock[] {
  const blocks: IntermediateBlock[] = []
  const start = kind === 'ordered' && typeof listNode.attrs?.start === 'number' ? listNode.attrs.start : 1
  const format =
    kind === 'bullet'
      ? 'bullet'
      : normalizeNumberingFormat(listNode.attrs?.type ?? listNode.attrs?.numberingFormat)

  let def = tracker.definitions.find((d) => d.numId === numId)
  if (!def) {
    def = { numId, kind, start, format, levels: [] }
    tracker.definitions.push(def)
  }
  if (!def.levels) {
    def.levels = []
  }
  if (!def.levels.some((l) => l.level === level)) {
    def.levels.push({ level, kind, start, format })
  }

  const items = listNode.content ?? []
  for (let i = 0; i < items.length; i += 1) {
    const item = items[i]
    if (item.type !== 'listItem' && item.type !== 'taskItem') {
      continue
    }
    const itemLoc = `${location}.item[${i}]`
    const itemChildren = item.content ?? []
    let firstParagraphInItem = true

    for (let j = 0; j < itemChildren.length; j += 1) {
      const child = itemChildren[j]
      if (child.type === 'paragraph') {
        blocks.push({
          type: 'paragraph',
          id: typeof child.attrs?.blockId === 'string' ? child.attrs.blockId : null,
          attrs: parseParagraphAttrs(
            child,
            firstParagraphInItem ? { kind, level, numId, start, format } : null,
          ),
          inlines: parseInlines(child.content, `${itemLoc}.p[${j}]`, diagnostics, tracker),
        })
        firstParagraphInItem = false
      } else if (child.type === 'bulletList' || child.type === 'orderedList') {
        blocks.push(
          ...convertListNode(
            child,
            child.type === 'bulletList' ? 'bullet' : 'ordered',
            Math.min(8, level + 1),
            numId,
            `${itemLoc}.sublist[${j}]`,
            diagnostics,
            tracker,
          ),
        )
      } else {
        blocks.push(...convertBlockNode(child, `${itemLoc}.block[${j}]`, diagnostics, tracker))
      }
    }
  }

  return blocks
}

function parseBorderInput(raw: unknown): BorderSpec | null {
  if (!raw || typeof raw !== 'object') return null
  const obj = raw as Record<string, unknown>
  const style = typeof obj.style === 'string' && obj.style.trim() !== '' ? obj.style.trim() : 'single'
  let sizeEighthPt = style === 'none' || style === 'nil' ? 0 : 4
  if (typeof obj.sizeEighthPt === 'number' && Number.isFinite(obj.sizeEighthPt)) {
    sizeEighthPt = Math.round(obj.sizeEighthPt)
  } else if (typeof obj.widthPt === 'number' && Number.isFinite(obj.widthPt)) {
    sizeEighthPt = ptToEighthPoints(obj.widthPt)
  } else if (typeof obj.size === 'number' && Number.isFinite(obj.size)) {
    sizeEighthPt = Math.round(obj.size)
  }
  const colorHex = normalizeHexColor(obj.colorHex ?? obj.color)
  return { style, sizeEighthPt, colorHex }
}

function parseTableBordersInput(raw: unknown): TableBordersSpec | null {
  if (!raw || typeof raw !== 'object') return null
  const obj = raw as Record<string, unknown>
  const top = parseBorderInput(obj.top)
  const bottom = parseBorderInput(obj.bottom)
  const left = parseBorderInput(obj.left)
  const right = parseBorderInput(obj.right)
  const insideH = parseBorderInput(obj.insideH ?? obj.insideHorizontal)
  const insideV = parseBorderInput(obj.insideV ?? obj.insideVertical)
  if (!top && !bottom && !left && !right && !insideH && !insideV) return null
  return {
    ...(top ? { top } : {}),
    ...(bottom ? { bottom } : {}),
    ...(left ? { left } : {}),
    ...(right ? { right } : {}),
    ...(insideH ? { insideH } : {}),
    ...(insideV ? { insideV } : {}),
  }
}

function parseCellBordersInput(raw: unknown): CellBordersSpec | null {
  if (!raw || typeof raw !== 'object') return null
  const obj = raw as Record<string, unknown>
  const top = parseBorderInput(obj.top)
  const bottom = parseBorderInput(obj.bottom)
  const left = parseBorderInput(obj.left)
  const right = parseBorderInput(obj.right)
  if (!top && !bottom && !left && !right) return null
  return {
    ...(top ? { top } : {}),
    ...(bottom ? { bottom } : {}),
    ...(left ? { left } : {}),
    ...(right ? { right } : {}),
  }
}

function parseCellMarginsInput(raw: unknown): CellMarginsDxa | null {
  if (!raw || typeof raw !== 'object') return null
  const obj = raw as Record<string, unknown>
  const readSide = (dxaKey: string, pxKey: string): number | null => {
    if (typeof obj[dxaKey] === 'number' && Number.isFinite(obj[dxaKey] as number)) {
      return Math.round(obj[dxaKey] as number)
    }
    if (typeof obj[pxKey] === 'number' && Number.isFinite(obj[pxKey] as number)) {
      return pixelsToTwips(obj[pxKey] as number)
    }
    return null
  }
  const topDxa = readSide('topDxa', 'top')
  const rightDxa = readSide('rightDxa', 'right')
  const bottomDxa = readSide('bottomDxa', 'bottom')
  const leftDxa = readSide('leftDxa', 'left')
  if (topDxa === null && rightDxa === null && bottomDxa === null && leftDxa === null) return null
  return {
    ...(topDxa !== null ? { topDxa } : {}),
    ...(rightDxa !== null ? { rightDxa } : {}),
    ...(bottomDxa !== null ? { bottomDxa } : {}),
    ...(leftDxa !== null ? { leftDxa } : {}),
  }
}

function convertTableNode(
  tableNode: TipTapNode,
  location: string,
  diagnostics: LossReportBuilder,
  tracker: ConversionState,
): IntermediateTableBlock {
  const attrs = tableNode.attrs ?? {}
  let tableWidth = parseOoxmlWidth(
    typeof attrs.width === 'number' || typeof attrs.width === 'string' ? attrs.width : undefined,
    typeof attrs.widthUnit === 'string' ? attrs.widthUnit : undefined,
  )
  if (typeof attrs.width === 'number') {
    tableWidth = { unit: 'dxa', value: pixelsToTwips(attrs.width) }
  }

  const rawRows = tableNode.content ?? []
  const rows: IntermediateTableRow[] = []
  const inferredGridCols: number[] = []

  // Track active rowspans so grid column index maps accurately across rows.
  const remainingRowspans = new Map<number, number>()

  for (let r = 0; r < rawRows.length; r += 1) {
    const rawRow = rawRows[r]
    if (rawRow.type !== 'tableRow') {
      continue
    }
    const rowAttrs = rawRow.attrs ?? {}
    const rawCells = rawRow.content ?? []
    const cells: IntermediateTableCell[] = []
    let colIdx = 0
    let hasHeaderCell = rowAttrs.isHeader === true

    const advanceOccupied = () => {
      while ((remainingRowspans.get(colIdx) ?? 0) > 0) {
        remainingRowspans.set(colIdx, (remainingRowspans.get(colIdx) ?? 1) - 1)
        colIdx += 1
      }
    }

    for (let c = 0; c < rawCells.length; c += 1) {
      advanceOccupied()
      const rawCell = rawCells[c]
      if (rawCell.type !== 'tableCell' && rawCell.type !== 'tableHeader') {
        continue
      }
      if (rawCell.type === 'tableHeader') {
        hasHeaderCell = true
      }
      const cellAttrs = rawCell.attrs ?? {}
      const colspan =
        typeof cellAttrs.colspan === 'number' && cellAttrs.colspan >= 1
          ? Math.trunc(cellAttrs.colspan)
          : 1
      const rowspan =
        typeof cellAttrs.rowspan === 'number' && cellAttrs.rowspan >= 1
          ? Math.trunc(cellAttrs.rowspan)
          : 1
      const colwidthRaw = Array.isArray(cellAttrs.colwidth) ? cellAttrs.colwidth : null
      const colwidthDxa = colwidthRaw
        ? colwidthRaw.map((w) => (typeof w === 'number' && Number.isFinite(w) ? pixelsToTwips(w) : 1800))
        : null

      if (colwidthDxa) {
        for (let spanOffset = 0; spanOffset < colspan; spanOffset += 1) {
          const w = colwidthDxa[spanOffset] ?? colwidthDxa[0]
          if (w && inferredGridCols[colIdx + spanOffset] === undefined) {
            inferredGridCols[colIdx + spanOffset] = w
          }
        }
      }

      if (rowspan > 1) {
        for (let spanOffset = 0; spanOffset < colspan; spanOffset += 1) {
          remainingRowspans.set(colIdx + spanOffset, rowspan - 1)
        }
      }

      const cellWidthSum = colwidthDxa
        ? colwidthDxa.reduce((sum, w) => sum + w, 0)
        : null
      const cellWidth =
        cellWidthSum !== null && cellWidthSum > 0
          ? ({ unit: 'dxa', value: cellWidthSum } as const)
          : typeof cellAttrs.widthDxa === 'number'
            ? ({ unit: 'dxa', value: Math.round(cellAttrs.widthDxa) } as const)
            : null

      const cellBlocks: IntermediateBlock[] = []
      const cellChildren = rawCell.content ?? []
      for (let b = 0; b < cellChildren.length; b += 1) {
        cellBlocks.push(
          ...convertBlockNode(cellChildren[b], `${location}.row[${r}].cell[${c}].block[${b}]`, diagnostics, tracker),
        )
      }

      const vAlignRaw = cellAttrs.verticalAlign
      const verticalAlign =
        vAlignRaw === 'middle' || vAlignRaw === 'center'
          ? 'center'
          : vAlignRaw === 'bottom'
            ? 'bottom'
            : vAlignRaw === 'top'
              ? 'top'
              : null

      const cellBorders = parseCellBordersInput(cellAttrs.borders)
      const cellMargins = parseCellMarginsInput(cellAttrs.margins ?? cellAttrs.cellMargins)

      cells.push({
        colspan,
        rowspan,
        colwidthDxa,
        width: cellWidth,
        backgroundHex: normalizeHexColor(cellAttrs.background),
        verticalAlign,
        ...(cellBorders ? { borders: cellBorders } : {}),
        ...(cellMargins ? { margins: cellMargins } : {}),
        blocks: cellBlocks,
      })

      colIdx += colspan
    }

    advanceOccupied()

    const heightDxa =
      typeof rowAttrs.height === 'number' && Number.isFinite(rowAttrs.height)
        ? pixelsToTwips(rowAttrs.height)
        : null
    const heightRule =
      rowAttrs.heightRule === 'exact' || rowAttrs.heightRule === 'atLeast' || rowAttrs.heightRule === 'auto'
        ? rowAttrs.heightRule
        : heightDxa !== null
          ? 'atLeast'
          : null

    rows.push({
      isHeader: hasHeaderCell,
      heightDxa,
      heightRule,
      cells,
    })
  }

  const explicitGrid = Array.isArray(attrs.gridColsDxa)
    ? (attrs.gridColsDxa as unknown[])
        .filter((n): n is number => typeof n === 'number' && Number.isFinite(n) && n > 0)
        .map((n) => Math.round(n))
    : null

  const maxCols = Math.max(
    explicitGrid?.length ?? 0,
    inferredGridCols.length,
    ...rows.map((row) => row.cells.reduce((acc, cell) => acc + cell.colspan, 0)),
    1,
  )

  const gridColsDxa: number[] = []
  const fallbackColWidth =
    tableWidth?.unit === 'dxa' && tableWidth.value > 0
      ? Math.round(tableWidth.value / maxCols)
      : 1800
  for (let i = 0; i < maxCols; i += 1) {
    gridColsDxa.push(explicitGrid?.[i] ?? inferredGridCols[i] ?? fallbackColWidth)
  }

  const tableBorders = parseTableBordersInput(attrs.borders)
  const tableMargins = parseCellMarginsInput(attrs.margins ?? attrs.cellMargins)

  return {
    type: 'table',
    id: typeof attrs.blockId === 'string' ? attrs.blockId : null,
    width: tableWidth ?? { unit: 'dxa', value: gridColsDxa.reduce((a, b) => a + b, 0) },
    indentDxa:
      typeof attrs.indent === 'number' && Number.isFinite(attrs.indent) ? pixelsToTwips(attrs.indent) : null,
    gridColsDxa,
    ...(tableBorders ? { borders: tableBorders } : {}),
    ...(tableMargins ? { margins: tableMargins } : {}),
    rows,
  }
}

function convertBlockNode(
  node: TipTapNode,
  location: string,
  diagnostics: LossReportBuilder,
  tracker: ConversionState,
): IntermediateBlock[] {
  const attrs = node.attrs ?? {}
  const blockId = typeof attrs.blockId === 'string' ? attrs.blockId : null

  switch (node.type) {
    case 'paragraph':
      return [
        {
          type: 'paragraph',
          id: blockId,
          attrs: parseParagraphAttrs(node),
          inlines: parseInlines(node.content, location, diagnostics, tracker),
        },
      ]
    case 'heading': {
      const rawLevel = typeof attrs.level === 'number' ? Math.trunc(attrs.level) : 1
      const level = Math.max(1, Math.min(6, rawLevel)) as 1 | 2 | 3 | 4 | 5 | 6
      return [
        {
          type: 'heading',
          id: blockId,
          level,
          attrs: parseParagraphAttrs(node),
          inlines: parseInlines(node.content, location, diagnostics, tracker),
        },
      ]
    }
    case 'bulletList': {
      const numId = tracker.nextNumId++
      return convertListNode(node, 'bullet', 0, numId, location, diagnostics, tracker)
    }
    case 'orderedList': {
      const numId = tracker.nextNumId++
      return convertListNode(node, 'ordered', 0, numId, location, diagnostics, tracker)
    }
    case 'table':
      return [convertTableNode(node, location, diagnostics, tracker)]
    case 'blockquote': {
      const inner: IntermediateBlock[] = []
      for (let i = 0; i < (node.content ?? []).length; i += 1) {
        inner.push(...convertBlockNode(node.content![i], `${location}.bq[${i}]`, diagnostics, tracker))
      }
      return [{ type: 'blockquote', id: blockId, blocks: inner }]
    }
    case 'codeBlock': {
      const text = (node.content ?? []).map((n) => n.text ?? '').join('')
      return [
        {
          type: 'codeBlock',
          id: blockId,
          language: typeof attrs.language === 'string' ? attrs.language : null,
          text,
        },
      ]
    }
    case 'horizontalRule':
      return [{ type: 'horizontalRule', id: blockId }]
    case 'pageBreak':
      return [{ type: 'pageBreak', id: blockId }]
    default:
      diagnostics.block(
        'UNSUPPORTED_MANDATORY_BLOCK',
        `block.${node.type}`,
        location,
        `Mandatory block node "${node.type}" is not supported by DOCX profile.`,
      )
      return [
        {
          type: 'unsupportedBlock',
          id: blockId,
          elementName: node.type,
          mandatory: true,
          reason: `Unsupported TipTap node "${node.type}"`,
        },
      ]
  }
}

function convertHeaderFooterSlot(
  slot: TipTapDoc | HeaderFooterContent | string | null | undefined,
  location: string,
  diagnostics: LossReportBuilder,
  tracker: ConversionState,
): HeaderFooterContent | null {
  if (slot === undefined || slot === null) {
    return null
  }
  if (typeof slot === 'string') {
    return {
      blocks: [
        {
          type: 'paragraph',
          id: null,
          attrs: {},
          inlines: [{ type: 'text', text: slot, marks: [] }],
        },
      ],
    }
  }
  if ('blocks' in slot && Array.isArray(slot.blocks)) {
    return slot
  }
  const doc = slot as TipTapDoc
  const blocks: IntermediateBlock[] = []
  for (let i = 0; i < (doc.content ?? []).length; i += 1) {
    blocks.push(...convertBlockNode(doc.content![i], `${location}.b[${i}]`, diagnostics, tracker))
  }
  return { blocks }
}

function attachSnapshotAnchorsToBlocks(
  blocks: IntermediateBlock[],
  discussions: readonly IntermediateDiscussion[],
): void {
  const attachedByBlock = new Map<string, Array<{ discussionId: string; from: number; to: number }>>()
  for (const d of discussions) {
    if (d.anchorState === 'attached' && d.blockId && d.sourcePosition !== null && d.sourcePosition !== undefined) {
      // If inline marks didn't already attach this discussion, we also support explicit utf16 ranges when present.
      const list = attachedByBlock.get(d.blockId) ?? []
      list.push({
        discussionId: d.id,
        from: d.sourcePosition,
        to: d.sourcePosition + (d.quoteText?.length ?? 0),
      })
      attachedByBlock.set(d.blockId, list)
    }
  }

  for (const block of blocks) {
    if (block.type === 'paragraph' || block.type === 'heading') {
      const alreadyTagged = new Set<string>()
      for (const inline of block.inlines) {
        if ('commentIds' in inline && inline.commentIds) {
          for (const id of inline.commentIds) {
            alreadyTagged.add(id)
          }
        }
      }
      if (!block.id) {
        continue
      }
      const candidates = attachedByBlock.get(block.id) ?? []
      for (const cand of candidates) {
        if (alreadyTagged.has(cand.discussionId) || cand.to <= cand.from) {
          continue
        }
        // Split inline text runs at UTF-16 [cand.from, cand.to) boundaries.
        const nextInlines: IntermediateInline[] = []
        let offset = 0
        for (const inline of block.inlines) {
          if (inline.type !== 'text') {
            nextInlines.push(inline)
            continue
          }
          const len = inline.text.length
          const start = offset
          const end = offset + len
          offset = end

          if (end <= cand.from || start >= cand.to) {
            nextInlines.push(inline)
            continue
          }

          const relStart = Math.max(0, cand.from - start)
          const relEnd = Math.min(len, cand.to - start)
          if (relStart > 0) {
            nextInlines.push({ ...inline, text: inline.text.slice(0, relStart) })
          }
          const middleIds = new Set(inline.commentIds ?? [])
          middleIds.add(cand.discussionId)
          nextInlines.push({
            ...inline,
            text: inline.text.slice(relStart, relEnd),
            commentIds: [...middleIds],
          })
          if (relEnd < len) {
            nextInlines.push({ ...inline, text: inline.text.slice(relEnd) })
          }
        }
        block.inlines = nextInlines
      }
    } else if (block.type === 'table') {
      for (const row of block.rows) {
        for (const cell of row.cells) {
          attachSnapshotAnchorsToBlocks(cell.blocks, discussions)
        }
      }
    } else if (block.type === 'blockquote') {
      attachSnapshotAnchorsToBlocks(block.blocks, discussions)
    }
  }
}

function normalizeDiscussionsInput(
  rawList: readonly DiscussionInput[] | undefined,
): IntermediateDiscussion[] {
  if (!rawList || rawList.length === 0) {
    return []
  }
  const inputs = [
    ...rawList.filter((item): item is DocxDiscussionInput | IntermediateDiscussion => !isAnchoredCommentLike(item)),
    ...anchoredCommentsToDocxDiscussions(rawList.filter(isAnchoredCommentLike)),
  ]

  return inputs.map((item) => {
    const comments: IntermediateComment[] = item.comments.map((c) => 'bodyParagraphs' in c ? c : ({
      id: c.id,
      parentId: c.parentId ?? null,
      author: c.author,
      initials: c.initials ?? null,
      createdAt: c.createdAt ?? '1970-01-01T00:00:00Z',
      bodyParagraphs: Array.isArray(c.body) ? c.body : c.body.split(/\r?\n/),
      ...(c.originalWId !== undefined || c.originalParaId || c.originalDurableId
        ? {
            ooxml: {
              ...(c.originalWId !== undefined ? { wId: Number(c.originalWId) } : {}),
              ...(c.originalParaId ? { paraId: c.originalParaId } : {}),
              ...(c.originalDurableId ? { durableId: c.originalDurableId } : {}),
            },
          }
        : {}),
    }))

    return {
      id: item.id,
      anchorState: item.anchorState ?? 'attached',
      quoteText: item.quoteText ?? null,
      blockId: item.blockId ?? null,
      sourcePosition: item.sourcePosition ?? null,
      generation: item.generation ?? null,
      resolved: item.resolved === true,
      comments,
    }
  })
}

function collectFormattingRevisions(
  sections: readonly IntermediateSection[],
  diagnostics: LossReportBuilder,
  requireFormattingRevisions: boolean,
): FormattingRevisionRecord[] {
  const records: FormattingRevisionRecord[] = []

  const scanBlocks = (blocks: readonly IntermediateBlock[], prefix: string) => {
    for (let b = 0; b < blocks.length; b += 1) {
      const block = blocks[b]
      const loc = `${prefix}.block[${b}]`
      if (block.type === 'paragraph' || block.type === 'heading') {
        if (block.attrs.formattingChange) {
          const fc = block.attrs.formattingChange
          records.push({
            id: fc.id,
            targetType: 'paragraph',
            location: loc,
            author: fc.author,
            date: fc.date,
            summary: fc.summary,
          })
          if (requireFormattingRevisions) {
            diagnostics.block(
              'NATIVE_FORMATTING_REVISION_UNSUPPORTED',
              'pPrChange',
              loc,
              `Mandatory native formatting revision (${fc.summary}) cannot be silently preserved as accepted formatting.`,
            )
          } else {
            diagnostics.warn(
              'NATIVE_FORMATTING_REVISION',
              'pPrChange',
              loc,
              'degraded',
              `Paragraph formatting revision "${fc.summary}" by ${fc.author} is recorded in loss report (not silently accepted).`,
            )
          }
        }
        for (let r = 0; r < block.inlines.length; r += 1) {
          const inline = block.inlines[r]
          if (inline.type === 'text' && inline.formattingChange) {
            const fc = inline.formattingChange
            const rLoc = `${loc}.inline[${r}]`
            records.push({
              id: fc.id,
              targetType: 'run',
              location: rLoc,
              author: fc.author,
              date: fc.date,
              summary: fc.summary,
            })
            if (requireFormattingRevisions) {
              diagnostics.block(
                'NATIVE_FORMATTING_REVISION_UNSUPPORTED',
                'rPrChange',
                rLoc,
                `Mandatory native formatting revision (${fc.summary}) cannot be silently preserved in strict profile.`,
              )
            } else {
              diagnostics.warn(
                'NATIVE_FORMATTING_REVISION',
                'rPrChange',
                rLoc,
                'degraded',
                `Native formatting revision "${fc.summary}" by ${fc.author} is flagged in loss report and not silently accepted.`,
              )
            }
          }
        }
      } else if (block.type === 'table') {
        for (let r = 0; r < block.rows.length; r += 1) {
          for (let c = 0; c < block.rows[r].cells.length; c += 1) {
            scanBlocks(block.rows[r].cells[c].blocks, `${loc}.row[${r}].cell[${c}]`)
          }
        }
      } else if (block.type === 'blockquote') {
        scanBlocks(block.blocks, `${loc}.bq`)
      }
    }
  }

  for (let s = 0; s < sections.length; s += 1) {
    scanBlocks(sections[s].blocks, `section[${s}]`)
  }

  return records
}

function projectBlocks(
  blocks: readonly IntermediateBlock[],
  projection: DocxProjectionMode,
): { blocks: IntermediateBlock[]; strippedRevisions: number } {
  let strippedRevisions = 0

  const mapInlineList = (inlines: readonly IntermediateInline[]): IntermediateInline[] => {
    const out: IntermediateInline[] = []
    for (const inline of inlines) {
      if (inline.type === 'text') {
        const rev = inline.revision
        if (rev) {
          if (projection === 'original') {
            strippedRevisions += 1
            if (rev.kind === 'insert') {
              continue
            }
            out.push({ ...inline, revision: null, formattingChange: null })
            continue
          }
          if (projection === 'proposed') {
            strippedRevisions += 1
            if (rev.kind === 'delete') {
              continue
            }
            out.push({ ...inline, revision: null, formattingChange: null })
            continue
          }
        }
        if (inline.formattingChange && projection !== 'review') {
          strippedRevisions += 1
          out.push({ ...inline, formattingChange: null })
          continue
        }
      }
      out.push(inline)
    }
    return out
  }

  const mapBlockList = (list: readonly IntermediateBlock[]): IntermediateBlock[] =>
    list.map((block) => {
      if (block.type === 'paragraph' || block.type === 'heading') {
        const attrs =
          projection === 'review'
            ? block.attrs
            : { ...block.attrs, blockJoinDeletion: null, formattingChange: null }
        if (block.attrs.blockJoinDeletion && projection !== 'review') {
          strippedRevisions += 1
        }
        return {
          ...block,
          attrs,
          inlines: mapInlineList(block.inlines),
        }
      }
      if (block.type === 'table') {
        return {
          ...block,
          rows: block.rows.map((row) => ({
            ...row,
            cells: row.cells.map((cell) => ({
              ...cell,
              blocks: mapBlockList(cell.blocks),
            })),
          })),
        }
      }
      if (block.type === 'blockquote') {
        return {
          ...block,
          blocks: mapBlockList(block.blocks),
        }
      }
      return block
    })

  return {
    blocks: mapBlockList(blocks),
    strippedRevisions,
  }
}

export function projectIntermediateModel(
  model: IntermediateDocument,
  projection: DocxProjectionMode,
): { model: IntermediateDocument; strippedRevisions: number } {
  let totalStripped = 0
  const sections = model.sections.map((section) => {
    const projected = projectBlocks(section.blocks, projection)
    totalStripped += projected.strippedRevisions
    return {
      ...section,
      blocks: projected.blocks,
    }
  })

  return {
    model: {
      ...model,
      sections,
      replacementGroups: projection === 'review' ? model.replacementGroups : [],
      formattingRevisions: projection === 'review' ? model.formattingRevisions : [],
    },
    strippedRevisions: totalStripped,
  }
}

export function toIntermediate(
  document: TipTapDoc,
  options: ToIntermediateOptions = {},
): ToIntermediateResult {
  const profile = options.profile ?? 'discussions'
  const capabilities = getProfileCapabilities(profile, options)
  const projection = options.projection ?? capabilities.defaultProjection
  const effectiveProjection: DocxProjectionMode = capabilities.revisionsEnabled ? projection : 'original'
  const diagnostics = new LossReportBuilder()
  const tracker: ConversionState = {
    nextNumId: 1,
    definitions: [],
    projection: effectiveProjection,
    nextFootnoteId: 1,
    usedFootnoteIds: new Set<number>(),
    footnotes: [],
    inputFootnotes: new Map((options.footnotes ?? []).map((note) => [String(note.id), note.blocks as IntermediateBlock[]])),
    nextEndnoteId: 1,
    usedEndnoteIds: new Set<number>(),
    endnotes: [],
    inputEndnotes: new Map((options.endnotes ?? []).map((note) => [String(note.id), note.blocks as IntermediateBlock[]])),
  }

  const rawContent = document.content ?? []
  const sectionNodes = rawContent.filter((n) => n.type === 'section')
  const sections: IntermediateSection[] = []

  if (sectionNodes.length > 0) {
    for (let s = 0; s < sectionNodes.length; s += 1) {
      const sNode = sectionNodes[s]
      const sAttrs = (sNode.attrs ?? {}) as Record<string, unknown>
      const sInput = options.sections?.[s]
      const blocks: IntermediateBlock[] = []
      const children = sNode.content ?? []
      for (let i = 0; i < children.length; i += 1) {
        blocks.push(...convertBlockNode(children[i], `section[${s}].block[${i}]`, diagnostics, tracker))
      }
      const headersInput = (sInput?.headers ?? sAttrs.headers ?? {}) as NonNullable<DocxSectionInput['headers']>
      const footersInput = (sInput?.footers ?? sAttrs.footers ?? {}) as NonNullable<DocxSectionInput['footers']>
      const firstHeader = convertHeaderFooterSlot(headersInput.first, `section[${s}].header.first`, diagnostics, tracker)
      const firstFooter = convertHeaderFooterSlot(footersInput.first, `section[${s}].footer.first`, diagnostics, tracker)
      sections.push({
        id: String(sInput?.id ?? sAttrs.id ?? `sec_${s + 1}`),
        type: (sInput?.type ?? sAttrs.type ?? 'nextPage') as IntermediateSection['type'],
        pageSize: sInput?.pageSize ?? (sAttrs.pageSize as IntermediateSection['pageSize']) ?? { widthDxa: 11906, heightDxa: 16838 },
        margins: sInput?.margins ?? (sAttrs.margins as IntermediateSection['margins']),
        titlePg: sInput?.titlePg ?? (typeof sAttrs.titlePg === 'boolean' ? sAttrs.titlePg : Boolean(firstHeader || firstFooter)),
        headers: {
          default: convertHeaderFooterSlot(headersInput.default, `section[${s}].header.default`, diagnostics, tracker),
          first: firstHeader,
          even: convertHeaderFooterSlot(headersInput.even, `section[${s}].header.even`, diagnostics, tracker),
        },
        footers: {
          default: convertHeaderFooterSlot(footersInput.default, `section[${s}].footer.default`, diagnostics, tracker),
          first: firstFooter,
          even: convertHeaderFooterSlot(footersInput.even, `section[${s}].footer.even`, diagnostics, tracker),
        },
        blocks,
      })
    }
  } else {
    // Split on `sectionBreak` nodes if present, or group by `options.sections` blockCounts.
    const hasSectionBreaks = rawContent.some((n) => n.type === 'sectionBreak')
    if (hasSectionBreaks) {
      let currentBlocks: IntermediateBlock[] = []
      let secIdx = 0
      const pushSection = (breakAttrs?: Record<string, unknown>) => {
        const sInput = options.sections?.[secIdx]
        const headersInput = (sInput?.headers ?? breakAttrs?.headers ?? {}) as NonNullable<DocxSectionInput['headers']>
        const footersInput = (sInput?.footers ?? breakAttrs?.footers ?? {}) as NonNullable<DocxSectionInput['footers']>
        const firstHeader = convertHeaderFooterSlot(headersInput.first, `section[${secIdx}].header.first`, diagnostics, tracker)
        const firstFooter = convertHeaderFooterSlot(footersInput.first, `section[${secIdx}].footer.first`, diagnostics, tracker)
        sections.push({
          id: String(sInput?.id ?? breakAttrs?.id ?? `sec_${secIdx + 1}`),
          type: (sInput?.type ?? breakAttrs?.type ?? 'nextPage') as IntermediateSection['type'],
          pageSize: sInput?.pageSize ?? { widthDxa: 11906, heightDxa: 16838 },
          margins: sInput?.margins,
          titlePg: sInput?.titlePg ?? Boolean(firstHeader || firstFooter),
          headers: {
            default: convertHeaderFooterSlot(headersInput.default, `section[${secIdx}].header.default`, diagnostics, tracker),
            first: firstHeader,
            even: convertHeaderFooterSlot(headersInput.even, `section[${secIdx}].header.even`, diagnostics, tracker),
          },
          footers: {
            default: convertHeaderFooterSlot(footersInput.default, `section[${secIdx}].footer.default`, diagnostics, tracker),
            first: firstFooter,
            even: convertHeaderFooterSlot(footersInput.even, `section[${secIdx}].footer.even`, diagnostics, tracker),
          },
          blocks: currentBlocks,
        })
        currentBlocks = []
        secIdx += 1
      }

      for (let i = 0; i < rawContent.length; i += 1) {
        const node = rawContent[i]
        if (node.type === 'sectionBreak') {
          pushSection(node.attrs)
        } else {
          currentBlocks.push(...convertBlockNode(node, `doc.block[${i}]`, diagnostics, tracker))
        }
      }
      if (currentBlocks.length > 0 || sections.length === 0) {
        pushSection()
      }
    } else if (options.sections && options.sections.length > 0) {
      let cursor = 0
      for (let s = 0; s < options.sections.length; s += 1) {
        const sInput = options.sections[s]
        const count = s === options.sections.length - 1
          ? rawContent.length - cursor
          : Math.max(0, sInput.blockCount ?? rawContent.length - cursor)
        const slice = rawContent.slice(cursor, cursor + count)
        cursor += count
        const blocks: IntermediateBlock[] = []
        const breakAfter = new Set(
          Array.isArray((sInput as { pageBreakAfterBlocks?: unknown }).pageBreakAfterBlocks)
            ? ((sInput as { pageBreakAfterBlocks?: number[] }).pageBreakAfterBlocks ?? [])
            : [],
        )
        for (let i = 0; i < slice.length; i += 1) {
          blocks.push(...convertBlockNode(slice[i], `section[${s}].block[${i}]`, diagnostics, tracker))
          if (breakAfter.has(i)) {
            blocks.push({ type: 'pageBreak', id: null })
          }
        }
        const firstHeader = convertHeaderFooterSlot(sInput.headers?.first, `section[${s}].header.first`, diagnostics, tracker)
        const firstFooter = convertHeaderFooterSlot(sInput.footers?.first, `section[${s}].footer.first`, diagnostics, tracker)
        sections.push({
          id: sInput.id ?? `sec_${s + 1}`,
          type: sInput.type ?? 'nextPage',
          pageSize: sInput.pageSize ?? { widthDxa: 11906, heightDxa: 16838 },
          margins: sInput.margins,
          titlePg: sInput.titlePg ?? Boolean(firstHeader || firstFooter),
          headers: {
            default: convertHeaderFooterSlot(sInput.headers?.default, `section[${s}].header.default`, diagnostics, tracker),
            first: firstHeader,
            even: convertHeaderFooterSlot(sInput.headers?.even, `section[${s}].header.even`, diagnostics, tracker),
          },
          footers: {
            default: convertHeaderFooterSlot(sInput.footers?.default, `section[${s}].footer.default`, diagnostics, tracker),
            first: firstFooter,
            even: convertHeaderFooterSlot(sInput.footers?.even, `section[${s}].footer.even`, diagnostics, tracker),
          },
          blocks,
        })
      }
    } else {
      const blocks: IntermediateBlock[] = []
      for (let i = 0; i < rawContent.length; i += 1) {
        blocks.push(...convertBlockNode(rawContent[i], `doc.block[${i}]`, diagnostics, tracker))
      }
      sections.push({
        id: 'sec_1',
        type: 'nextPage',
        pageSize: { widthDxa: 11906, heightDxa: 16838 },
        titlePg: false,
        headers: {},
        footers: {},
        blocks,
      })
    }
  }

  const discussions = normalizeDiscussionsInput(options.discussions)
  for (const sec of sections) {
    attachSnapshotAnchorsToBlocks(sec.blocks, discussions)
  }

  // Derive replacement group metadata from paired insert/delete groupId if not explicitly given.
  const replacementGroups: ReplacementGroupMetadata[] = [...(options.replacementGroups ?? [])]
  if (replacementGroups.length === 0) {
    const byGroup = new Map<string, { del?: InlineRevisionMeta; ins?: InlineRevisionMeta }>()
    const visitInlines = (blocks: readonly IntermediateBlock[]) => {
      for (const b of blocks) {
        if (b.type === 'paragraph' || b.type === 'heading') {
          for (const inl of b.inlines) {
            if (inl.type === 'text' && inl.revision?.groupId) {
              const entry = byGroup.get(inl.revision.groupId) ?? {}
              if (inl.revision.kind === 'delete') entry.del = inl.revision
              else entry.ins = inl.revision
              byGroup.set(inl.revision.groupId, entry)
            }
          }
        } else if (b.type === 'table') {
          for (const r of b.rows) {
            for (const c of r.cells) visitInlines(c.blocks)
          }
        } else if (b.type === 'blockquote') {
          visitInlines(b.blocks)
        }
      }
    }
    for (const s of sections) visitInlines(s.blocks)
    for (const [groupId, pair] of byGroup) {
      if (pair.del && pair.ins) {
        replacementGroups.push({
          groupId,
          deletionRevisionId: pair.del.id,
          insertionRevisionId: pair.ins.id,
          author: pair.ins.author,
          date: pair.ins.date,
        })
      }
    }
  }

  const formattingRevisions = collectFormattingRevisions(
    sections,
    diagnostics,
    capabilities.requireFormattingRevisions && projection === 'review',
  )

  const hasEven = sections.some((s) => Boolean(s.headers.even || s.footers.even))
  let model: IntermediateDocument = {
    schema: DOCX_MODEL_SCHEMA,
    sourceMarker: options.sourceMarker ?? null,
    metadata: {
      title: options.title ?? null,
      creator: options.creator ?? null,
      evenAndOddHeaders: options.evenAndOddHeaders ?? hasEven,
    },
    numbering: tracker.definitions,
    sections,
    footnotes: tracker.footnotes,
    endnotes: tracker.endnotes,
    discussions: capabilities.discussionsEnabled ? discussions : [],
    replacementGroups,
    formattingRevisions,
  }

  if (!capabilities.discussionsEnabled && discussions.length > 0) {
    diagnostics.warn(
      'DISCUSSIONS_EXCLUDED_BY_PROFILE',
      'discussions',
      'document',
      'dropped',
      `${discussions.length} discussion thread(s) omitted because profile "${profile}" excludes discussions.`,
    )
  } else if (capabilities.discussionsEnabled && !capabilities.threadedComments) {
    for (const d of discussions) {
      if (d.comments.length > 1) {
        diagnostics.warn(
          'LEGACY_COMMENTS_FLATTENED',
          'comment_replies',
          `discussion:${d.id}`,
          'degraded',
          `Flattened ${d.comments.length - 1} reply comment(s) into root comment for legacy profile.`,
        )
      }
    }
  }

  const projected = projectIntermediateModel(model, effectiveProjection)
  model = projected.model

  if (!capabilities.revisionsEnabled && projected.strippedRevisions > 0) {
    diagnostics.warn(
      'REVISIONS_PROJECTED_TO_ORIGINAL',
      'tracked_revisions',
      'document',
      'projected',
      `${projected.strippedRevisions} pending tracked change(s) projected to "${effectiveProjection}" because profile "${profile}" does not export tracked revisions.`,
    )
  }

  return {
    model,
    diagnostics: diagnostics.all(),
  }
}

function marksToTipTap(inline: IntermediateTextInline): TipTapMark[] {
  const marks: TipTapMark[] = []
  for (const m of inline.marks) {
    switch (m.type) {
      case 'bold':
        marks.push({ type: 'bold' })
        break
      case 'italic':
        marks.push({ type: 'italic' })
        break
      case 'underline':
        marks.push({ type: 'underline', attrs: { underlineType: m.underlineType ?? 'single' } })
        break
      case 'strike':
        marks.push({ type: 'strike' })
        break
      case 'code':
        marks.push({ type: 'code' })
        break
      case 'superscript':
        marks.push({ type: 'superscript' })
        break
      case 'subscript':
        marks.push({ type: 'subscript' })
        break
      case 'highlight':
        marks.push({ type: 'highlight', attrs: { color: `#${m.colorHex}` } })
        break
      case 'link':
        marks.push({ type: 'link', attrs: { href: m.href, ...(m.title ? { title: m.title } : {}) } })
        break
      case 'textStyle':
        marks.push({
          type: 'textStyle',
          attrs: {
            ...(m.fontFamily ? { fontFamily: m.fontFamily } : {}),
            ...(m.fontSizeHalfPt ? { fontSize: `${halfPointsToPt(m.fontSizeHalfPt)}pt` } : {}),
            ...(m.colorHex ? { color: `#${m.colorHex}` } : {}),
            ...(m.backgroundColorHex ? { backgroundColor: `#${m.backgroundColorHex}` } : {}),
            ...(m.lineHeight ? { lineHeight: m.lineHeight } : {}),
          },
        })
        break
    }
  }

  for (const threadId of inline.commentIds ?? []) {
    marks.push({
      type: 'inlineThread',
      attrs: { 'data-thread-id': threadId },
    })
  }

  if (inline.revision) {
    marks.push({
      type: 'suggestion',
      attrs: {
        id: inline.revision.id,
        type: inline.revision.kind === 'delete'
          ? inline.revision.groupId ? 'replaceDeletion' : 'delete'
          : inline.revision.groupId ? 'replaceInsertion' : 'add',
        ...(inline.revision.groupId ? { groupId: inline.revision.groupId } : {}),
        author: inline.revision.author,
        createdAt: inline.revision.date,
        userMetadata: { name: inline.revision.author },
      },
    })
  }

  if (inline.formattingChange) {
    marks.push({
      type: 'suggestion',
      attrs: {
        id: inline.formattingChange.id,
        type: 'markChange',
        author: inline.formattingChange.author,
        createdAt: inline.formattingChange.date,
        userMetadata: { name: inline.formattingChange.author },
        markChanges: inline.formattingChange.markChanges ?? [],
      },
    })
  }

  return marks
}

function inlinesToTipTap(
  inlines: readonly IntermediateInline[],
  footnotesById?: Map<number, IntermediateBlock[]>,
  endnotesById?: Map<number, IntermediateBlock[]>,
): TipTapNode[] {
  const nodes: TipTapNode[] = []
  for (const inline of inlines) {
    if (inline.type === 'text') {
      if (inline.text === '') {
        continue
      }
      const marks = marksToTipTap(inline)
      nodes.push({
        type: 'text',
        text: inline.text,
        ...(marks.length > 0 ? { marks } : {}),
      })
    } else if (inline.type === 'hardBreak') {
      nodes.push({ type: 'hardBreak' })
    } else if (inline.type === 'bookmark') {
      nodes.push({ type: 'bookmark', attrs: { id: inline.id, ...(inline.name ? { name: inline.name } : {}) } })
    } else if (inline.type === 'footnoteRef') {
      const noteBlocks = footnotesById?.get(inline.id) ?? []
      nodes.push({
        type: 'footnote',
        attrs: { id: inline.id },
        content: blocksToTipTap(noteBlocks, footnotesById, endnotesById),
      })
    } else if (inline.type === 'endnoteRef') {
      const noteBlocks = endnotesById?.get(inline.id) ?? []
      nodes.push({
        type: 'endnote',
        attrs: { id: inline.id },
        content: blocksToTipTap(noteBlocks, footnotesById, endnotesById),
      })
    } else if (inline.type === 'image') {
      nodes.push({
        type: 'image',
        attrs: {
          src: inline.src,
          alt: inline.alt ?? null,
          title: inline.title ?? null,
          width: inline.widthPx ?? null,
          height: inline.heightPx ?? null,
          ...(inline.crop ? { crop: inline.crop } : {}),
        },
      })
    }
  }
  return nodes
}

function paragraphAttrsToTipTap(attrs: ParagraphAttrs): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  if (attrs.textAlign) out.textAlign = attrs.textAlign
  if (attrs.indentLeftDxa != null) out.indent = twipsToPixels(attrs.indentLeftDxa)
  if (attrs.indentFirstLineDxa != null) out.firstLineIndent = twipsToPixels(attrs.indentFirstLineDxa)
  if (attrs.indentRightDxa != null) out.indentRight = twipsToPixels(attrs.indentRightDxa)
  if (attrs.spacingBeforeDxa != null) out.spacingBefore = twipsToPixels(attrs.spacingBeforeDxa)
  if (attrs.spacingAfterDxa != null) out.spacingAfter = twipsToPixels(attrs.spacingAfterDxa)
  if (attrs.lineSpacing != null) out.lineHeight = attrs.lineSpacing
  if (attrs.contextualSpacing != null) out.contextualSpacing = attrs.contextualSpacing
  if (attrs.keepNext != null) out.keepNext = attrs.keepNext
  if (attrs.keepLines != null) out.keepLines = attrs.keepLines
  if (attrs.blockJoinDeletion) out.blockJoinDeletion = attrs.blockJoinDeletion
  if (attrs.formattingChange) out.formattingChange = attrs.formattingChange
  return out
}

function blocksToTipTap(
  blocks: readonly IntermediateBlock[],
  footnotesById?: Map<number, IntermediateBlock[]>,
  endnotesById?: Map<number, IntermediateBlock[]>,
): TipTapNode[] {
  const result: TipTapNode[] = []
  let i = 0

  const consumeListAtLevel = (targetLevel: number): TipTapNode => {
    const first = blocks[i] as { attrs: ParagraphAttrs }
    const listKind = first.attrs.list!.kind
    const numId = first.attrs.list!.numId
    const start = first.attrs.list!.start ?? 1
    const format = first.attrs.list!.format
    const items: TipTapNode[] = []

    while (
      i < blocks.length &&
      (blocks[i].type === 'paragraph' || blocks[i].type === 'heading') &&
      (blocks[i] as { attrs: ParagraphAttrs }).attrs.list?.numId === numId &&
      (blocks[i] as { attrs: ParagraphAttrs }).attrs.list?.kind === listKind
    ) {
      const cur = blocks[i] as { id: string | null; attrs: ParagraphAttrs; inlines: IntermediateInline[] }
      const curLevel = cur.attrs.list?.level ?? 0
      if (curLevel < targetLevel) break
      if (curLevel > targetLevel) {
        const nested = consumeListAtLevel(curLevel)
        const lastItem = items[items.length - 1]
        if (lastItem) {
          lastItem.content = [...(lastItem.content ?? []), nested]
        } else {
          items.push({ type: 'listItem', content: [nested] })
        }
        continue
      }

      i += 1
      const pAttrs = paragraphAttrsToTipTap(cur.attrs)
      if (cur.id) pAttrs.blockId = cur.id
      const itemContent: TipTapNode[] = [
        {
          type: 'paragraph',
          ...(Object.keys(pAttrs).length > 0 ? { attrs: pAttrs } : {}),
          content: inlinesToTipTap(cur.inlines, footnotesById, endnotesById),
        },
      ]
      while (
        i < blocks.length &&
        (blocks[i].type === 'paragraph' || blocks[i].type === 'heading') &&
        (blocks[i] as { attrs: ParagraphAttrs }).attrs.list?.numId === numId &&
        (blocks[i] as { attrs: ParagraphAttrs }).attrs.list?.kind === listKind &&
        ((blocks[i] as { attrs: ParagraphAttrs }).attrs.list?.level ?? 0) > targetLevel
      ) {
        const nextLevel = (blocks[i] as { attrs: ParagraphAttrs }).attrs.list?.level ?? 0
        itemContent.push(consumeListAtLevel(nextLevel))
      }
      items.push({
        type: 'listItem',
        content: itemContent,
      })
    }

    const listAttrs: Record<string, unknown> = {}
    if (listKind === 'ordered') {
      if (start !== 1) listAttrs.start = start
      if (format && format !== 'decimal') listAttrs.type = format
    }
    return {
      type: listKind === 'bullet' ? 'bulletList' : 'orderedList',
      ...(Object.keys(listAttrs).length > 0 ? { attrs: listAttrs } : {}),
      content: items,
    }
  }

  while (i < blocks.length) {
    const block = blocks[i]
    if ((block.type === 'paragraph' || block.type === 'heading') && block.attrs.list) {
      result.push(consumeListAtLevel(block.attrs.list.level ?? 0))
      continue
    }

    if (block.type === 'paragraph') {
      const attrs = paragraphAttrsToTipTap(block.attrs)
      if (block.id) attrs.blockId = block.id
      const content = inlinesToTipTap(block.inlines, footnotesById, endnotesById)
      result.push({
        type: 'paragraph',
        ...(Object.keys(attrs).length > 0 ? { attrs } : {}),
        ...(content.length > 0 ? { content } : {}),
      })
    } else if (block.type === 'heading') {
      const attrs: Record<string, unknown> = {
        level: block.level,
        ...paragraphAttrsToTipTap(block.attrs),
      }
      if (block.id) attrs.blockId = block.id
      result.push({
        type: 'heading',
        attrs,
        content: inlinesToTipTap(block.inlines, footnotesById, endnotesById),
      })
    } else if (block.type === 'table') {
      result.push({
        type: 'table',
        attrs: {
          ...(block.id ? { blockId: block.id } : {}),
          ...(block.width?.unit === 'dxa' ? { width: twipsToPixels(block.width.value) } : {}),
          gridColsDxa: block.gridColsDxa,
          ...(block.borders ? { borders: block.borders } : {}),
          ...(block.margins ? { margins: block.margins } : {}),
        },
        content: block.rows.map((row) => ({
          type: 'tableRow',
          attrs: {
            ...(row.isHeader ? { isHeader: true } : {}),
            ...(row.heightDxa != null ? { height: twipsToPixels(row.heightDxa), heightRule: row.heightRule ?? 'atLeast' } : {}),
          },
          content: row.cells.map((cell) => ({
            type: row.isHeader ? 'tableHeader' : 'tableCell',
            attrs: {
              colspan: cell.colspan,
              rowspan: cell.rowspan,
              ...(cell.colwidthDxa ? { colwidth: cell.colwidthDxa.map((d) => twipsToPixels(d)) } : {}),
              ...(cell.backgroundHex ? { background: `#${cell.backgroundHex.toLowerCase()}` } : {}),
              ...(cell.verticalAlign ? { verticalAlign: cell.verticalAlign === 'center' ? 'middle' : cell.verticalAlign } : {}),
              ...(cell.borders ? { borders: cell.borders } : {}),
              ...(cell.margins ? { margins: cell.margins } : {}),
            },
            content: blocksToTipTap(cell.blocks, footnotesById, endnotesById),
          })),
        })),
      })
    } else if (block.type === 'blockquote') {
      result.push({
        type: 'blockquote',
        ...(block.id ? { attrs: { blockId: block.id } } : {}),
        content: blocksToTipTap(block.blocks, footnotesById, endnotesById),
      })
    } else if (block.type === 'codeBlock') {
      result.push({
        type: 'codeBlock',
        attrs: {
          ...(block.id ? { blockId: block.id } : {}),
          ...(block.language ? { language: block.language } : {}),
        },
        content: block.text ? [{ type: 'text', text: block.text }] : [],
      })
    } else if (block.type === 'horizontalRule') {
      result.push({ type: 'horizontalRule' })
    } else if (block.type === 'pageBreak') {
      result.push({ type: 'pageBreak' })
    }

    i += 1
  }

  return result
}

export function fromIntermediate(
  model: IntermediateDocument,
  options: FromIntermediateOptions = {},
): FromIntermediateResult {
  const preserveSections = options.preserveSectionNodes ?? false
  const content: TipTapNode[] = []
  const footnotesById = new Map<number, IntermediateBlock[]>(
    (model.footnotes ?? []).map((note) => [note.id, note.blocks]),
  )
  const endnotesById = new Map<number, IntermediateBlock[]>(
    (model.endnotes ?? []).map((note) => [note.id, note.blocks]),
  )

  if (preserveSections && model.sections.length > 0) {
    for (const sec of model.sections) {
      content.push({
        type: 'section',
        attrs: {
          id: sec.id,
          type: sec.type,
          titlePg: sec.titlePg,
        },
        content: blocksToTipTap(sec.blocks, footnotesById, endnotesById),
      })
    }
  } else {
    for (let s = 0; s < model.sections.length; s += 1) {
      content.push(...blocksToTipTap(model.sections[s].blocks, footnotesById, endnotesById))
      if (s < model.sections.length - 1) {
        content.push({
          type: 'sectionBreak',
          attrs: { id: model.sections[s].id },
        })
      }
    }
  }

  return {
    document: {
      type: 'doc',
      content,
    },
    sections: model.sections,
    discussions: model.discussions,
    replacementGroups: model.replacementGroups,
    formattingRevisions: model.formattingRevisions,
    sourceMarker: model.sourceMarker,
    model,
  }
}

export function normalizeFontSizePt(halfPt: number): number {
  return ptToHalfPoints(halfPointsToPt(halfPt)) / 2
}

export interface ExportSuggestionInput {
  id: string
  kind: 'insert' | 'delete' | 'replace' | 'mark_change' | 'block_join' | string
  group_id?: string | null
  author?: string | null
  created_at?: string | null
  target_block_id: string
  from_utf16: number
  to_utf16: number
  before?: string | null
  after?: string | null
  payload?: Record<string, unknown> | null
}

function parseFormattingSummary(
  fc: FormattingChangeMeta,
): Array<{ operation: 'added' | 'removed'; markName: string; markAttrs?: Record<string, unknown> }> {
  if (fc.markChanges && fc.markChanges.length > 0) {
    return fc.markChanges
  }
  const out: Array<{ operation: 'added' | 'removed'; markName: string }> = []
  for (const part of fc.summary.split(',')) {
    const trimmed = part.trim()
    const match = /^(added|removed):([a-zA-Z0-9_]+)$/.exec(trimmed)
    if (match) {
      out.push({ operation: match[1] as 'added' | 'removed', markName: match[2] })
    }
  }
  return out
}

function cloneMarksForBaseline(inline: IntermediateTextInline): IntermediateMark[] {
  let marks = [...inline.marks]
  if (inline.formattingChange) {
    for (const change of parseFormattingSummary(inline.formattingChange)) {
      if (change.operation === 'added') {
        marks = marks.filter((m) => m.type !== change.markName)
      } else if (change.operation === 'removed') {
        const exists = marks.some((m) => m.type === change.markName)
        if (!exists) {
          marks.push({ type: change.markName } as IntermediateMark)
        }
      }
    }
  }
  return marks
}

function baselineInlinesFromBlock(inlines: readonly IntermediateInline[]): IntermediateInline[] {
  const out: IntermediateInline[] = []
  for (const inline of inlines) {
    if (inline.type === 'text') {
      if (inline.revision?.kind === 'insert') {
        continue
      }
      out.push({
        type: 'text',
        text: inline.text,
        marks: cloneMarksForBaseline(inline),
      })
    } else if (inline.type === 'hardBreak') {
      if (inline.revision?.kind === 'insert') {
        continue
      }
      out.push({ type: 'hardBreak' })
    } else if (inline.type === 'image' || inline.type === 'bookmark' || inline.type === 'footnoteRef' || inline.type === 'endnoteRef') {
      out.push(inline)
    }
  }
  return out
}

export function extractCleanImportPayload(model: IntermediateDocument): {
  document: TipTapDoc
  sections: Array<Record<string, unknown>>
  discussions: Array<Record<string, unknown>>
  revisions: Array<Record<string, unknown>>
} {
  const cleanSectionsModel: IntermediateSection[] = []
  const sectionsOut: Array<Record<string, unknown>> = []
  const revisionsOut: Array<Record<string, unknown>> = []

  // Map discussionId -> { blockIndex, fromUtf16, toUtf16, quoteText, prefix, suffix }
  const discussionAnchors = new Map<
    string,
    { blockIndex: number; fromUtf16: number; toUtf16: number; quoteText: string; prefix: string; suffix: string }
  >()
  const openDiscussionRanges = new Map<string, { blockIndex: number; start: number; text: string }>()

  let textblockCursor = 0

  const processBlockList = (blocks: readonly IntermediateBlock[]): IntermediateBlock[] => {
    const kept: IntermediateBlock[] = []
    for (const block of blocks) {
      if (block.type === 'pageBreak' || block.type === 'unsupportedBlock') {
        continue
      }
      if (block.type === 'paragraph' || block.type === 'heading') {
        const currentBlockIdx = textblockCursor++
        if (block.attrs.blockJoinDeletion && currentBlockIdx > 0) {
          revisionsOut.push({
            id: block.attrs.blockJoinDeletion.id,
            kind: 'block_join',
            group_id: block.attrs.blockJoinDeletion.groupId ?? null,
            author: block.attrs.blockJoinDeletion.author,
            created_at: block.attrs.blockJoinDeletion.date,
            block_index: currentBlockIdx - 1,
            second_block_index: currentBlockIdx,
            from_utf16: 0,
            to_utf16: 0,
            before: '',
            after: 'join',
          })
        }

        const cleanInlines = baselineInlinesFromBlock(block.inlines)
        const baseText = cleanInlines
          .map((inl) => (inl.type === 'text' ? inl.text : inl.type === 'hardBreak' ? '\n' : ' '))
          .join('')

        let offset = 0
        let lastDel: {
          id: string
          groupId: string | null
          from: number
          to: number
          text: string
          author: string
          date: string
        } | null = null

        for (const inline of block.inlines) {
          if (inline.type === 'text') {
            const len = inline.text.length
            const rev = inline.revision
            if (rev?.kind === 'insert') {
              if (lastDel && rev.groupId && lastDel.groupId === rev.groupId) {
                revisionsOut.push({
                  id: lastDel.id,
                  replacement_id: rev.id,
                  kind: 'replace',
                  group_id: rev.groupId,
                  author: rev.author,
                  created_at: rev.date,
                  block_index: currentBlockIdx,
                  from_utf16: lastDel.from,
                  to_utf16: lastDel.to,
                  before: lastDel.text,
                  after: inline.text,
                })
                lastDel = null
              } else {
                if (lastDel) {
                  revisionsOut.push({
                    id: lastDel.id,
                    kind: 'delete',
                    group_id: lastDel.groupId,
                    author: lastDel.author,
                    created_at: lastDel.date,
                    block_index: currentBlockIdx,
                    from_utf16: lastDel.from,
                    to_utf16: lastDel.to,
                    before: lastDel.text,
                    after: '',
                  })
                  lastDel = null
                }
                revisionsOut.push({
                  id: rev.id,
                  kind: 'insert',
                  group_id: rev.groupId ?? null,
                  author: rev.author,
                  created_at: rev.date,
                  block_index: currentBlockIdx,
                  from_utf16: offset,
                  to_utf16: offset,
                  before: '',
                  after: inline.text,
                })
              }
              continue
            }

            if (lastDel) {
              revisionsOut.push({
                id: lastDel.id,
                kind: 'delete',
                group_id: lastDel.groupId,
                author: lastDel.author,
                created_at: lastDel.date,
                block_index: currentBlockIdx,
                from_utf16: lastDel.from,
                to_utf16: lastDel.to,
                before: lastDel.text,
                after: '',
              })
              lastDel = null
            }

            const start = offset
            const end = offset + len
            offset = end

            if (rev?.kind === 'delete') {
              lastDel = {
                id: rev.id,
                groupId: rev.groupId ?? null,
                from: start,
                to: end,
                text: inline.text,
                author: rev.author,
                date: rev.date,
              }
            }

            if (inline.formattingChange && len > 0) {
              const parsed = parseFormattingSummary(inline.formattingChange)
              const firstChange = parsed[0] ?? { operation: 'added' as const, markName: 'bold' }
              revisionsOut.push({
                id: inline.formattingChange.id,
                kind: 'mark_change',
                group_id: null,
                author: inline.formattingChange.author,
                created_at: inline.formattingChange.date,
                block_index: currentBlockIdx,
                from_utf16: start,
                to_utf16: end,
                before: '',
                after: inline.formattingChange.summary,
                payload: {
                  mark_name: firstChange.markName,
                  mark_attrs: firstChange.markAttrs ?? {},
                  operation: firstChange.operation,
                  quote_text: inline.text,
                  summary: inline.formattingChange.summary,
                },
              })
            }

            const ids = new Set(inline.commentIds ?? [])
            for (const [dId, active] of [...openDiscussionRanges.entries()]) {
              if (!ids.has(dId) || active.blockIndex !== currentBlockIdx) {
                if (!discussionAnchors.has(dId) && active.text.length > 0) {
                  const pStart = Math.max(0, active.start - 24)
                  discussionAnchors.set(dId, {
                    blockIndex: active.blockIndex,
                    fromUtf16: active.start,
                    toUtf16: active.start + active.text.length,
                    quoteText: active.text,
                    prefix: baseText.slice(pStart, active.start),
                    suffix: baseText.slice(active.start + active.text.length, active.start + active.text.length + 24),
                  })
                }
                openDiscussionRanges.delete(dId)
              }
            }
            for (const dId of ids) {
              const existing = openDiscussionRanges.get(dId)
              if (existing && existing.blockIndex === currentBlockIdx) {
                existing.text += inline.text
              } else if (!discussionAnchors.has(dId)) {
                openDiscussionRanges.set(dId, { blockIndex: currentBlockIdx, start, text: inline.text })
              }
            }
          } else if (inline.type === 'hardBreak') {
            if (inline.revision?.kind !== 'insert') {
              offset += 1
            }
          } else if (inline.type === 'image') {
            offset += 1
          }
        }

        if (lastDel) {
          revisionsOut.push({
            id: lastDel.id,
            kind: 'delete',
            group_id: lastDel.groupId,
            author: lastDel.author,
            created_at: lastDel.date,
            block_index: currentBlockIdx,
            from_utf16: lastDel.from,
            to_utf16: lastDel.to,
            before: lastDel.text,
            after: '',
          })
        }

        for (const [dId, active] of [...openDiscussionRanges.entries()]) {
          if (active.blockIndex === currentBlockIdx && !discussionAnchors.has(dId) && active.text.length > 0) {
            const pStart = Math.max(0, active.start - 24)
            discussionAnchors.set(dId, {
              blockIndex: active.blockIndex,
              fromUtf16: active.start,
              toUtf16: active.start + active.text.length,
              quoteText: active.text,
              prefix: baseText.slice(pStart, active.start),
              suffix: baseText.slice(active.start + active.text.length, active.start + active.text.length + 24),
            })
          }
          openDiscussionRanges.delete(dId)
        }

        const { blockJoinDeletion: _bjd, formattingChange: _fc, ...cleanAttrs } = block.attrs
        kept.push({
          ...block,
          attrs: cleanAttrs,
          inlines: cleanInlines,
        })
      } else if (block.type === 'codeBlock') {
        textblockCursor++
        kept.push(block)
      } else if (block.type === 'blockquote') {
        kept.push({ ...block, blocks: processBlockList(block.blocks) })
      } else if (block.type === 'table') {
        kept.push({
          ...block,
          rows: block.rows.map((row) => ({
            ...row,
            cells: row.cells.map((cell) => ({
              ...cell,
              blocks: processBlockList(cell.blocks),
            })),
          })),
        })
      } else {
        kept.push(block)
      }
    }
    return kept
  }

  for (const sec of model.sections) {
    const pageBreakAfterBlocks: number[] = []
    const nonBreakBlocks: IntermediateBlock[] = []
    let topTipTapBlockCount = 0
    let activeListKey: string | null = null

    for (const block of sec.blocks) {
      if (block.type === 'pageBreak') {
        if (topTipTapBlockCount > 0) {
          pageBreakAfterBlocks.push(topTipTapBlockCount - 1)
        }
        activeListKey = null
        continue
      }
      if (block.type === 'unsupportedBlock') {
        continue
      }
      nonBreakBlocks.push(block)
      if ((block.type === 'paragraph' || block.type === 'heading') && block.attrs.list) {
        const key = `${block.attrs.list.kind}:${block.attrs.list.numId}`
        if (activeListKey !== key) {
          topTipTapBlockCount += 1
          activeListKey = key
        }
      } else {
        topTipTapBlockCount += 1
        activeListKey = null
      }
    }

    const cleanBlocks = processBlockList(nonBreakBlocks)
    cleanSectionsModel.push({ ...sec, blocks: cleanBlocks })

    const serializeSlot = (slot: HeaderFooterContent | null | undefined) => {
      if (!slot) return null
      return {
        type: 'doc',
        content: blocksToTipTap(slot.blocks),
      }
    }

    sectionsOut.push({
      id: sec.id,
      type: sec.type ?? 'nextPage',
      ...(sec.pageSize ? { pageSize: sec.pageSize } : {}),
      ...(sec.margins ? { margins: sec.margins } : {}),
      titlePg: sec.titlePg === true,
      headers: {
        ...(sec.headers.default ? { default: serializeSlot(sec.headers.default) } : {}),
        ...(sec.headers.first ? { first: serializeSlot(sec.headers.first) } : {}),
        ...(sec.headers.even ? { even: serializeSlot(sec.headers.even) } : {}),
      },
      footers: {
        ...(sec.footers.default ? { default: serializeSlot(sec.footers.default) } : {}),
        ...(sec.footers.first ? { first: serializeSlot(sec.footers.first) } : {}),
        ...(sec.footers.even ? { even: serializeSlot(sec.footers.even) } : {}),
      },
      blockCount: topTipTapBlockCount,
      ...(pageBreakAfterBlocks.length > 0 ? { pageBreakAfterBlocks } : {}),
    })
  }

  const discussionsOut = model.discussions.map((d) => {
    const anchor = discussionAnchors.get(d.id)
    return {
      id: d.id,
      anchorState: anchor ? 'attached' : d.anchorState,
      resolved: d.resolved,
      quoteText: anchor?.quoteText ?? d.quoteText ?? '',
      blockIndex: anchor?.blockIndex ?? null,
      fromUtf16: anchor?.fromUtf16 ?? 0,
      toUtf16: anchor?.toUtf16 ?? 0,
      prefix: anchor?.prefix ?? '',
      suffix: anchor?.suffix ?? '',
      comments: d.comments.map((c) => ({
        id: c.id,
        parentId: c.parentId ?? null,
        author: c.author,
        initials: c.initials ?? null,
        createdAt: c.createdAt,
        body: c.bodyParagraphs.join('\n'),
      })),
    }
  })

  const cleanDocContent: TipTapNode[] = []
  for (const sec of cleanSectionsModel) {
    cleanDocContent.push(...blocksToTipTap(sec.blocks))
  }

  return {
    document: { type: 'doc', content: cleanDocContent },
    sections: sectionsOut,
    discussions: discussionsOut,
    revisions: revisionsOut,
  }
}

export function decorateDocumentForExport(
  document: TipTapDoc,
  suggestions: readonly ExportSuggestionInput[],
  projection: DocxProjectionMode,
): TipTapDoc {
  if (projection === 'original' || suggestions.length === 0) {
    return document
  }

  const byBlock = new Map<string, ExportSuggestionInput[]>()
  const blockJoins: ExportSuggestionInput[] = []
  for (const s of suggestions) {
    if (s.kind === 'block_join') {
      blockJoins.push(s)
      continue
    }
    const list = byBlock.get(s.target_block_id) ?? []
    list.push(s)
    byBlock.set(s.target_block_id, list)
  }

  const applyInlineToContent = (
    content: readonly TipTapNode[],
    items: readonly ExportSuggestionInput[],
  ): TipTapNode[] => {
    let current = [...content]
    const sorted = [...items].sort((a, b) => b.to_utf16 - a.to_utf16 || b.from_utf16 - a.from_utf16)

    for (const s of sorted) {
      const from = s.from_utf16
      const to = s.to_utf16
      const author = s.author ?? 'Unknown Author'
      const createdAt = s.created_at ?? '1970-01-01T00:00:00Z'
      const nextContent: TipTapNode[] = []
      let pos = 0
      let insertedAtZero = false

      const buildSuggMark = (
        type: 'add' | 'delete' | 'replaceDeletion' | 'replaceInsertion' | 'markChange',
        id: string,
        extra: Record<string, unknown> = {},
      ): TipTapMark => ({
        type: 'suggestion',
        attrs: {
          id,
          type,
          ...(s.group_id ? { groupId: s.group_id } : {}),
          author,
          createdAt,
          userMetadata: { name: author },
          ...extra,
        },
      })

      if (s.kind === 'insert' && from === 0 && current.length === 0) {
        if (projection === 'proposed') {
          nextContent.push({ type: 'text', text: s.after ?? '' })
        } else {
          nextContent.push({
            type: 'text',
            text: s.after ?? '',
            marks: [buildSuggMark('add', s.id)],
          })
        }
        current = nextContent
        continue
      }

      for (const inline of current) {
        if (inline.type !== 'text') {
          if (s.kind === 'insert' && !insertedAtZero && pos === from) {
            insertedAtZero = true
            nextContent.push(
              projection === 'proposed'
                ? { type: 'text', text: s.after ?? '' }
                : { type: 'text', text: s.after ?? '', marks: [buildSuggMark('add', s.id)] },
            )
          }
          nextContent.push(inline)
          pos += 1
          continue
        }

        const text = inline.text ?? ''
        const len = text.length
        const start = pos
        const end = pos + len
        pos = end

        if (s.kind === 'insert') {
          if (!insertedAtZero && from >= start && from <= end) {
            insertedAtZero = true
            const rel = from - start
            const pre = text.slice(0, rel)
            const post = text.slice(rel)
            if (pre !== '') nextContent.push({ ...inline, text: pre })
            nextContent.push(
              projection === 'proposed'
                ? { type: 'text', text: s.after ?? '', ...(inline.marks ? { marks: inline.marks } : {}) }
                : {
                    type: 'text',
                    text: s.after ?? '',
                    marks: [...(inline.marks ?? []), buildSuggMark('add', s.id)],
                  },
            )
            if (post !== '') nextContent.push({ ...inline, text: post })
          } else {
            nextContent.push(inline)
          }
          continue
        }

        if (end <= from || start >= to) {
          nextContent.push(inline)
          continue
        }

        const relStart = Math.max(0, from - start)
        const relEnd = Math.min(len, to - start)
        const pre = text.slice(0, relStart)
        const mid = text.slice(relStart, relEnd)
        const post = text.slice(relEnd)
        if (pre !== '') nextContent.push({ ...inline, text: pre })

        if (s.kind === 'delete') {
          if (projection === 'review' && mid !== '') {
            nextContent.push({
              ...inline,
              text: mid,
              marks: [...(inline.marks ?? []), buildSuggMark('delete', s.id)],
            })
          }
        } else if (s.kind === 'replace') {
          if (projection === 'proposed') {
            nextContent.push({
              type: 'text',
              text: s.after ?? '',
              ...(inline.marks ? { marks: inline.marks } : {}),
            })
          } else {
            if (mid !== '') {
              nextContent.push({
                ...inline,
                text: mid,
                marks: [...(inline.marks ?? []), buildSuggMark('replaceDeletion', s.id)],
              })
            }
            if ((s.after ?? '') !== '') {
              nextContent.push({
                ...inline,
                text: s.after ?? '',
                marks: [...(inline.marks ?? []), buildSuggMark('replaceInsertion', `${s.id}_ins`)],
              })
            }
          }
        } else if (s.kind === 'mark_change') {
          const markName = String(s.payload?.mark_name ?? 'bold')
          const op = String(s.payload?.operation ?? 'added')
          const baseMarks = (inline.marks ?? []).filter((m) => m.type !== markName)
          const proposedMarks =
            op === 'added'
              ? [...baseMarks, { type: markName, ...(s.payload?.mark_attrs ? { attrs: s.payload.mark_attrs as Record<string, unknown> } : {}) }]
              : baseMarks
          if (projection === 'proposed') {
            nextContent.push({ ...inline, text: mid, ...(proposedMarks.length > 0 ? { marks: proposedMarks } : {}) })
          } else {
            nextContent.push({
              ...inline,
              text: mid,
              marks: [
                ...proposedMarks,
                buildSuggMark('markChange', s.id, {
                  markChanges: [{ operation: op === 'removed' ? 'removed' : 'added', markName }],
                }),
              ],
            })
          }
        }

        if (post !== '') nextContent.push({ ...inline, text: post })
      }

      if (s.kind === 'insert' && !insertedAtZero) {
        nextContent.push(
          projection === 'proposed'
            ? { type: 'text', text: s.after ?? '' }
            : { type: 'text', text: s.after ?? '', marks: [buildSuggMark('add', s.id)] },
        )
      }

      current = nextContent
    }

    return current
  }

  const joinSecondById = new Map<string, ExportSuggestionInput>()
  for (const bj of blockJoins) {
    const secondId = typeof bj.payload?.second_block_id === 'string' ? bj.payload.second_block_id : null
    if (secondId) {
      joinSecondById.set(secondId, bj)
    }
  }

  const walk = (node: TipTapNode): TipTapNode => {
    const blockId = typeof node.attrs?.blockId === 'string' ? node.attrs.blockId : null
    let next = { ...node }
    if (blockId && (node.type === 'paragraph' || node.type === 'heading')) {
      const items = byBlock.get(blockId)
      if (items && items.length > 0) {
        next.content = applyInlineToContent(node.content ?? [], items)
      }
      if (projection === 'review' && joinSecondById.has(blockId)) {
        const bj = joinSecondById.get(blockId)!
        next.attrs = {
          ...(next.attrs ?? {}),
          blockJoinDeletion: {
            id: bj.id,
            kind: 'delete',
            author: bj.author ?? 'Unknown Author',
            date: bj.created_at ?? '1970-01-01T00:00:00Z',
          },
        }
      }
    } else if (Array.isArray(node.content)) {
      if (projection === 'proposed' && blockJoins.length > 0) {
        const mapped: TipTapNode[] = []
        for (let i = 0; i < node.content.length; i += 1) {
          const child = walk(node.content[i])
          const childId = typeof child.attrs?.blockId === 'string' ? child.attrs.blockId : null
          if (childId && joinSecondById.has(childId) && mapped.length > 0) {
            const prev = mapped[mapped.length - 1]
            prev.content = [...(prev.content ?? []), ...(child.content ?? [])]
          } else {
            mapped.push(child)
          }
        }
        next.content = mapped
      } else {
        next.content = node.content.map(walk)
      }
    }
    return next
  }

  return walk(document) as TipTapDoc
}
