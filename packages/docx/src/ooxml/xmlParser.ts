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

const FORBIDDEN_XML_CONSTRUCT_RE = /<!\s*(?:DOCTYPE|ENTITY|ELEMENT|ATTLIST)\b|<[A-Za-z0-9_-]*:include\b|http:\/\/www\.w3\.org\/2001\/XInclude/i

export function stripNamespacePrefix(name: string): string {
  const idx = name.indexOf(':')
  return idx === -1 ? name : name.slice(idx + 1)
}

export function parseXmlPart(xml: string, partName = 'part.xml'): XmlElementNode {
  if (FORBIDDEN_XML_CONSTRUCT_RE.test(xml)) {
    throw new DocxSecurityError(
      'xml_xxe_forbidden',
      `XML part "${partName}" contains forbidden DOCTYPE/ENTITY/XInclude construct.`,
    )
  }

  let parserError: Error | null = null
  let root: XmlElementNode | null = null
  const stack: XmlElementNode[] = []
  const parser = new SaxesParser({ xmlns: false, position: true })

  parser.on('doctype', () => {
    if (!parserError) {
      parserError = new DocxSecurityError(
        'xml_xxe_forbidden',
        `XML part "${partName}" declares forbidden DOCTYPE.`,
      )
    }
  })

  parser.on('opentag', (tag) => {
    const depth = stack.length + 1
    if (depth > MAX_XML_DEPTH) {
      if (!parserError) {
        parserError = new DocxSecurityError(
          'xml_depth_exceeded',
          `XML part "${partName}" exceeded max element depth ${MAX_XML_DEPTH}.`,
        )
      }
      return
    }
    if (parserError) {
      return
    }

    const node: XmlElementNode = {
      name: tag.name,
      localName: stripNamespacePrefix(tag.name),
      attrs: { ...tag.attributes },
      children: [],
      text: '',
    }
    if (root === null) {
      root = node
    } else {
      stack[stack.length - 1].children.push(node)
    }
    stack.push(node)
  })

  parser.on('text', (chunk) => {
    if (!parserError && stack.length > 0) {
      stack[stack.length - 1].text += chunk
    }
  })

  parser.on('cdata', (chunk) => {
    if (!parserError && stack.length > 0) {
      stack[stack.length - 1].text += chunk
    }
  })

  parser.on('closetag', () => {
    const closed = stack.pop()
    if (!parserError && closed && stack.length > 0) {
      stack[stack.length - 1].text += closed.text
    }
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
  if (!root) {
    throw new DocxSecurityError('xml_malformed', `XML part "${partName}" has no root element.`)
  }
  return root
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
  return String(text ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}
