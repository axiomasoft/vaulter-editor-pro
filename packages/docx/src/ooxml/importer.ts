import {
  DOCX_MODEL_SCHEMA,
  VAULTER_DOCX_CUSTOM_XML_NS,
  type DocxProfileId,
  type FormattingRevisionRecord,
  type HeaderFooterContent,
  type InlineRevisionMeta,
  type IntermediateBlock,
  type IntermediateComment,
  type IntermediateDiscussion,
  type IntermediateDocument,
  type IntermediateInline,
  type IntermediateMark,
  type IntermediateSection,
  type IntermediateTableCell,
  type IntermediateTableRow,
  type NumberingDefinition,
  type ParagraphAttrs,
  type ReplacementGroupMetadata,
  type SourceMarker,
  type UnderlineType,
} from '../model/types'
import { getProfileCapabilities, type ProfileFeatureOptions } from '../model/profiles'
import { LossReportBuilder } from '../model/lossReport'
import { DocxSecurityError, type ZipQuotas, type ZipReader } from '../zip/guard'
import { isValidOoxmlHexId } from './ids'
import { extractParagraphProps, extractRunProps, StyleRegistry } from './styles'
import { isSafeHyperlinkUrl, normalizeHexColor, parseOoxmlWidth } from './units'
import {
  decodeUtf8,
  findAllDescendants,
  findChild,
  findChildren,
  getAttr,
  parseXmlPart,
  type XmlElementNode,
} from './xmlParser'

export interface ImportOoxmlOptions extends ProfileFeatureOptions {
  profile?: DocxProfileId
  quotas?: ZipQuotas
  sourceMarker?: SourceMarker | null
}

interface ParsedRelationship {
  id: string
  type: string
  target: string
  resolvedPath: string | null
  external: boolean
}

interface ParsedCommentMeta {
  wId: number
  author: string
  initials: string | null
  date: string
  bodyParagraphs: string[]
  lastParaId: string | null
}

interface CustomReviewMetadata {
  sourceMarker: SourceMarker | null
  replacements: Array<{
    groupId: string
    deletionRevisionId: string
    insertionRevisionId: string
    deletionOoxmlId?: number
    insertionOoxmlId?: number
    author?: string | null
    date?: string | null
  }>
  discussions: Array<{
    id: string
    wId?: number
    anchorState: 'attached' | 'detached' | 'historical'
    resolved: boolean
    quoteText?: string | null
    blockId?: string | null
    sourcePosition?: number | null
    generation?: number | null
  }>
}

function normalizePosixPath(baseDir: string, target: string): string | null {
  const raw = target.startsWith('/') ? target.slice(1) : baseDir ? `${baseDir}/${target}` : target
  const parts = raw.split('/')
  const stack: string[] = []
  for (const part of parts) {
    if (part === '' || part === '.') {
      continue
    }
    if (part === '..') {
      if (stack.length === 0) {
        return null
      }
      stack.pop()
      continue
    }
    stack.push(part)
  }
  return stack.join('/')
}

function parseRelationships(
  relsXml: string,
  baseDir: string,
  partName: string,
): Map<string, ParsedRelationship> {
  const root = parseXmlPart(relsXml, partName)
  const map = new Map<string, ParsedRelationship>()

  for (const rel of findChildren(root, 'Relationship')) {
    const id = getAttr(rel, 'Id') ?? ''
    const type = getAttr(rel, 'Type') ?? ''
    const target = getAttr(rel, 'Target') ?? ''
    const targetMode = (getAttr(rel, 'TargetMode') ?? '').toLowerCase()
    const external = targetMode === 'external'

    if (external) {
      if (!type.endsWith('/relationships/hyperlink')) {
        throw new DocxSecurityError(
          'external_rel_forbidden',
          `External relationship "${id}" in "${partName}" has forbidden type "${type}". Only relationships/hyperlink is allowed.`,
        )
      }
      if (!isSafeHyperlinkUrl(target)) {
        throw new DocxSecurityError(
          'external_rel_forbidden',
          `External hyperlink "${target}" in "${partName}" uses an unsafe URL scheme.`,
        )
      }
      map.set(id, { id, type, target, resolvedPath: null, external: true })
    } else {
      const resolvedPath = normalizePosixPath(baseDir, target)
      if (resolvedPath === null) {
        throw new DocxSecurityError(
          'zip_slip',
          `Internal relationship "${target}" in "${partName}" escapes package root.`,
        )
      }
      map.set(id, { id, type, target, resolvedPath, external: false })
    }
  }

  return map
}

function parseNumbering(xmlNode: XmlElementNode | null): Map<number, NumberingDefinition> {
  const abstractDefs = new Map<number, { kind: 'bullet' | 'ordered'; start: number; format: string }>()
  const numMap = new Map<number, NumberingDefinition>()

  if (!xmlNode) {
    return numMap
  }

  for (const abs of findChildren(xmlNode, 'abstractNum')) {
    const absId = Number.parseInt(getAttr(abs, 'w:abstractNumId', 'abstractNumId') ?? '', 10)
    if (!Number.isFinite(absId)) {
      continue
    }
    const lvl0 = findChildren(abs, 'lvl').find((l) => getAttr(l, 'w:ilvl', 'ilvl') === '0') ?? findChild(abs, 'lvl')
    const numFmtEl = lvl0 ? findChild(lvl0, 'numFmt') : undefined
    const startEl = lvl0 ? findChild(lvl0, 'start') : undefined
    const format = getAttr(numFmtEl ?? abs, 'w:val', 'val') ?? 'decimal'
    const startRaw = startEl ? Number.parseInt(getAttr(startEl, 'w:val', 'val') ?? '1', 10) : 1
    abstractDefs.set(absId, {
      kind: format === 'bullet' ? 'bullet' : 'ordered',
      start: Number.isFinite(startRaw) ? startRaw : 1,
      format,
    })
  }

  for (const numEl of findChildren(xmlNode, 'num')) {
    const numId = Number.parseInt(getAttr(numEl, 'w:numId', 'numId') ?? '', 10)
    const absRefEl = findChild(numEl, 'abstractNumId')
    const absId = Number.parseInt(getAttr(absRefEl ?? numEl, 'w:val', 'val') ?? '', 10)
    const base = abstractDefs.get(absId)
    if (!Number.isFinite(numId) || !base) {
      continue
    }
    let start = base.start
    const lvlOverride = findChild(numEl, 'lvlOverride')
    const startOverride = lvlOverride ? findChild(lvlOverride, 'startOverride') : undefined
    if (startOverride) {
      const overrideVal = Number.parseInt(getAttr(startOverride, 'w:val', 'val') ?? '', 10)
      if (Number.isFinite(overrideVal)) {
        start = overrideVal
      }
    }
    numMap.set(numId, {
      numId,
      kind: base.kind,
      start,
      format: base.format,
    })
  }

  return numMap
}

