import { expect, test } from '@playwright/test'

test.describe('REVIEW-HISTORY', () => {
  test('pin/unpin, side-by-side & inline compare, and strictly read-only preview', async ({ page }) => {
    const baseUrl = process.env.E2E_BASE_URL
    const setupRes = await fetch(`${baseUrl}/e2e/setup`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: 'History E2E' }),
    })
    expect(setupRes.ok).toBe(true)
    const { node_id: nodeId, first_revision_id: rev1Id } = await setupRes.json()

    // Create Revision 2 (added text + bold mark)
    const rev2Res = await fetch(`${baseUrl}/e2e/document/${nodeId}/revision`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-E2E-User': '1' },
      body: JSON.stringify({
        label: 'Rev 2',
        content: {
          type: 'doc',
          content: [
            {
              type: 'paragraph',
              attrs: { blockId: 'blk_1' },
              content: [
                { type: 'text', text: 'Hello target clause in paragraph one. ' },
                { type: 'text', text: 'Added bold clause.', marks: [{ type: 'bold' }] },
              ],
            },
            {
              type: 'paragraph',
              attrs: { blockId: 'blk_2' },
              content: [{ type: 'text', text: 'Second paragraph tail.' }],
            },
          ],
        },
      }),
    })
    expect(rev2Res.ok).toBe(true)

    // Create Revision 3 (deletion)
    const rev3Res = await fetch(`${baseUrl}/e2e/document/${nodeId}/revision`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-E2E-User': '1' },
      body: JSON.stringify({
        label: 'Rev 3',
        content: {
          type: 'doc',
          content: [
            {
              type: 'paragraph',
              attrs: { blockId: 'blk_1' },
              content: [{ type: 'text', text: 'Hello target clause in paragraph one. Added bold clause.' }],
            },
          ],
        },
      }),
    })
    expect(rev3Res.ok).toBe(true)

    await page.goto(`${baseUrl}/?mode=history&node=${nodeId}&user=1`)

    // Side-by-side compare
    const root = page.locator('.vaulter-history-compare')
    await expect(root).toHaveAttribute('data-mode', 'compare')
    await expect(root).toHaveAttribute('data-layout', 'side-by-side')
    await expect(root.locator('[data-pane="base"]')).toBeVisible()
    await expect(root.locator('[data-pane="target"]')).toBeVisible()

    // Pin Revision 1
    const rev1Item = root.locator(`[data-testid="revision-timeline"] [data-revision-id="${rev1Id}"]`)
    await rev1Item.locator('[data-action="pin"]').click()
    await expect(rev1Item).toHaveAttribute('data-pinned', 'true')

    // Switch to inline layout
    await root.locator('[data-layout-option="inline"]').click()
    await expect(root).toHaveAttribute('data-layout', 'inline')
    expect(Number((await root.locator('[data-stat="inserts"]').textContent())?.replace(/\D+/g, '') || '0')).toBeGreaterThan(0)

    // Switch to isolated preview mode and verify zero edits on click/typing
    await page.locator('[data-testid="toggle-preview-mode"]').click()
    await expect(root).toHaveAttribute('data-mode', 'preview')
    await expect(root).toHaveAttribute('data-readonly', 'true')

    const beforeText = await root.textContent()
    await root.click()
    await page.keyboard.type('UNWANTED_MUTATION')
    await expect(root).toHaveText(beforeText ?? '')

    // Unpin Revision 1
    await page.locator('[data-testid="toggle-preview-mode"]').click()
    await rev1Item.locator('[data-action="unpin"]').click()
    await expect(rev1Item).toHaveAttribute('data-pinned', 'false')
  })
})
