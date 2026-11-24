#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

if ! command -v soffice >/dev/null 2>&1; then
  echo "ERROR: soffice (LibreOffice) is required on PATH for docx-libreoffice.sh" >&2
  exit 1
fi

resolve_node26() {
  if command -v node >/dev/null 2>&1; then
    if node -e 'const [maj, min] = process.versions.node.split(".").map(Number); process.exit(maj > 26 || (maj === 26 && min >= 13) ? 0 : 1)'; then
      command -v node
      return 0
    fi
  fi
  if [ -x /tmp/node-v26.13.1/bin/node ]; then
    echo "/tmp/node-v26.13.1/bin/node"
    return 0
  fi
  echo "ERROR: Node >= 26.13.1 is required" >&2
  return 1
}

NODE_BIN="$(resolve_node26)"
export PATH="$(dirname "$NODE_BIN"):$PATH"

if [ ! -f "$ROOT_DIR/packages/docx/dist/node.js" ]; then
  npm --workspace=@vaulter/editor-pro-docx run build
fi

WORK_DIR="$(mktemp -d /tmp/vaulter-lo-XXXXXX)"
cleanup() {
  rm -rf "$WORK_DIR"
}
trap cleanup EXIT

LO_PROFILE="file://$WORK_DIR/lo-profile"

# format: filename|profile|min_tables|expected_sections|expect_revisions|min_images|min_footnotes|required_phrases_csv
CORPUS_CASES=(
  "d1-body-table.docx|standard|1|1|0|0|0|D1 Contract Schedule,Plain clause,Bold merged cell"
  "d2-sections.docx|standard|0|2|0|0|0|"
  "d3-discussions.docx|discussions|0|1|0|0|0|Clause prefix,Annotated clause suffix,Active range review target"
  "d3-discussions-libreoffice.docx|discussions|0|1|0|0|0|Clause prefix,Annotated clause suffix,Active range review target"
  "d4-revisions.docx|revisions|0|1|1|0|0|"
  "d5-rich-layout.docx|revisions|1|1|0|1|1|D5 Rich Layout,Bordered Cell A,Quoted paragraph inside blockquote.,const answer = 42;,Italic formatting proposal"
)

for entry in "${CORPUS_CASES[@]}"; do
  IFS='|' read -r filename profile min_tables expected_sections expect_revisions min_images min_footnotes required_phrases <<< "$entry"
  fixture="$ROOT_DIR/tests/corpus/docx/$filename"
  stem="${filename%.docx}"

  if [ ! -f "$fixture" ]; then
    echo "ERROR: missing corpus fixture $fixture" >&2
    exit 1
  fi

  mkdir -p "$WORK_DIR/docx/$stem" "$WORK_DIR/pdf/$stem"

  soffice --headless --nologo --nodefault --nofirststartwizard \
    "-env:UserInstallation=$LO_PROFILE" \
    --convert-to "docx:Office Open XML Text" \
    --outdir "$WORK_DIR/docx/$stem" \
    "$fixture" >/dev/null

  soffice --headless --nologo --nodefault --nofirststartwizard \
    "-env:UserInstallation=$LO_PROFILE" \
    --convert-to pdf \
    --outdir "$WORK_DIR/pdf/$stem" \
    "$fixture" >/dev/null

  lo_docx="$WORK_DIR/docx/$stem/$filename"
  lo_pdf="$WORK_DIR/pdf/$stem/$stem.pdf"

  if [ ! -s "$lo_docx" ]; then
    echo "ERROR: LibreOffice produced empty DOCX for $filename" >&2
    exit 1
  fi

  if [ ! -s "$lo_pdf" ] || [ "$(wc -c < "$lo_pdf")" -lt 1024 ]; then
    echo "ERROR: LibreOffice produced invalid/empty PDF for $filename" >&2
    exit 1
  fi

  pdf_magic="$(head -c 5 "$lo_pdf")"
  if [ "$pdf_magic" != "%PDF-" ]; then
    echo "ERROR: PDF header mismatch for $filename ($pdf_magic)" >&2
    exit 1
  fi

  "$NODE_BIN" --input-type=module - "$fixture" "$lo_docx" "$profile" "$min_tables" "$expected_sections" "$expect_revisions" "$min_images" "$min_footnotes" "$required_phrases" <<'JS'
import fs from 'node:fs'
import { unzipSync } from 'fflate'
import { SaxesParser } from 'saxes'
import { importDocx } from './packages/docx/dist/node.js'