function parseCustomReviewMetadata(
  entries: Record<string, Uint8Array>,
  docRels: ReadonlyMap<string, ParsedRelationship>,
): CustomReviewMetadata {
  const out: CustomReviewMetadata = {
    sourceMarker: null,
    replacements: [],
    discussions: [],
  }

  const candidatePaths = new Set<string>()
  for (const rel of docRels.values()) {
    if (rel.type.endsWith('/customXml') && rel.resolvedPath && entries[rel.resolvedPath]) {
      candidatePaths.add(rel.resolvedPath)
    }
  }
  for (const key of Object.keys(entries)) {
    if (key.startsWith('customXml/') && key.endsWith('.xml')) {
      candidatePaths.add(key)
    }
  }

  for (const path of candidatePaths) {
    const raw = decodeUtf8(entries[path])
    const root = parseXmlPart(raw, path)
    if (root.localName !== 'review') {
      continue
    }
    const xmlns = getAttr(root, 'xmlns')
    if (xmlns && xmlns !== VAULTER_DOCX_CUSTOM_XML_NS && xmlns !== 'urn:vaulter:lab:review:1') {
      continue
    }

    const rawMarker = getAttr(root, 'sourceMarker')
    if (rawMarker) {
      try {
        out.sourceMarker = JSON.parse(rawMarker) as SourceMarker
      } catch {
        // ignore invalid marker string
      }
    }

    for (const rep of findChildren(root, 'replacement')) {
      const delAttr = getAttr(rep, 'deletion', 'deletionId') ?? ''
      const insAttr = getAttr(rep, 'insertion', 'insertionId') ?? ''
      const delNum = Number.parseInt(getAttr(rep, 'deletionOoxmlId', 'deletion') ?? '', 10)
      const insNum = Number.parseInt(getAttr(rep, 'insertionOoxmlId', 'insertion') ?? '', 10)
      const groupId = getAttr(rep, 'groupId') ?? `grp_${delAttr}_${insAttr}`

      out.replacements.push({
        groupId,
        deletionRevisionId: delAttr,
        insertionRevisionId: insAttr,
        ...(Number.isFinite(delNum) ? { deletionOoxmlId: delNum } : {}),
        ...(Number.isFinite(insNum) ? { insertionOoxmlId: insNum } : {}),
        author: getAttr(rep, 'author') ?? null,
        date: getAttr(rep, 'date') ?? null,
      })
    }

    for (const d of findChildren(root, 'discussion')) {
      const id = getAttr(d, 'id')
      if (!id) continue
      const wIdNum = Number.parseInt(getAttr(d, 'wId') ?? '', 10)
      const genNum = Number.parseInt(getAttr(d, 'generation') ?? '', 10)
      const posNum = Number.parseInt(getAttr(d, 'sourcePosition') ?? '', 10)
      const stateRaw = getAttr(d, 'anchorState')
      const anchorState =
        stateRaw === 'detached' || stateRaw === 'historical' ? stateRaw : 'attached'
      out.discussions.push({
        id,
        ...(Number.isFinite(wIdNum) ? { wId: wIdNum } : {}),
        anchorState,
        resolved: getAttr(d, 'resolved') === '1' || getAttr(d, 'resolved') === 'true',
        quoteText: getAttr(d, 'quoteText') ?? null,
        blockId: getAttr(d, 'blockId') ?? null,
        sourcePosition: Number.isFinite(posNum) ? posNum : null,
        generation: Number.isFinite(genNum) ? genNum : null,
      })
    }
  }

  return out
}

function collectRunText(node: XmlElementNode, targetTag: 't' | 'delText'): string {
  const parts: string[] = []
  for (const child of node.children) {
    if (child.localName === targetTag) {
      parts.push(child.text)
    } else if (child.localName === 'r' || child.localName === 'ins' || child.localName === 'del') {
      parts.push(collectRunText(child, targetTag))
    }
  }
  return parts.join('')
}

