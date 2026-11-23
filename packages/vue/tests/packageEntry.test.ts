import { expect, it, vi } from 'vitest'
import { computeStructuralDiff } from '../src/index'

vi.mock('@vaulter/vue', () => {
  throw new Error('The optional @vaulter/vue package is unavailable')
})

it('loads the standalone diff from the package entry without @vaulter/vue', () => {
  expect(computeStructuralDiff).toBeTypeOf('function')
})
