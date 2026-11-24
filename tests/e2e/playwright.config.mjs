import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './specs',
  globalSetup: './harness.mjs',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  outputDir: '/tmp/vaulter-editor-pro-pw-results',
  reporter: [['list']],
  use: {
    headless: true,
    channel: 'chromium',
    trace: 'retain-on-failure',
    acceptDownloads: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