function parseCommentsParts(
  entries: Record<string, Uint8Array>,
  anchorOrderWIds: readonly number[],
  customMeta: CustomReviewMetadata,
  diagnostics: LossReportBuilder,
  discussionsEnabled: boolean,
  threadedComments: boolean,
): {
  discussions: IntermediateDiscussion[]
  discussionIdByWId: Map<number, string>
} {
  const discussionIdByWId = new Map<number, string>()
  if (!discussionsEnabled || !entries['word/comments.xml']) {
    return { discussions: [], discussionIdByWId }
  }

  const commentsRoot = parseXmlPart(decodeUtf8(entries['word/comments.xml']), 'word/comments.xml')
  const commentMetas: ParsedCommentMeta[] = []
  const metaByParaId = new Map<string, ParsedCommentMeta>()

  for (const cNode of findChildren(commentsRoot, 'comment')) {
    const rawId = getAttr(cNode, 'w:id', 'id')
    const wId = Number.parseInt(rawId ?? '', 10)
    if (!Number.isFinite(wId)) {
      continue
    }
    const author = getAttr(cNode, 'w:author', 'author') ?? 'Unknown'
    const initials = getAttr(cNode, 'w:initials', 'initials') ?? null
    const date = getAttr(cNode, 'w:date', 'date') ?? '1970-01-01T00:00:00Z'

    const pNodes = findChildren(cNode, 'p')
    const bodyParagraphs = pNodes.map((p) => collectRunText(p, 't'))
    const lastP = pNodes[pNodes.length - 1]
    const lastParaId = lastP ? getAttr(lastP, 'w14:paraId', 'paraId') ?? null : null

    if (lastParaId !== null && !isValidOoxmlHexId(lastParaId)) {
      diagnostics.warn(
        'INVALID_COMMENT_PARA_ID',
        'w14:paraId',
        `word/comments.xml#comment:${wId}`,
        'degraded',
        `Comment ${wId} has out-of-range w14:paraId "${lastParaId}" (expected 00000001..7FFFFFFF).`,
      )
    }

    const meta: ParsedCommentMeta = {
      wId,
      author,
      initials,
      date,
      bodyParagraphs: bodyParagraphs.length > 0 ? bodyParagraphs : [''],
      lastParaId,
    }
    commentMetas.push(meta)
    if (lastParaId) {
      metaByParaId.set(lastParaId.toUpperCase(), meta)
    }
  }

  const durableByParaId = new Map<string, string>()
  if (entries['word/commentsIds.xml']) {
    const idsRoot = parseXmlPart(decodeUtf8(entries['word/commentsIds.xml']), 'word/commentsIds.xml')
    for (const item of findChildren(idsRoot, 'commentId')) {
      const paraId = (getAttr(item, 'w16cid:paraId', 'paraId') ?? '').toUpperCase()
      const durableId = (getAttr(item, 'w16cid:durableId', 'durableId') ?? '').toUpperCase()
      if (durableId && !isValidOoxmlHexId(durableId)) {
        diagnostics.warn(
          'INVALID_COMMENT_DURABLE_ID',
          'w16cid:durableId',
          `word/commentsIds.xml#${paraId}`,
          'degraded',
          `Comment durableId "${durableId}" is outside 00000001..7FFFFFFF and will be re-allocated on export.`,
        )
      }
      if (paraId && durableId) {
        durableByParaId.set(paraId, durableId)
      }
    }
  }

  const extByParaId = new Map<string, { paraIdParent: string | null; done: boolean }>()
  const hasExtended = Boolean(entries['word/commentsExtended.xml'])
  if (hasExtended) {
    const extRoot = parseXmlPart(
      decodeUtf8(entries['word/commentsExtended.xml']),
      'word/commentsExtended.xml',
    )
    for (const ex of findChildren(extRoot, 'commentEx')) {
      const paraId = (getAttr(ex, 'w15:paraId', 'paraId') ?? '').toUpperCase()
      const parent = (getAttr(ex, 'w15:paraIdParent', 'paraIdParent') ?? '').toUpperCase() || null
      const doneRaw = getAttr(ex, 'w15:done', 'done')
      const done = doneRaw === '1' || doneRaw === 'true'
      if (paraId) {
        extByParaId.set(paraId, { paraIdParent: parent, done })
      }
    }
  } else if (commentMetas.length > 0 && threadedComments) {
    diagnostics.warn(
      'COMMENTS_EXTENDED_MISSING',
      'commentsExtended.xml',
      'word/commentsExtended.xml',
      'degraded',
      'word/commentsExtended.xml is absent (e.g. LibreOffice export); falling back to commentRangeStart/End grouping.',
    )
  }

  const anchoredSet = new Set(anchorOrderWIds)
  const customByWId = new Map<number, CustomReviewMetadata['discussions'][number]>()
  for (const d of customMeta.discussions) {
    if (d.wId !== undefined) {
      customByWId.set(d.wId, d)
    }
  }

  // Group into threads:
  const rootMetaByChildWId = new Map<number, ParsedCommentMeta>()
  const childrenByRootWId = new Map<number, ParsedCommentMeta[]>()

  if (hasExtended) {
    const findRootMeta = (meta: ParsedCommentMeta): ParsedCommentMeta => {
      const visited = new Set<number>()
      let current = meta
      while (current.lastParaId && !visited.has(current.wId)) {
        visited.add(current.wId)
        const ext = extByParaId.get(current.lastParaId.toUpperCase())
        if (!ext?.paraIdParent) {
          break
        }
        const parent = metaByParaId.get(ext.paraIdParent.toUpperCase())
        if (!parent) {
          break
        }
        current = parent
      }
      return current
    }

    for (const meta of commentMetas) {
      const rootMeta = findRootMeta(meta)
      rootMetaByChildWId.set(meta.wId, rootMeta)
      const list = childrenByRootWId.get(rootMeta.wId) ?? []
      list.push(meta)
      childrenByRootWId.set(rootMeta.wId, list)
    }
  } else {
    // Fallback without commentsExtended.xml: anchored comments are roots; unanchored without custom detached metadata stay standalone.
    for (const meta of commentMetas) {
      rootMetaByChildWId.set(meta.wId, meta)
      childrenByRootWId.set(meta.wId, [meta])
    }
  }

  const discussions: IntermediateDiscussion[] = []
  const seenRoots = new Set<number>()

  for (const meta of commentMetas) {
    const rootMeta = rootMetaByChildWId.get(meta.wId) ?? meta
    if (seenRoots.has(rootMeta.wId)) {
      continue
    }
    seenRoots.add(rootMeta.wId)

    const members = childrenByRootWId.get(rootMeta.wId) ?? [rootMeta]
    const custom = customByWId.get(rootMeta.wId)
    const discussionId = custom?.id ?? `thread_${rootMeta.wId}`

    for (const member of members) {
      discussionIdByWId.set(member.wId, discussionId)
    }

    const rootExt = rootMeta.lastParaId ? extByParaId.get(rootMeta.lastParaId.toUpperCase()) : undefined
    const resolved = custom?.resolved ?? rootExt?.done ?? false
    const isAnchored = anchoredSet.has(rootMeta.wId)
    const anchorState: IntermediateDiscussion['anchorState'] =
      custom?.anchorState ?? (isAnchored ? 'attached' : 'detached')

    const comments: IntermediateComment[] = members.map((m, idx) => {
      const paraUpper = m.lastParaId?.toUpperCase()
      const ext = paraUpper ? extByParaId.get(paraUpper) : undefined
      const parentMeta = ext?.paraIdParent ? metaByParaId.get(ext.paraIdParent.toUpperCase()) : undefined
      const parentId = idx === 0 ? null : parentMeta ? `comment_${parentMeta.wId}` : `comment_${rootMeta.wId}`
      const durableId = paraUpper ? durableByParaId.get(paraUpper) : undefined

      return {
        id: `comment_${m.wId}`,
        parentId,
        author: m.author,
        initials: m.initials,
        createdAt: m.date,
        bodyParagraphs: m.bodyParagraphs,
        ooxml: {
          wId: m.wId,
          ...(m.lastParaId ? { paraId: m.lastParaId } : {}),
          ...(durableId ? { durableId } : {}),
        },
      }
    })

    discussions.push({
      id: discussionId,
      anchorState,
      quoteText: custom?.quoteText ?? null,
      blockId: custom?.blockId ?? null,
      sourcePosition: custom?.sourcePosition ?? null,
      generation: custom?.generation ?? null,
      resolved,
      comments,
    })
  }

  return { discussions, discussionIdByWId }
}

interface ParseContext {
  styles: StyleRegistry
  numbering: Map<number, NumberingDefinition>
  rels: ReadonlyMap<string, ParsedRelationship>
  diagnostics: LossReportBuilder
  formattingRevisions: FormattingRevisionRecord[]
  activeCommentWIds: Set<number>
  anchorOrderWIds: number[]
  commentIdLookup?: ReadonlyMap<number, string>
  replacementByOoxmlId: ReadonlyMap<number, { groupId: string; role: 'del' | 'ins' }>
  revisionsEnabled: boolean
  requireFormattingRevisions: boolean
}

