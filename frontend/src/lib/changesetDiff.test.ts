import { describe, expect, it } from 'vitest'
import { summarizeCounts } from './changesetDiff'

describe('summarizeCounts', () => {
  it('件数のある操作だけを、追加・変更・削除の順に並べる', () => {
    expect(summarizeCounts({ created: 1, updated: 0, deleted: 3 })).toBe('追加 1・削除 3')
    expect(summarizeCounts({ created: 0, updated: 2, deleted: 0 })).toBe('変更 2')
  })
})