const [
  origPath,
  loPath,
  profile,
  minTablesStr,
  expectedSectionsStr,
  expectRevisionsStr,
  minImagesStr,
  minFootnotesStr,
  requiredPhrasesCsv,
] = process.argv.slice(2)

const minTables = Number(minTablesStr)
const expectedSections = Number(expectedSectionsStr)
const expectRevisions = expectRevisionsStr === '1'
const minImages = Number(minImagesStr)
const minFootnotes = Number(minFootnotesStr)
const requiredPhrases = requiredPhrasesCsv
  ? requiredPhrasesCsv.split(',').map((s) => s.trim()).filter(Boolean)
  : []

const loBytes = new Uint8Array(fs.readFileSync(loPath))
const origBytes = new Uint8Array(fs.readFileSync(origPath))

// 1. Validate XML well-formedness of core OOXML parts
const zip = unzipSync(loBytes)
for (const part of ['[Content_Types].xml', '_rels/.rels', 'word/document.xml']) {
  if (!zip[part]) {
    throw new Error(`Missing ${part} in LibreOffice output for ${loPath}`)
  }
  const xml = new TextDecoder().decode(zip[part])
  const parser = new SaxesParser()
  parser.on('error', (err) => {
    throw new Error(`Malformed XML in ${part}: ${err.message}`)
  })
  parser.write(xml).close()
}

// 2. Re-import both original and LibreOffice-converted DOCX
const origResult = await importDocx(origBytes, { profile })
const loResult = await importDocx(loBytes, { profile })

if (loResult.report.blocked) {
  throw new Error(`importDocx blocked LibreOffice output for ${loPath}: ${JSON.stringify(loResult.report.diagnostics)}`)
}

const errorDiags = loResult.report.diagnostics.filter((d) => d.severity === 'error')
if (errorDiags.length > 0) {
  throw new Error(`importDocx reported error diagnostics for ${loPath}: ${JSON.stringify(errorDiags)}`)
}

function collectModelStats(model) {
  const texts = []
  let tables = 0
  let insertions = 0
  let deletions = 0
  let images = 0

  function walk(node) {
    if (!node) return
    if (Array.isArray(node)) {
      for (const item of node) walk(item)
      return
    }
    if (typeof node !== 'object') return
    if (typeof node.text === 'string') texts.push(node.text)
    if (node.type === 'table') tables += 1
    if (node.type === 'image') images += 1
    if (node.revision?.kind === 'insert') insertions += 1
    if (node.revision?.kind === 'delete') deletions += 1
    for (const val of Object.values(node)) {
      if (val && typeof val === 'object') walk(val)
    }
  }

  walk(model?.sections ?? [])

  return {
    text: texts.join(' ').replace(/\s+/g, ' ').trim(),
    tables,
    sections: model?.sections?.length ?? 0,
    insertions,
    deletions,
    images,
    footnotes: model?.footnotes?.length ?? 0,
  }
}

const origStats = collectModelStats(origResult.model)
const loStats = collectModelStats(loResult.model)
const origText = origStats.text
const loText = loStats.text

if (!loText) {
  throw new Error(`LibreOffice re-import produced empty body text for ${loPath}`)
}

for (const phrase of requiredPhrases) {
  if (!loText.includes(phrase)) {
    throw new Error(`Missing required phrase "${phrase}" in LibreOffice re-import of ${loPath}. Got: ${loText}`)
  }
}

if (loStats.tables < minTables) {
  throw new Error(`Expected >= ${minTables} tables in ${loPath}, got ${loStats.tables}`)
}
if (loStats.sections < expectedSections) {
  throw new Error(`Expected >= ${expectedSections} sections in ${loPath}, got ${loStats.sections}`)
}
if (expectRevisions && (loStats.insertions < 1 || loStats.deletions < 1)) {
  throw new Error(`Expected >=1 insert and >=1 delete revision in ${loPath}, got ins=${loStats.insertions}, del=${loStats.deletions}`)
}
if (loStats.images < minImages) {
  throw new Error(`Expected >= ${minImages} images in ${loPath}, got ${loStats.images}`)
}
if (loStats.footnotes < minFootnotes) {
  throw new Error(`Expected >= ${minFootnotes} footnotes in ${loPath}, got ${loStats.footnotes}`)
}

console.log(
  `[LO OK] ${loPath.split('/').pop()} | profile=${profile} | origLen=${origText.length} loLen=${loText.length} | tables=${loStats.tables} sections=${loStats.sections} ins=${loStats.insertions} del=${loStats.deletions} images=${loStats.images} footnotes=${loStats.footnotes}`
)
JS
done

echo "All LibreOffice D1-D5 roundtrip checks passed."