function parseRunElement(
  rNode: XmlElementNode,
  paragraphStyle: ReturnType<StyleRegistry['resolveParagraph']>,
  linkHref: string | null,
  revision: InlineRevisionMeta | null,
  ctx: ParseContext,
  location: string,
): IntermediateInline[] {
  const rPr = findChild(rNode, 'rPr')
  const rStyleEl = rPr ? findChild(rPr, 'rStyle') : undefined
  const rStyleId = rStyleEl ? getAttr(rStyleEl, 'w:val', 'val') ?? null : null
  const inlineRPr = extractRunProps(rPr)
  const resolved = ctx.styles.resolveRun({ paragraphStyle, rStyleId, inlineRPr })

  const rPrChange = rPr ? findChild(rPr, 'rPrChange') : undefined
  let formattingChange: NonNullable<Extract<IntermediateInline, { type: 'text' }>['formattingChange']> | null = null
  if (rPrChange) {
    const fcId = getAttr(rPrChange, 'w:id', 'id') ?? `rpr_${location}`
    const fcAuthor = getAttr(rPrChange, 'w:author', 'author') ?? 'Unknown'
    const fcDate = getAttr(rPrChange, 'w:date', 'date') ?? '1970-01-01T00:00:00Z'
    const prevProps = extractRunProps(findChild(rPrChange, 'rPr'))
    const summaryParts: string[] = []
    if (resolved.bold && !prevProps.bold) summaryParts.push('added:bold')
    if (resolved.italic && !prevProps.italic) summaryParts.push('added:italic')
    if (summaryParts.length === 0) summaryParts.push('run formatting change')
    const summary = summaryParts.join(', ')

    ctx.formattingRevisions.push({
      id: fcId,
      targetType: 'run',
      location,
      author: fcAuthor,
      date: fcDate,
      summary,
    })

    if (ctx.requireFormattingRevisions) {
      ctx.diagnostics.block(
        'NATIVE_FORMATTING_REVISION_UNSUPPORTED',
        'w:rPrChange',
        location,
        `Mandatory native formatting revision (${summary}) in w:rPrChange cannot be losslessly roundtripped.`,
      )
    } else {
      ctx.diagnostics.warn(
        'NATIVE_FORMATTING_REVISION',
        'w:rPrChange',
        location,
        'degraded',
        `Native formatting revision (${summary}) by ${fcAuthor} recorded in loss report (not silently accepted).`,
      )
    }

    formattingChange = {
      id: fcId,
      targetType: 'run',
      author: fcAuthor,
      date: fcDate,
      summary,
      ooxmlId: Number.parseInt(fcId, 10) || undefined,
    }
  }

  const marks: IntermediateMark[] = []
  if (resolved.bold) marks.push({ type: 'bold' })
  if (resolved.italic) marks.push({ type: 'italic' })
  if (resolved.underline && resolved.underline !== 'none') {
    marks.push({ type: 'underline', underlineType: resolved.underline as UnderlineType })
  }
  if (resolved.strike) marks.push({ type: 'strike' })
  if (resolved.superscript) marks.push({ type: 'superscript' })
  if (resolved.subscript) marks.push({ type: 'subscript' })
  if (resolved.highlightHex) marks.push({ type: 'highlight', colorHex: resolved.highlightHex })
  if (resolved.fontFamily || resolved.fontSizeHalfPt || resolved.colorHex) {
    marks.push({
      type: 'textStyle',
      fontFamily: resolved.fontFamily,
      fontSizeHalfPt: resolved.fontSizeHalfPt,
      colorHex: resolved.colorHex,
    })
  }
  if (linkHref) {
    marks.push({ type: 'link', href: linkHref })
  }

  const out: IntermediateInline[] = []
  const commentIds = [...ctx.activeCommentWIds].map((wId) => ctx.commentIdLookup?.get(wId) ?? `thread_${wId}`)

  for (const child of rNode.children) {
    if (child.localName === 't' || child.localName === 'delText') {
      out.push({
        type: 'text',
        text: child.text,
        marks,
        ...(commentIds.length > 0 ? { commentIds } : {}),
        ...(revision ? { revision } : {}),
        ...(formattingChange ? { formattingChange } : {}),
      })
    } else if (child.localName === 'br') {
      const brType = getAttr(child, 'w:type', 'type')
      if (brType !== 'page') {
        out.push({
          type: 'hardBreak',
          ...(commentIds.length > 0 ? { commentIds } : {}),
          ...(revision ? { revision } : {}),
        })
      }
    } else if (child.localName === 'oleObject' || child.localName === 'object') {
      ctx.diagnostics.block(
        'UNSUPPORTED_MANDATORY_ELEMENT',
        child.name,
        location,
        `Mandatory embedded object "${child.name}" is forbidden/unsupported.`,
      )
      out.push({
        type: 'unsupportedInline',
        elementName: child.name,
        mandatory: true,
      })
    }
  }

  return out
}

