import { execFileSync, spawn } from 'node:child_process'
import fs from 'node:fs'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { createServer } from 'vite'
import vue from '@vitejs/plugin-vue'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(__dirname, '../..')
const packagesRoot = path.resolve(repoRoot, '..')

function resolveNode26() {
  const [major, minor] = process.versions.node.split('.').map(Number)
  if (major > 26 || (major === 26 && minor >= 13)) {
    return process.execPath
  }
  const fallback = '/tmp/node-v26.13.1/bin/node'
  if (fs.existsSync(fallback)) {
    return fallback
  }
  throw new Error(`Node >= 26.13.1 is required (current: ${process.versions.node})`)
}

function getFreePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer()
    srv.unref()
    srv.on('error', reject)
    srv.listen(0, '127.0.0.1', () => {
      const addr = srv.address()
      const port = typeof addr === 'object' && addr ? addr.port : 0
      srv.close((err) => (err ? reject(err) : resolve(port)))
    })
  })
}

async function waitForHttp(url, timeoutMs = 30_000, validate = null) {
  const deadline = Date.now() + timeoutMs
  while (Date.now() < deadline) {
    try {
      const res = await fetch(url)
      if (res.ok) {
        if (!validate) return
        if (await validate(res)) return
      }
    } catch {
      // retry
    }
    await new Promise((r) => setTimeout(r, 150))
  }
  throw new Error(`Timed out waiting for ${url}`)
}

function canConnectTcp(host, port, timeoutMs = 1000) {
  return new Promise((resolve) => {
    const sock = net.connect({ host, port })
    sock.setTimeout(timeoutMs)
    sock.on('connect', () => {
      sock.destroy()
      resolve(true)
    })
    sock.on('error', () => {
      sock.destroy()
      resolve(false)
    })
    sock.on('timeout', () => {
      sock.destroy()
      resolve(false)
    })
  })
}

