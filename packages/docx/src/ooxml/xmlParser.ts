import { XMLParser } from 'fast-xml-parser'
import { SaxesParser } from 'saxes'
import { DocxSecurityError } from '../zip/guard'

export const MAX_XML_DEPTH = 256

export interface XmlElementNode {
  name: string
  localName: string
  attrs: Record<string, string>
  children: XmlElementNode[]
  text: string
}

const ORDERED_PARSER = new XMLParser({
  processEntities: false,
  ignoreAttributes: false,
  attributeNamePrefix: '@_',
  preserveOrder: true,
  cdataPropName: '#cdata',
  trimValues: false,
  parseTagValue: false,
  parseAttributeValue: false,
  removeNSPrefix: false,
})

const XML_ENTITIES: Record<string, string> = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
}

// Saxes has already validated the references and code points. Decode once so
// an escaped reference such as &amp;lt; stays literal.
function decodeXmlReferences(value: string): string {
  return value.replace(/&(amp|lt|gt|quot|apos|#[0-9]+|#x[0-9a-fA-F]+);/g, (_match, reference: string) => {
    if (reference.startsWith('#x')) {
      return String.fromCodePoint(Number.parseInt(reference.slice(2), 16))
    }
    if (reference.startsWith('#')) {
      return String.fromCodePoint(Number.parseInt(reference.slice(1), 10))
    }
    return XML_ENTITIES[reference]
  })
}

const FORBIDDEN_XML_CONSTRUCT_RE = /<!\s*(?:DOCTYPE|ENTITY|ELEMENT|ATTLIST)\b|<[A-Za-z0-9_-]*:include\b|http:\/\/www\.w3\.org\/2001\/XInclude/i

export function stripNamespacePrefix(name: string): string {
  const idx = name.indexOf(':')
  return idx === -1 ? name : name.slice(idx + 1)
}

function validateXmlSecurityAndWellFormedness(xml: string, partName: string): void {
  if (FORBIDDEN_XML_CONSTRUCT_RE.test(xml)) {
    throw new DocxSecurityError(
      'xml_xxe_forbidden',
      `XML part "${partName}" contains forbidden DOCTYPE/ENTITY/XInclude construct.`,
    )
  }

  let depth = 0
  let parserError: Error | null = null
  const parser = new SaxesParser({ xmlns: false, position: true })

  parser.on('doctype', () => {
    parserError = new DocxSecurityError(
      'xml_xxe_forbidden',
      `XML part "${partName}" declares forbidden DOCTYPE.`,
    )
  })
  parser.on('opentag', () => {
    depth += 1
    if (depth > MAX_XML_DEPTH && !parserError) {
      parserError = new DocxSecurityError(
        'xml_depth_exceeded',
        `XML part "${partName}" exceeded max element depth ${MAX_XML_DEPTH}.`,
      )
    }
  })
  parser.on('closetag', () => {
    depth = Math.max(0, depth - 1)
  })
  parser.on('error', (err) => {
    if (!parserError) {
      parserError = new DocxSecurityError(
        'xml_malformed',
        `Malformed XML in "${partName}": ${err.message}`,
      )
    }
  })

  parser.write(xml).close()

  if (parserError) {
    throw parserError
  }
}

function convertOrderedEntry(raw: Record<string, unknown>): XmlElementNode | null {
  const keys = Object.keys(raw).filter((k) => k !== ':@' && k !== '#text')
  if (keys.length === 0) {
    return null
  }
  const name = keys[0]
  if (name.startsWith('?') || name.startsWith('!')) {
    return null
  }

  const rawAttrs = (raw[':@'] ?? {}) as Record<string, unknown>
  const attrs: Record<string, string> = {}
  for (const [k, v] of Object.entries(rawAttrs)) {
    const attrName = k.startsWith('@_') ? k.slice(2) : k
    attrs[attrName] = decodeXmlReferences(String(v))
  }

  const rawChildren = Array.isArray(raw[name]) ? (raw[name] as Array<Record<string, unknown>>) : []
  const children: XmlElementNode[] = []
  let text = ''

  for (const childRaw of rawChildren) {
    if ('#text' in childRaw) {
      text += decodeXmlReferences(String(childRaw['#text']))
      continue
    }
    if ('#cdata' in childRaw) {
      const cdata = childRaw['#cdata'] as Array<{ '#text': string }>
      text += cdata.map((entry) => entry['#text']).join('')
      continue
    }
    const converted = convertOrderedEntry(childRaw)
    if (converted) {
      children.push(converted)
      text += converted.text
    }
  }

  return {
    name,
    localName: stripNamespacePrefix(name),
    attrs,
    children,
    text,
  }
}

export function parseXmlPart(xml: string, partName = 'part.xml'): XmlElementNode {
  validateXmlSecurityAndWellFormedness(xml, partName)

  const parsed = ORDERED_PARSER.parse(xml) as Array<Record<string, unknown>>
  for (const entry of parsed) {
    const root = convertOrderedEntry(entry)
    if (root) {
      return root
    }
  }

  throw new DocxSecurityError('xml_malformed', `XML part "${partName}" has no root element.`)
}

export function decodeUtf8(bytes: Uint8Array): string {
  return new TextDecoder('utf-8', { fatal: false }).decode(bytes)
}

export function encodeUtf8(text: string): Uint8Array {
  return new TextEncoder().encode(text)
}

export function findChild(node: XmlElementNode, nameOrLocal: string): XmlElementNode | undefined {
  return node.children.find((child) => child.name === nameOrLocal || child.localName === nameOrLocal)
}

export function findChildren(node: XmlElementNode, nameOrLocal: string): XmlElementNode[] {
  return node.children.filter((child) => child.name === nameOrLocal || child.localName === nameOrLocal)
}

export function findAllDescendants(node: XmlElementNode, nameOrLocal: string): XmlElementNode[] {
  const result: XmlElementNode[] = []
  const visit = (cur: XmlElementNode) => {
    for (const child of cur.children) {
      if (child.name === nameOrLocal || child.localName === nameOrLocal) {
        result.push(child)
      }
      visit(child)
    }
  }
  visit(node)
  return result
}

export function getAttr(node: XmlElementNode, ...candidates: string[]): string | undefined {
  for (const key of candidates) {
    if (key in node.attrs) {
      return node.attrs[key]
    }
  }
  const candidateLocals = new Set(candidates.map(stripNamespacePrefix))
  for (const [k, v] of Object.entries(node.attrs)) {
    if (candidateLocals.has(stripNamespacePrefix(k))) {
      return v
    }
  }
  return undefined
}

export function escapeXml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}
