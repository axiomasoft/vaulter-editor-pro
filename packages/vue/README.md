# `@vaulter/editor-pro-vue`

Vue 3 and TipTap components and composables for Vaulter Editor Pro: revision compare,
range comments, tracked changes, server DOCX exchange and local (in-browser) DOCX export.

## Install

```bash
npm install @vaulter/editor-pro-vue
```

Peer dependencies: `vue`, `@tiptap/core`, `@tiptap/pm`, `@vaulter/vue`, `yjs`,
`prosemirror-changeset` (versions in `package.json`).

## Exports

- Components: `VaulterHistoryCompare`, `VaulterRangeComments`, `VaulterTrackedChangesPanel`,
  `VaulterDocxExchange`, `VaulterLocalDocxExport`.
- Composables: `useRangeComments`, `useTrackedChanges`, `useRevisionCompare`,
  `useDocxExchange`, `useLocalDocxExport`.
- TipTap extensions: `VaulterTrackedChanges`, `RangeAnchorHighlight`, `DiffOverlay`.
- `@vaulter/editor-pro-vue/worker`: the Web Worker entry for local DOCX export
  (`attachDocxWorkerListener`).

The server side is `axiomasoft/vaulter-editor-pro-review` and
`axiomasoft/vaulter-editor-pro-exchange`.

## Development

```bash
npm test
npm run typecheck
npm run build     # needs a built @vaulter/editor-pro-docx and @vaulter/vue
```

## License

Proprietary. See [LICENSE](LICENSE).