function parseParagraph(
  pNode: XmlElementNode,
  tblStyleId: string | null,
  ctx: ParseContext,
  location: string,
): { blocks: IntermediateBlock[]; sectionEl: XmlElementNode | null } {
  const pPr = findChild(pNode, 'pPr')
  const pStyleEl = pPr ? findChild(pPr, 'pStyle') : undefined
  const pStyleId = pStyleEl ? getAttr(pStyleEl, 'w:val', 'val') ?? null : null
  const inlinePPr = extractParagraphProps(pPr)
  const resolvedP = ctx.styles.resolveParagraph({ tblStyleId, pStyleId, inlinePPr })
  const sectionEl = pPr ? findChild(pPr, 'sectPr') ?? null : null

  let listBinding: ParagraphAttrs['list'] = null
  const numPr = pPr ? findChild(pPr, 'numPr') : undefined
  if (numPr) {
    const numIdEl = findChild(numPr, 'numId')
    const ilvlEl = findChild(numPr, 'ilvl')
    const numId = Number.parseInt(getAttr(numIdEl ?? numPr, 'w:val', 'val') ?? '', 10)
    const level = Number.parseInt(getAttr(ilvlEl ?? numPr, 'w:val', 'val') ?? '0', 10)
    const def = ctx.numbering.get(numId)
    if (Number.isFinite(numId) && def) {
      listBinding = {
        kind: def.kind,
        level: Number.isFinite(level) ? level : 0,
        numId,
        start: def.start,
        format: def.format,
      }
    }
  }

  // block_join deletion in <w:pPr><w:rPr><w:del .../></w:rPr></w:pPr>
  let blockJoinDeletion: ParagraphAttrs['blockJoinDeletion'] = null
  const pPrRPr = pPr ? findChild(pPr, 'rPr') : undefined
  const pPrDel = pPrRPr ? findChild(pPrRPr, 'del') : undefined
  if (pPrDel && ctx.revisionsEnabled) {
    const delId = Number.parseInt(getAttr(pPrDel, 'w:id', 'id') ?? '', 10)
    blockJoinDeletion = {
      id: Number.isFinite(delId) ? `rev_${delId}` : `join_${location}`,
      kind: 'delete',
      author: getAttr(pPrDel, 'w:author', 'author') ?? 'Unknown',
      date: getAttr(pPrDel, 'w:date', 'date') ?? '1970-01-01T00:00:00Z',
      ...(Number.isFinite(delId) ? { ooxmlId: delId } : {}),
    }
  }

  const pPrChange = pPr ? findChild(pPr, 'pPrChange') : undefined
  let formattingChange: ParagraphAttrs['formattingChange'] = null
  if (pPrChange) {
    const fcId = getAttr(pPrChange, 'w:id', 'id') ?? `ppr_${location}`
    const fcAuthor = getAttr(pPrChange, 'w:author', 'author') ?? 'Unknown'
    const fcDate = getAttr(pPrChange, 'w:date', 'date') ?? '1970-01-01T00:00:00Z'
    ctx.formattingRevisions.push({
      id: fcId,
      targetType: 'paragraph',
      location,
      author: fcAuthor,
      date: fcDate,
      summary: 'paragraph formatting change',
    })
    if (ctx.requireFormattingRevisions) {
      ctx.diagnostics.block(
        'NATIVE_FORMATTING_REVISION_UNSUPPORTED',
        'w:pPrChange',
        location,
        'Mandatory paragraph formatting revision in w:pPrChange cannot be losslessly roundtripped.',
      )
    } else {
      ctx.diagnostics.warn(
        'NATIVE_FORMATTING_REVISION',
        'w:pPrChange',
        location,
        'degraded',
        `Paragraph formatting revision by ${fcAuthor} recorded in loss report.`,
      )
    }
    formattingChange = {
      id: fcId,
      targetType: 'paragraph',
      author: fcAuthor,
      date: fcDate,
      summary: 'paragraph formatting change',
      ooxmlId: Number.parseInt(fcId, 10) || undefined,
    }
  }

  // Check if paragraph contains explicit page break(s)
  const hasExplicitPageBreak = findAllDescendants(pNode, 'br').some(
    (br) => getAttr(br, 'w:type', 'type') === 'page',
  )

  const inlines: IntermediateInline[] = []
  let idx = 0
  for (const child of pNode.children) {
    const childLoc = `${location}.c[${idx++}]`
    if (child.localName === 'pPr') {
      continue
    }
    if (child.localName === 'commentRangeStart') {
      const wId = Number.parseInt(getAttr(child, 'w:id', 'id') ?? '', 10)
      if (Number.isFinite(wId)) {
        ctx.activeCommentWIds.add(wId)
        ctx.anchorOrderWIds.push(wId)
      }
    } else if (child.localName === 'commentRangeEnd') {
      const wId = Number.parseInt(getAttr(child, 'w:id', 'id') ?? '', 10)
      if (Number.isFinite(wId)) {
        ctx.activeCommentWIds.delete(wId)
      }
    } else if (child.localName === 'r') {
      inlines.push(...parseRunElement(child, resolvedP, null, null, ctx, childLoc))
    } else if (child.localName === 'hyperlink') {
      const rId = getAttr(child, 'r:id', 'id') ?? ''
      const rel = ctx.rels.get(rId)
      const href = rel?.external ? rel.target : null
      for (const hChild of child.children) {
        if (hChild.localName === 'r') {
          inlines.push(...parseRunElement(hChild, resolvedP, href, null, ctx, childLoc))
        }
      }
    } else if (child.localName === 'ins' || child.localName === 'del') {
      const kind = child.localName === 'ins' ? 'insert' : 'delete'
      const ooxmlId = Number.parseInt(getAttr(child, 'w:id', 'id') ?? '', 10)
      const author = getAttr(child, 'w:author', 'author') ?? 'Unknown'
      const date = getAttr(child, 'w:date', 'date') ?? '1970-01-01T00:00:00Z'
      const groupMeta = Number.isFinite(ooxmlId) ? ctx.replacementByOoxmlId.get(ooxmlId) : undefined
      const rev: InlineRevisionMeta = {
        id: Number.isFinite(ooxmlId) ? `rev_${ooxmlId}` : `rev_${childLoc}`,
        kind,
        ...(groupMeta ? { groupId: groupMeta.groupId } : {}),
        author,
        date,
        ...(Number.isFinite(ooxmlId) ? { ooxmlId } : {}),
      }

      if (!ctx.revisionsEnabled) {
        if (kind === 'insert') {
          // Projected to original: drop pending insert, record diagnostic
          ctx.diagnostics.warn(
            'REVISION_DROPPED_BY_PROFILE',
            'w:ins',
            childLoc,
            'projected',
            `Pending insertion by ${author} dropped when importing under non-revisions profile.`,
          )
          continue
        }
        ctx.diagnostics.warn(
          'REVISION_ACCEPTED_BY_PROFILE',
          'w:del',
          childLoc,
          'projected',
          `Pending deletion by ${author} kept as baseline text under non-revisions profile.`,
        )
      }

      for (const revChild of child.children) {
        if (revChild.localName === 'r') {
          inlines.push(
            ...parseRunElement(revChild, resolvedP, null, ctx.revisionsEnabled ? rev : null, ctx, childLoc),
          )
        } else if (revChild.localName === 'hyperlink') {
          const rId = getAttr(revChild, 'r:id', 'id') ?? ''
          const rel = ctx.rels.get(rId)
          const href = rel?.external ? rel.target : null
          for (const hChild of revChild.children) {
            if (hChild.localName === 'r') {
              inlines.push(
                ...parseRunElement(
                  hChild,
                  resolvedP,
                  href,
                  ctx.revisionsEnabled ? rev : null,
                  ctx,
                  childLoc,
                ),
              )
            }
          }
        }
      }
    } else if (child.localName === 'oleObject' || child.localName === 'object') {
      ctx.diagnostics.block(
        'UNSUPPORTED_MANDATORY_ELEMENT',
        child.name,
        childLoc,
        `Unsupported mandatory element "${child.name}".`,
      )
    }
  }

  const attrs: ParagraphAttrs = {
    styleId: pStyleId,
    textAlign: resolvedP.textAlign,
    indentLeftDxa: resolvedP.indentLeftDxa,
    indentFirstLineDxa: resolvedP.indentFirstLineDxa,
    indentRightDxa: resolvedP.indentRightDxa,
    spacingBeforeDxa: resolvedP.spacingBeforeDxa,
    spacingAfterDxa: resolvedP.spacingAfterDxa,
    lineSpacing: resolvedP.lineSpacing,
    lineRule: resolvedP.lineRule,
    contextualSpacing: resolvedP.contextualSpacing,
    keepNext: resolvedP.keepNext,
    keepLines: resolvedP.keepLines,
    list: listBinding,
    blockJoinDeletion,
    formattingChange,
  }

  const blocks: IntermediateBlock[] = []
  const headingLevel = ctx.styles.headingLevelForStyle(pStyleId, resolvedP)

  const hasTextOrInline = inlines.length > 0
  if (hasExplicitPageBreak && !hasTextOrInline) {
    blocks.push({ type: 'pageBreak', id: null })
  } else {
    if (hasTextOrInline || (!sectionEl && !hasExplicitPageBreak)) {
      if (headingLevel !== null) {
        blocks.push({
          type: 'heading',
          id: null,
          level: headingLevel as 1 | 2 | 3 | 4 | 5 | 6,
          attrs,
          inlines,
        })
      } else {
        blocks.push({
          type: 'paragraph',
          id: null,
          attrs,
          inlines,
        })
      }
    }
    if (hasExplicitPageBreak) {
      blocks.push({ type: 'pageBreak', id: null })
    }
  }

  return { blocks, sectionEl }
}

