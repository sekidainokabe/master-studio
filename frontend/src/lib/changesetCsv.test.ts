import { describe, expect, it } from 'vitest'
import { ApiError } from './api'
import { importErrorMessages, importSummary } from './changesetCsv'

describe('importSummary', () => {
  it('追加・更新・変化なしの件数を並べる', () => {
    expect(importSummary({ created: 1, updated: 0, unchanged: 20 })).toBe('追加 1 件・更新 0 件・変化なし 20 件')
  })
})

describe('importErrorMessages', () => {
  it('422 はサーバーの理由をすべて並べる', () => {
    const error = new ApiError(422, {
      message: 'x',
      errors: { file: ['2 行目：record_key がありません。', '3 行目：列の数（3）が見出し（2）と合いません。'] },
    })

    expect(importErrorMessages(error)).toEqual([
      '2 行目：record_key がありません。',
      '3 行目：列の数（3）が見出し（2）と合いません。',
    ])
  })

  it('権限や状態のエラーは、画面での案内に置き換える', () => {
    expect(importErrorMessages(new ApiError(403, null))).toEqual(['取り込む権限がありません。'])
    expect(importErrorMessages(new ApiError(409, null))[0]).toContain('下書きではなくなった')
    expect(importErrorMessages(new Error('network'))[0]).toContain('時間をおいて')
  })
})