export default async function globalSetup() {
  const node26 = resolveNode26()
  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'vaulter-ep-e2e-'))
  const cachePath = path.join(tmpDir, 'cache')
  fs.mkdirSync(cachePath, { recursive: true })

  // Ensure @vaulter/editor-pro-docx dist is built
  if (!fs.existsSync(path.join(repoRoot, 'packages/docx/dist/cli.js'))) {
    execFileSync(node26, [path.join(repoRoot, 'node_modules/.bin/vite'), 'build'], {
      cwd: path.join(repoRoot, 'packages/docx'),
      stdio: 'inherit',
    })
  }

  // PostgreSQL 18 setup: use external DB if E2E_DB_HOST is set, else ephemeral PG 18 cluster
  let pgHost = process.env.E2E_DB_HOST || process.env.DB_HOST || ''
  let pgPort = Number(process.env.E2E_DB_PORT || process.env.DB_PORT || '5432')
  let pgDb = process.env.E2E_DB_DATABASE || process.env.DB_DATABASE || 'vaulter_e2e_test'
  let pgUser = process.env.E2E_DB_USERNAME || process.env.DB_USERNAME || 'vaulter'
  let pgPass = process.env.E2E_DB_PASSWORD || process.env.DB_PASSWORD || 'secret'
  let pgDataDir = null
  const pgBinDir = '/usr/lib/postgresql/18/bin'

  if (!pgHost || !(await canConnectTcp(pgHost, pgPort))) {
    pgHost = '127.0.0.1'
    pgPort = await getFreePort()
    pgDb = 'vaulter_e2e_test'
    pgUser = 'vaulter'
    pgPass = 'secret'
    pgDataDir = path.join(tmpDir, 'pgdata')
    const pgSockDir = path.join(tmpDir, 'pgsock')
    fs.mkdirSync(pgSockDir, { recursive: true })

    execFileSync(path.join(pgBinDir, 'initdb'), ['-D', pgDataDir, '-U', pgUser, '-A', 'trust', '-E', 'UTF8', '--no-locale'], {
      stdio: 'pipe',
    })
    execFileSync(
      path.join(pgBinDir, 'pg_ctl'),
      ['-D', pgDataDir, '-l', path.join(tmpDir, 'pg.log'), '-o', `-k ${pgSockDir} -p ${pgPort} -h 127.0.0.1`, 'start'],
      { stdio: 'pipe' },
    )
    execFileSync(path.join(pgBinDir, 'createdb'), ['-h', pgHost, '-p', String(pgPort), '-U', pgUser, pgDb], {
      stdio: 'pipe',
    })
  }

  const dbEnv = {
    DB_CONNECTION: 'pgsql',
    DB_HOST: pgHost,
    DB_PORT: String(pgPort),
    DB_DATABASE: pgDb,
    DB_USERNAME: pgUser,
    DB_PASSWORD: pgPass,
  }

  // Run suite migrations against PG 18
  execFileSync('php', [path.join(repoRoot, 'scripts/ci/migrate-suite.php')], {
    cwd: repoRoot,
    env: { ...process.env, ...dbEnv, VAULTER_TEST_IDS_STRATEGY: 'bigint' },
    stdio: 'inherit',
  })

  const laravelPort = await getFreePort()
  const hubPort = await getFreePort()
  const hubMgmtPort = await getFreePort()
  const vitePort = await getFreePort()

  const backendUrl = `http://127.0.0.1:${laravelPort}`
  const hubWsUrl = `ws://127.0.0.1:${hubPort}?protocol=2`
  const jwtKeys = 'e2e-1:e2e-collab-jwt-secret-that-is-at-least-32-bytes'
  const webhookSecret = 'e2e-collab-webhook-secret'

  const phpProc = spawn('php', ['-S', `127.0.0.1:${laravelPort}`, path.join(repoRoot, 'tests/e2e/php/router.php')], {
    cwd: repoRoot,
    env: {
      ...process.env,
      ...dbEnv,
      PHP_CLI_SERVER_WORKERS: '4',
      APP_URL: backendUrl,
      E2E_CACHE_PATH: cachePath,
      VAULTER_DOCX_NODE_BIN: node26,
      VAULTER_DOCX_CLI_PATH: path.join(repoRoot, 'packages/docx/dist/cli.js'),
      VAULTER_COLLAB_URL: hubWsUrl,
      VAULTER_COLLAB_JWT_KEYS: jwtKeys,
      VAULTER_COLLAB_JWT_ACTIVE_KID: 'e2e-1',
      VAULTER_COLLAB_WEBHOOK_SECRET: webhookSecret,
      VAULTER_COLLAB_ISSUER: backendUrl,
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  })

  let phpLogs = ''
  phpProc.stdout.on('data', (d) => {
    phpLogs += d.toString()
  })
  phpProc.stderr.on('data', (d) => {
    phpLogs += d.toString()
  })

  try {
    await waitForHttp(`${backendUrl}/e2e/health`, 30_000, async (res) => {
      const ct = res.headers.get('content-type') || ''
      if (!ct.includes('application/json')) return false
      const body = await res.json().catch(() => null)
      return body?.status === 'ok'
    })
  } catch (err) {
    throw new Error(`Laravel Testbench failed to start: ${err.message}\n${phpLogs}`)
  }

  const hubCli = process.env.VAULTER_COLLAB_HUB_CLI || path.join(packagesRoot, 'vaulter-collaboration/packages/hub/src/cli.js')
  const hubProc = spawn(node26, [hubCli, 'serve'], {
    cwd: path.dirname(hubCli),
    env: {
      ...process.env,
      NODE_ENV: 'development',
      VAULTER_COLLAB_PORT: String(hubPort),
      VAULTER_COLLAB_MANAGEMENT_PORT: String(hubMgmtPort),
      VAULTER_COLLAB_WEBHOOK_SECRET: webhookSecret,
      VAULTER_BACKEND_URL: backendUrl,
      VAULTER_API_PREFIX: 'api/vaulter',
      VAULTER_COLLAB_REDIS_URL: '',
      VAULTER_COLLAB_COMPACTION_INTERVAL_MS: '1000',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  })

  let hubLogs = ''
  hubProc.stderr.on('data', (d) => {
    hubLogs += d.toString()
  })

  try {
    await waitForHttp(`http://127.0.0.1:${hubMgmtPort}/livez`, 30_000)
    await waitForHttp(`http://127.0.0.1:${hubMgmtPort}/readyz`, 30_000)
  } catch (err) {
    throw new Error(`Collab Hub failed to start: ${err.message}\n${hubLogs}`)
  }

  const vaulterVueSrc = path.join(packagesRoot, 'vaulter/packages/vue/src/index.ts')
  const vaulterCollabVueSrc = path.join(packagesRoot, 'vaulter-collaboration/packages/vue-collaboration/src/index.ts')

  const vite = await createServer({
    configFile: false,
    root: path.join(repoRoot, 'tests/e2e/frontend'),
    plugins: [vue()],
    resolve: {
      alias: {
        '@vaulter/editor-pro-vue': path.join(repoRoot, 'packages/vue/src/index.ts'),
        '@vaulter/editor-pro-docx/browser': path.join(repoRoot, 'packages/docx/src/browser.ts'),
        '@vaulter/editor-pro-docx': path.join(repoRoot, 'packages/docx/src/index.ts'),
        ...(fs.existsSync(vaulterVueSrc) ? { '@vaulter/vue': vaulterVueSrc } : {}),
        ...(fs.existsSync(vaulterCollabVueSrc) ? { '@vaulter/vue-collaboration': vaulterCollabVueSrc } : {}),
      },
      dedupe: ['vue', 'yjs', '@tiptap/pm', '@tiptap/core', 'lib0'],
    },
    worker: {
      format: 'es',
    },
    server: {
      host: '127.0.0.1',
      port: vitePort,
      strictPort: true,
      fs: {
        allow: [repoRoot, packagesRoot],
      },
      proxy: {
        '/api': { target: backendUrl, changeOrigin: false },
        '/e2e': { target: backendUrl, changeOrigin: false },
      },
    },
  })

  await vite.listen()

  const viteUrl = `http://127.0.0.1:${vitePort}`
  process.env.E2E_BASE_URL = viteUrl
  process.env.E2E_BACKEND_URL = backendUrl
  process.env.E2E_HUB_WS_URL = hubWsUrl

  const cleanup = async () => {
    await vite.close().catch(() => {})
    hubProc.kill('SIGTERM')
    phpProc.kill('SIGTERM')
    if (pgDataDir) {
      try {
        execFileSync(path.join(pgBinDir, 'pg_ctl'), ['-D', pgDataDir, 'stop', '-m', 'immediate'], { stdio: 'ignore' })
      } catch {
        // ignore
      }
    }
    fs.rmSync(tmpDir, { recursive: true, force: true })
  }

  for (const sig of ['SIGINT', 'SIGTERM']) {
    process.once(sig, () => {
      void cleanup().finally(() => process.exit(1))
    })
  }

  return cleanup
}