function parseTable(
  tblNode: XmlElementNode,
  ctx: ParseContext,
  location: string,
): IntermediateBlock {
  const tblPr = findChild(tblNode, 'tblPr')
  const tblStyleEl = tblPr ? findChild(tblPr, 'tblStyle') : undefined
  const tblStyleId = tblStyleEl ? getAttr(tblStyleEl, 'w:val', 'val') ?? null : null
  const tblWEl = tblPr ? findChild(tblPr, 'tblW') : undefined
  const width = tblWEl
    ? parseOoxmlWidth(getAttr(tblWEl, 'w:w', 'w'), getAttr(tblWEl, 'w:type', 'type'))
    : null
  const tblIndEl = tblPr ? findChild(tblPr, 'tblInd') : undefined
  const indentRaw = tblIndEl ? Number.parseInt(getAttr(tblIndEl, 'w:w', 'w') ?? '', 10) : Number.NaN
  const indentDxa = Number.isFinite(indentRaw) ? indentRaw : null

  const tblGrid = findChild(tblNode, 'tblGrid')
  const gridColsDxa = tblGrid
    ? findChildren(tblGrid, 'gridCol')
        .map((g) => Number.parseInt(getAttr(g, 'w:w', 'w') ?? '0', 10))
        .filter((n) => Number.isFinite(n) && n > 0)
    : []

  const rawRows = findChildren(tblNode, 'tr')
  const rows: IntermediateTableRow[] = []
  // Track originating cells for vertical merges keyed by column index.
  const openVerticalMerges = new Map<number, IntermediateTableCell>()

  for (let r = 0; r < rawRows.length; r += 1) {
    const tr = rawRows[r]
    const trPr = findChild(tr, 'trPr')
    const tblHeaderEl = trPr ? findChild(trPr, 'tblHeader') : undefined
    const isHeader = Boolean(
      tblHeaderEl && !['0', 'false', 'off'].includes((getAttr(tblHeaderEl, 'w:val', 'val') ?? '').toLowerCase()),
    )
    const trHeightEl = trPr ? findChild(trPr, 'trHeight') : undefined
    const heightRaw = trHeightEl ? Number.parseInt(getAttr(trHeightEl, 'w:val', 'val') ?? '', 10) : Number.NaN
    const heightDxa = Number.isFinite(heightRaw) ? heightRaw : null
    const hRuleRaw = trHeightEl ? getAttr(trHeightEl, 'w:hRule', 'hRule') : undefined
    const heightRule =
      hRuleRaw === 'exact' || hRuleRaw === 'atLeast' || hRuleRaw === 'auto' ? hRuleRaw : null

    const cells: IntermediateTableCell[] = []
    const rawCells = findChildren(tr, 'tc')
    let colCursor = 0

    for (let c = 0; c < rawCells.length; c += 1) {
      const tc = rawCells[c]
      const tcPr = findChild(tc, 'tcPr')
      const tcWEl = tcPr ? findChild(tcPr, 'tcW') : undefined
      const tcWidth = tcWEl
        ? parseOoxmlWidth(getAttr(tcWEl, 'w:w', 'w'), getAttr(tcWEl, 'w:type', 'type'))
        : null
      const gridSpanEl = tcPr ? findChild(tcPr, 'gridSpan') : undefined
      const colspan = Math.max(
        1,
        Number.parseInt(getAttr(gridSpanEl ?? tc, 'w:val', 'val') ?? '1', 10) || 1,
      )
      const vMergeEl = tcPr ? findChild(tcPr, 'vMerge') : undefined
      const vMergeVal = vMergeEl ? (getAttr(vMergeEl, 'w:val', 'val') ?? 'continue').toLowerCase() : null

      if (gridColsDxa.length > 0 && colCursor + colspan > gridColsDxa.length) {
        ctx.diagnostics.block(
          'INVALID_TABLE_MERGE_GEOMETRY',
          'w:gridSpan',
          `${location}.row[${r}].cell[${c}]`,
          `Cell gridSpan=${colspan} at col ${colCursor} exceeds tblGrid columns (${gridColsDxa.length}).`,
        )
      }

      if (vMergeVal !== null && vMergeVal !== 'restart') {
        const origin = openVerticalMerges.get(colCursor)
        if (!origin) {
          ctx.diagnostics.block(
            'INVALID_TABLE_MERGE_GEOMETRY',
            'w:vMerge',
            `${location}.row[${r}].cell[${c}]`,
            'Found w:vMerge continuation cell with no preceding restart cell.',
          )
        } else {
          origin.rowspan += 1
        }
        colCursor += colspan
        continue
      }

      const shdEl = tcPr ? findChild(tcPr, 'shd') : undefined
      const backgroundHex = shdEl ? normalizeHexColor(getAttr(shdEl, 'w:fill', 'fill')) : null
      const vAlignEl = tcPr ? findChild(tcPr, 'vAlign') : undefined
      const vAlignRaw = vAlignEl ? getAttr(vAlignEl, 'w:val', 'val') : undefined
      const verticalAlign =
        vAlignRaw === 'center' || vAlignRaw === 'middle'
          ? 'center'
          : vAlignRaw === 'bottom'
            ? 'bottom'
            : vAlignRaw === 'top'
              ? 'top'
              : null

      const cellBlocks: IntermediateBlock[] = []
      let bIdx = 0
      for (const tcChild of tc.children) {
        const bLoc = `${location}.row[${r}].cell[${c}].b[${bIdx++}]`
        if (tcChild.localName === 'p') {
          cellBlocks.push(...parseParagraph(tcChild, tblStyleId, ctx, bLoc).blocks)
        } else if (tcChild.localName === 'tbl') {
          cellBlocks.push(parseTable(tcChild, ctx, bLoc))
        }
      }

      const colwidthDxa =
        gridColsDxa.length >= colCursor + colspan
          ? gridColsDxa.slice(colCursor, colCursor + colspan)
          : tcWidth?.unit === 'dxa'
            ? [tcWidth.value]
            : null

      const cellModel: IntermediateTableCell = {
        colspan,
        rowspan: 1,
        colwidthDxa,
        width: tcWidth,
        backgroundHex,
        verticalAlign,
        blocks: cellBlocks,
      }

      if (vMergeVal === 'restart') {
        for (let span = 0; span < colspan; span += 1) {
          openVerticalMerges.set(colCursor + span, cellModel)
        }
      } else {
        for (let span = 0; span < colspan; span += 1) {
          openVerticalMerges.delete(colCursor + span)
        }
      }

      cells.push(cellModel)
      colCursor += colspan
    }

    rows.push({
      isHeader,
      heightDxa,
      heightRule,
      cells,
    })
  }

  return {
    type: 'table',
    id: null,
    width,
    indentDxa,
    gridColsDxa,
    rows,
  }
}

function parseHeaderFooterPart(
  entries: Record<string, Uint8Array>,
  partPath: string,
  baseCtx: ParseContext,
): HeaderFooterContent | null {
  if (!entries[partPath]) {
    return null
  }
  const relsPath = partPath.replace(/^word\/([^/]+)$/, 'word/_rels/$1.rels')
  let partRels = baseCtx.rels
  if (entries[relsPath]) {
    partRels = parseRelationships(decodeUtf8(entries[relsPath]), 'word', relsPath)
  }
  const root = parseXmlPart(decodeUtf8(entries[partPath]), partPath)
  const localCtx: ParseContext = {
    ...baseCtx,
    rels: partRels,
    activeCommentWIds: new Set(),
    anchorOrderWIds: [],
  }
  const blocks: IntermediateBlock[] = []
  let idx = 0
  for (const child of root.children) {
    const loc = `${partPath}.b[${idx++}]`
    if (child.localName === 'p') {
      blocks.push(...parseParagraph(child, null, localCtx, loc).blocks)
    } else if (child.localName === 'tbl') {
      blocks.push(parseTable(child, localCtx, loc))
    }
  }
  return { blocks }
}

