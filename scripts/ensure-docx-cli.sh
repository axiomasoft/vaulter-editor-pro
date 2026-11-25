#!/usr/bin/env sh
# The exchange PHP tests drive the real DOCX converter through packages/docx/dist/cli.js.
# Build it when it is missing or older than any docx source file, so `composer test` never
# runs against a stale or absent converter. Needs `npm ci` to have run once.
set -eu

root=$(cd -- "$(dirname -- "$0")/.." && pwd)
cli="$root/packages/docx/dist/cli.js"

if [ -f "$cli" ] && [ -z "$(find "$root/packages/docx/src" "$root/packages/docx/package.json" "$root/packages/docx/vite.config.ts" -newer "$cli" -print -quit)" ]; then
    exit 0
fi

if [ ! -d "$root/node_modules" ]; then
    echo "packages/docx/dist/cli.js is missing or stale and node_modules is absent: run 'npm ci' first." >&2
    exit 1
fi

echo "Building @vaulter/editor-pro-docx (dist/cli.js is missing or older than its sources)..."
npm --prefix "$root" --workspace=@vaulter/editor-pro-docx run build
