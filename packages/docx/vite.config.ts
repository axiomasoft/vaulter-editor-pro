import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import dts from 'vite-plugin-dts'

export default defineConfig({
  plugins: [
    dts({
      tsconfigPath: './tsconfig.json',
      include: ['src'],
      exclude: ['tests'],
    }),
  ],
  build: {
    lib: {
      entry: {
        index: fileURLToPath(new URL('./src/index.ts', import.meta.url)),
        browser: fileURLToPath(new URL('./src/browser.ts', import.meta.url)),
        node: fileURLToPath(new URL('./src/node.ts', import.meta.url)),
        cli: fileURLToPath(new URL('./src/cli.ts', import.meta.url)),
      },
      formats: ['es', 'cjs'],
    },
    rollupOptions: {
      external: [
        'docx',
        'fflate',
        'yauzl',
        'saxes',
        /^node:/,
        'fs',
        'path',
        'stream',
        'zlib',
        'events',
        'util',
        'crypto',
      ],
      output: {
        exports: 'named',
      },
    },
  },
})