function parseSectionNode(
  sectPr: XmlElementNode,
  index: number,
  blocks: IntermediateBlock[],
  entries: Record<string, Uint8Array>,
  docRels: ReadonlyMap<string, ParsedRelationship>,
  ctx: ParseContext,
): IntermediateSection {
  const typeEl = findChild(sectPr, 'type')
  const typeRaw = typeEl ? getAttr(typeEl, 'w:val', 'val') : undefined
  const type: IntermediateSection['type'] =
    typeRaw === 'continuous' || typeRaw === 'evenPage' || typeRaw === 'oddPage' ? typeRaw : 'nextPage'

  const pgSzEl = findChild(sectPr, 'pgSz')
  const widthDxa = pgSzEl ? Number.parseInt(getAttr(pgSzEl, 'w:w', 'w') ?? '11906', 10) || 11906 : 11906
  const heightDxa = pgSzEl ? Number.parseInt(getAttr(pgSzEl, 'w:h', 'h') ?? '16838', 10) || 16838 : 16838
  const orientRaw = pgSzEl ? getAttr(pgSzEl, 'w:orient', 'orient') : undefined
  const orientation = orientRaw === 'landscape' ? 'landscape' : 'portrait'

  const pgMarEl = findChild(sectPr, 'pgMar')
  const intAttr = (el: XmlElementNode | undefined, name: string): number | null => {
    if (!el) return null
    const val = Number.parseInt(getAttr(el, `w:${name}`, name) ?? '', 10)
    return Number.isFinite(val) ? val : null
  }
  const margins = pgMarEl
    ? {
        topDxa: intAttr(pgMarEl, 'top'),
        rightDxa: intAttr(pgMarEl, 'right'),
        bottomDxa: intAttr(pgMarEl, 'bottom'),
        leftDxa: intAttr(pgMarEl, 'left'),
        headerDxa: intAttr(pgMarEl, 'header'),
        footerDxa: intAttr(pgMarEl, 'footer'),
        gutterDxa: intAttr(pgMarEl, 'gutter'),
      }
    : undefined

  const titlePgEl = findChild(sectPr, 'titlePg')
  const titlePg = titlePgEl
    ? !['0', 'false', 'off'].includes((getAttr(titlePgEl, 'w:val', 'val') ?? '').toLowerCase())
    : false

  const headers: IntermediateSection['headers'] = {}
  const footers: IntermediateSection['footers'] = {}

  for (const ref of findChildren(sectPr, 'headerReference')) {
    const slot = (getAttr(ref, 'w:type', 'type') ?? 'default') as 'default' | 'first' | 'even'
    const rId = getAttr(ref, 'r:id', 'id') ?? ''
    const rel = docRels.get(rId)
    if (rel?.resolvedPath) {
      headers[slot] = parseHeaderFooterPart(entries, rel.resolvedPath, ctx)
    }
  }

  for (const ref of findChildren(sectPr, 'footerReference')) {
    const slot = (getAttr(ref, 'w:type', 'type') ?? 'default') as 'default' | 'first' | 'even'
    const rId = getAttr(ref, 'r:id', 'id') ?? ''
    const rel = docRels.get(rId)
    if (rel?.resolvedPath) {
      footers[slot] = parseHeaderFooterPart(entries, rel.resolvedPath, ctx)
    }
  }

  return {
    id: `sec_${index + 1}`,
    type,
    pageSize: { widthDxa, heightDxa, orientation },
    ...(margins ? { margins } : {}),
    titlePg,
    headers,
    footers,
    blocks,
  }
}

export interface EffectiveSectionHeaderFooter {
  sectionId: string
  firstPageVariant: boolean
  explicit: {
    'header.default'?: string
    'header.first'?: string
    'header.even'?: string
    'footer.default'?: string
    'footer.first'?: string
    'footer.even'?: string
  }
  effective: {
    'header.default'?: string
    'header.first'?: string
    'header.even'?: string
    'footer.default'?: string
    'footer.first'?: string
    'footer.even'?: string
  }
}

function headerFooterText(slot: HeaderFooterContent | null | undefined): string {
  if (!slot) return ''
  return slot.blocks
    .map((b) => {
      if (b.type === 'paragraph' || b.type === 'heading') {
        return b.inlines.map((i) => (i.type === 'text' ? i.text : '')).join('')
      }
      return ''
    })
    .join('\n')
}

export function resolveEffectiveSectionHeadersFooters(
  sections: readonly IntermediateSection[],
): EffectiveSectionHeaderFooter[] {
  const inherited: Record<string, string> = {}
  return sections.map((sec) => {
    const explicit: Record<string, string> = {}
    for (const slot of ['default', 'first', 'even'] as const) {
      if (sec.headers[slot]) explicit[`header.${slot}`] = headerFooterText(sec.headers[slot])
      if (sec.footers[slot]) explicit[`footer.${slot}`] = headerFooterText(sec.footers[slot])
    }
    Object.assign(inherited, explicit)
    return {
      sectionId: sec.id,
      firstPageVariant: Boolean(sec.titlePg),
      explicit,
      effective: { ...inherited },
    }
  })
}

