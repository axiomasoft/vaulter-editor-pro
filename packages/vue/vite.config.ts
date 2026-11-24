import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import dts from 'vite-plugin-dts'

export default defineConfig({
  plugins: [
    vue(),
    dts({ tsconfigPath: './tsconfig.json', include: ['src'], exclude: ['tests'] }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    lib: {
      entry: {
        'vaulter-editor-pro-vue': fileURLToPath(new URL('./src/index.ts', import.meta.url)),
        'local-export-worker': fileURLToPath(new URL('./src/docx/localExportWorker.ts', import.meta.url)),
      },
      formats: ['es', 'cjs'],
      fileName: (format, entryName) => `${entryName}.${format === 'es' ? 'js' : 'cjs'}`,
    },
    rollupOptions: {
      external: ['vue', 'yjs', 'prosemirror-changeset', /^@tiptap\//, /^@vaulter\//],
      output: {
        exports: 'named',
      },
    },
  },
  test: {
    environment: 'happy-dom',
  },
})
