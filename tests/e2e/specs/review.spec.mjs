import { expect, test } from '@playwright/test'

test.describe('REVIEW', () => {
  test('multi-client tracked changes, bulk accept/reject, and generation_changed after restore', async ({ browser }) => {
    const baseUrl = process.env.E2E_BASE_URL
    const setupRes = await fetch(`${baseUrl}/e2e/setup`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: 'Review E2E' }),
    })
    expect(setupRes.ok).toBe(true)
    const { node_id: nodeId } = await setupRes.json()

    const ctxA = await browser.newContext()
    const ctxB = await browser.newContext()
    const pageA = await ctxA.newPage()
    const pageB = await ctxB.newPage()

    try {
      await Promise.all([
        pageA.goto(`${baseUrl}/?mode=review&node=${nodeId}&user=1`),
        pageB.goto(`${baseUrl}/?mode=review&node=${nodeId}&user=3`),
      ])

      await expect(pageA.locator('[data-testid="review-editor"]')).toBeVisible()
      await expect(pageB.locator('[data-testid="review-editor"]')).toBeVisible()

      // Context A (user 1) authors an insert suggestion
      await pageA.locator('[data-testid="toggle-suggesting"]').click()
      await pageA.locator('[data-testid="save-insert-blk1"]').click()
      await expect(pageA.locator('[data-testid="review-save-status"]')).toContainText('saved:')
      await expect(pageA.locator('.vaulter-tracked-changes__item')).toHaveCount(1)
      const author1 = await pageA.locator('.vaulter-tracked-changes__item').first().getAttribute('data-author')
      expect(author1).toBeTruthy()

      // Context B (user 3) authors a delete suggestion
      await pageB.locator('[data-testid="toggle-suggesting"]').click()
      await pageB.locator('[data-testid="save-delete-blk2"]').click()
      await expect(pageB.locator('.vaulter-tracked-changes__item')).toHaveCount(2)
      const authors = await pageB.locator('.vaulter-tracked-changes__item').evaluateAll((els) =>
        els.map((el) => el.getAttribute('data-author')).filter(Boolean),
      )
      const author3 = authors.find((a) => a !== author1)
      expect(author3).toBeTruthy()

      // Context A reloads and sees both authors' suggestions
      await pageA.reload()
      await expect(pageA.locator('.vaulter-tracked-changes__item')).toHaveCount(2)

      // Switch projection
      await pageA.locator('[data-projection="proposed"]').click()
      await pageA.locator('[data-projection="review"]').click()

      // Accept the first suggestion -> 1 suggestion transitions to accepted, 1 remains open
      await pageA.locator('.vaulter-tracked-changes__item').first().locator('.vaulter-tracked-changes__accept').click()
      await expect(pageA.locator('.vaulter-tracked-changes__error')).toHaveCount(0)
      await expect(pageA.locator('.vaulter-tracked-changes__item[data-state="accepted"]')).toHaveCount(1)

      // Restore Revision 1 -> advances generation -> remaining pending suggestion becomes generation_changed
      await pageA.locator('[data-testid="restore-rev-1"]').click()
      const staleSection = pageA.locator('[data-testid="stale-generation-suggestions"]')
      await expect(staleSection.locator('[data-generation-changed="true"]')).toHaveCount(1)

      // Bulk reject stale
      await staleSection.locator('[data-action="reject-stale"]').click()
      await expect(staleSection.locator('[data-generation-changed="true"]')).toHaveCount(0)

      const stateRes = await fetch(`${baseUrl}/e2e/document/${nodeId}/state`)
      const state = await stateRes.json()
      expect(state.generation).toBeGreaterThan(0)
    } finally {
      await Promise.all([
        pageA.goto('about:blank').catch(() => {}),
        pageB.goto('about:blank').catch(() => {}),
      ])
      await Promise.all([ctxA.close().catch(() => {}), ctxB.close().catch(() => {})])
    }
  })
})