export async function importOoxmlToIntermediate(
  bytes: Uint8Array,
  zipReader: ZipReader,
  options: ImportOoxmlOptions,
  diagnostics: LossReportBuilder,
): Promise<IntermediateDocument> {
  const profile = options.profile ?? 'discussions'
  const capabilities = getProfileCapabilities(profile, options)

  const entries = await zipReader.readEntries(bytes, options.quotas)
  if (!entries['word/document.xml']) {
    throw new DocxSecurityError('zip_invalid_archive', 'DOCX package is missing word/document.xml.')
  }

  // Enforce strict External relationship allowlist across ALL .rels files in the archive.
  const relsByPart = new Map<string, Map<string, ParsedRelationship>>()
  for (const [entryName, entryBytes] of Object.entries(entries)) {
    if (entryName.endsWith('.rels')) {
      const baseDir = entryName.startsWith('word/_rels/') ? 'word' : ''
      const parsedRels = parseRelationships(decodeUtf8(entryBytes), baseDir, entryName)
      relsByPart.set(entryName, parsedRels)
    }
  }

  const docRels = relsByPart.get('word/_rels/document.xml.rels') ?? new Map<string, ParsedRelationship>()

  const stylesRoot = entries['word/styles.xml']
    ? parseXmlPart(decodeUtf8(entries['word/styles.xml']), 'word/styles.xml')
    : null
  const styles = new StyleRegistry(stylesRoot)

  const numberingRoot = entries['word/numbering.xml']
    ? parseXmlPart(decodeUtf8(entries['word/numbering.xml']), 'word/numbering.xml')
    : null
  const numbering = parseNumbering(numberingRoot)

  const settingsRoot = entries['word/settings.xml']
    ? parseXmlPart(decodeUtf8(entries['word/settings.xml']), 'word/settings.xml')
    : null
  const evenOddEl = settingsRoot ? findChild(settingsRoot, 'evenAndOddHeaders') : undefined
  const evenAndOddHeaders = Boolean(
    evenOddEl && !['0', 'false', 'off'].includes((getAttr(evenOddEl, 'w:val', 'val') ?? '').toLowerCase()),
  )

  const customMeta = parseCustomReviewMetadata(entries, docRels)
  const replacementByOoxmlId = new Map<number, { groupId: string; role: 'del' | 'ins' }>()
  for (const rep of customMeta.replacements) {
    if (rep.deletionOoxmlId !== undefined) {
      replacementByOoxmlId.set(rep.deletionOoxmlId, { groupId: rep.groupId, role: 'del' })
    }
    if (rep.insertionOoxmlId !== undefined) {
      replacementByOoxmlId.set(rep.insertionOoxmlId, { groupId: rep.groupId, role: 'ins' })
    }
  }

  const docRoot = parseXmlPart(decodeUtf8(entries['word/document.xml']), 'word/document.xml')
  const bodyEl = findChild(docRoot, 'body')
  if (!bodyEl) {
    throw new DocxSecurityError('xml_malformed', 'word/document.xml does not contain w:body.')
  }

  const formattingRevisions: FormattingRevisionRecord[] = []
  const ctx: ParseContext = {
    styles,
    numbering,
    rels: docRels,
    diagnostics,
    formattingRevisions,
    activeCommentWIds: new Set<number>(),
    anchorOrderWIds: [],
    replacementByOoxmlId,
    revisionsEnabled: capabilities.revisionsEnabled,
    requireFormattingRevisions: capabilities.requireFormattingRevisions,
  }

  const sections: IntermediateSection[] = []
  let currentBlocks: IntermediateBlock[] = []
  let trailingSectPr: XmlElementNode | null = null
  let bodyIndex = 0

  for (const child of bodyEl.children) {
    const loc = `body[${bodyIndex++}]`
    if (child.localName === 'p') {
      const parsed = parseParagraph(child, null, ctx, loc)
      currentBlocks.push(...parsed.blocks)
      if (parsed.sectionEl) {
        sections.push(
          parseSectionNode(parsed.sectionEl, sections.length, currentBlocks, entries, docRels, ctx),
        )
        currentBlocks = []
      }
    } else if (child.localName === 'tbl') {
      currentBlocks.push(parseTable(child, ctx, loc))
    } else if (child.localName === 'sectPr') {
      trailingSectPr = child
    } else if (child.localName === 'altChunk' || child.localName === 'oleObject') {
      diagnostics.block(
        'UNSUPPORTED_MANDATORY_ELEMENT',
        child.name,
        loc,
        `Mandatory body element "${child.name}" is unsupported/blocked in this profile.`,
      )
    }
  }

  if (trailingSectPr || currentBlocks.length > 0 || sections.length === 0) {
    if (trailingSectPr) {
      sections.push(
        parseSectionNode(trailingSectPr, sections.length, currentBlocks, entries, docRels, ctx),
      )
    } else {
      sections.push({
        id: `sec_${sections.length + 1}`,
        type: 'nextPage',
        pageSize: { widthDxa: 11906, heightDxa: 16838, orientation: 'portrait' },
        titlePg: false,
        headers: {},
        footers: {},
        blocks: currentBlocks,
      })
    }
  }

  // Parse comments and reconcile discussion IDs onto anchored inline commentIds.
  const { discussions, discussionIdByWId } = parseCommentsParts(
    entries,
    ctx.anchorOrderWIds,
    customMeta,
    diagnostics,
    capabilities.discussionsEnabled,
    capabilities.threadedComments,
  )

  const remapDiscussionIds = (blocks: IntermediateBlock[]) => {
    for (const block of blocks) {
      if (block.type === 'paragraph' || block.type === 'heading') {
        for (const inline of block.inlines) {
          if ('commentIds' in inline && inline.commentIds) {
            inline.commentIds = inline.commentIds.map((raw) => {
              const m = /^thread_(\d+)$/.exec(raw)
              if (!m) return raw
              const wId = Number.parseInt(m[1], 10)
              return discussionIdByWId.get(wId) ?? raw
            })
          }
        }
      } else if (block.type === 'table') {
        for (const row of block.rows) {
          for (const cell of row.cells) {
            remapDiscussionIds(cell.blocks)
          }
        }
      }
    }
  }
  for (const sec of sections) {
    remapDiscussionIds(sec.blocks)
  }

  // Reconstruct replacementGroups: check customXml metadata first, or warn if paired ins/del exist without group metadata.
  const replacementGroups: ReplacementGroupMetadata[] = []
  let hasUnlinkedDeletions = false
  let hasUnlinkedInsertions = false

  const scanRevisions = (blocks: readonly IntermediateBlock[]) => {
    for (const block of blocks) {
      if (block.type === 'paragraph' || block.type === 'heading') {
        for (const inline of block.inlines) {
          if (inline.type === 'text' && inline.revision) {
            if (!inline.revision.groupId) {
              if (inline.revision.kind === 'delete') hasUnlinkedDeletions = true
              else hasUnlinkedInsertions = true
            }
          }
        }
      } else if (block.type === 'table') {
        for (const r of block.rows) {
          for (const c of r.cells) scanRevisions(c.blocks)
        }
      }
    }
  }

  if (capabilities.revisionsEnabled) {
    for (const rep of customMeta.replacements) {
      replacementGroups.push({
        groupId: rep.groupId,
        deletionRevisionId: rep.deletionOoxmlId !== undefined ? `rev_${rep.deletionOoxmlId}` : rep.deletionRevisionId,
        insertionRevisionId: rep.insertionOoxmlId !== undefined ? `rev_${rep.insertionOoxmlId}` : rep.insertionRevisionId,
        author: rep.author,
        date: rep.date,
      })
    }

    for (const sec of sections) {
      scanRevisions(sec.blocks)
    }

    if (replacementGroups.length === 0 && hasUnlinkedDeletions && hasUnlinkedInsertions) {
      diagnostics.warn(
        'REPLACEMENT_GROUP_METADATA_MISSING',
        'replacement_group',
        'customXml',
        'degraded',
        'Document contains both w:del and w:ins without Vaulter replacement group metadata; preserved as separate insert/delete revisions.',
      )
    }
  }

  // Check if document has customXml/word/comments and missing numbering definitions used
  const numberingList = [...numbering.values()]

  return {
    schema: DOCX_MODEL_SCHEMA,
    sourceMarker: options.sourceMarker ?? customMeta.sourceMarker ?? null,
    metadata: {
      evenAndOddHeaders,
    },
    numbering: numberingList,
    sections,
    discussions,
    replacementGroups,
    formattingRevisions,
  }
}
