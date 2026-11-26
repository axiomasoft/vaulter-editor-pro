import { expect, it, vi } from 'vitest'
import { computeStructuralDiff, createReviewClient } from '../src/index'

vi.mock('@vaulter/vue', () => {
  throw new Error('The optional @vaulter/vue package is unavailable')
})

it('loads the standalone diff from the package entry without @vaulter/vue', () => {
  expect(computeStructuralDiff).toBeTypeOf('function')
})

it('requests the paged envelope from both history endpoints with an omitted page size', async () => {
  const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: [], meta: { next_cursor: null } })))
  const client = createReviewClient({ fetch })
  await client.pins.page('doc')
  expect(fetch.mock.calls[0][0]).toContain('/pins?per_page=50')
  fetch.mockResolvedValueOnce(new Response(JSON.stringify({ data: [], meta: { next_cursor: null } })))
  await client.suggestions.page('doc', { cursor: 'cursor' })
  expect(fetch.mock.calls[1][0]).toContain('/suggestions?cursor=cursor&per_page=50')
})
