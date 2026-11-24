import { expect, test } from '@playwright/test'

test.describe('COMMENTS', () => {
  test('2 browser contexts: create, reply, live overlayEvents, reanchor under edits, resolve', async ({ browser }) => {
    const baseUrl = process.env.E2E_BASE_URL
    const setupRes = await fetch(`${baseUrl}/e2e/setup`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: 'Comments E2E' }),
    })
    if (!setupRes.ok) {
      throw new Error(`setup failed (${setupRes.status}): ${await setupRes.text()}`)
    }
    const { node_id: nodeId } = await setupRes.json()

    const ctxA = await browser.newContext()
    const ctxB = await browser.newContext()
    const pageA = await ctxA.newPage()
    const pageB = await ctxB.newPage()

    try {
      await Promise.all([
        pageA.goto(`${baseUrl}/?mode=comments&node=${nodeId}&user=1`),
        pageB.goto(`${baseUrl}/?mode=comments&node=${nodeId}&user=3`),
      ])

      await expect(pageA.locator('[data-testid="collab-status"]')).toHaveAttribute('data-status', 'connected:writable')
      await expect(pageB.locator('[data-testid="collab-status"]')).toHaveAttribute('data-status', 'connected:writable')

      const initialOverlayCountB = Number(await pageB.locator('[data-testid="overlay-events-count"]').textContent())

      // Context A selects phrase and creates anchored comment
      await pageA.locator('[data-testid="select-target-phrase"]').click()
      await pageA.locator('.vaulter-range-comments__create-input').fill('Check target wording')
      await pageA.locator('[data-testid="create-comment-form"] [data-action="create"]').click()

      await expect(pageA.locator('.vaulter-range-comments__thread')).toContainText('Check target wording')

      // Context B receives live overlayEvents over Hub WebSocket without reload
      await expect
        .poll(async () => Number(await pageB.locator('[data-testid="overlay-events-count"]').textContent()))
        .toBeGreaterThan(initialOverlayCountB)
      await expect(pageB.locator('.vaulter-range-comments__thread')).toContainText('Check target wording')

      // Context B replies; Context A receives live overlayEvents
      const initialOverlayCountA = Number(await pageA.locator('[data-testid="overlay-events-count"]').textContent())
      await pageB.locator('.vaulter-range-comments__reply-input').fill('Agreed, updated')
      await pageB.locator('.vaulter-range-comments__reply-form [data-action="reply"]').click()

      await expect
        .poll(async () => Number(await pageA.locator('[data-testid="overlay-events-count"]').textContent()))
        .toBeGreaterThan(initialOverlayCountA)
      await expect(pageA.locator('.vaulter-range-comments__reply')).toContainText('Agreed, updated')

      // Context B edits document BEFORE the anchored range; Yjs CRDT syncs and anchor stays valid
      await pageB.locator('[data-testid="prepend-prefix"]').click()
      await expect(pageA.locator('[data-testid="collab-editor"]')).toContainText('Prefix added by peer')

      // Context A reanchors onto second paragraph
      await pageA.locator('[data-testid="select-second-paragraph"]').click()
      await pageA.locator('[data-testid="confirm-reanchor"]').click()

      // Context A resolves thread; Context B reflects resolved state live
      await pageA.locator('[data-action="resolve"]').first().click()
      await expect(pageA.locator('.vaulter-range-comments__thread').first()).toHaveAttribute('data-resolved', 'true')
      await expect(pageB.locator('.vaulter-range-comments__thread').first()).toHaveAttribute('data-resolved', 'true')

      // Verify PostgreSQL persistence via REST API
      const commentsRes = await fetch(`${baseUrl}/api/vaulter/v1/review/nodes/${nodeId}/comments`, {
        headers: { Accept: 'application/json', 'X-E2E-User': '1' },
      })
      expect(commentsRes.ok).toBe(true)
      const commentsJson = await commentsRes.json()
      expect(commentsJson.data.length).toBeGreaterThanOrEqual(1)
      expect(commentsJson.data[0].resolved_at).not.toBeNull()
    } finally {
      await Promise.all([
        pageA.goto('about:blank').catch(() => {}),
        pageB.goto('about:blank').catch(() => {}),
      ])
      await Promise.all([ctxA.close().catch(() => {}), ctxB.close().catch(() => {})])
    }
  })
})
