import { describe, expect, it } from 'vitest'
import { ApiError } from './api'
import { reviewError, submitBlockReason } from './changesetReview'

describe('submitBlockReason', () => {
  it('未保存の編集・変更0件・取得中・エラーありの順に理由を返す', () => {
    expect(submitBlockReason({ dirty: true, itemCount: 0, errorCount: 3 })).toBe(
      '未保存の変更を保存すると、レビューを依頼できます。',
    )
    expect(submitBlockReason({ dirty: false, itemCount: 0, errorCount: 0 })).toBe(
      '変更が1件もないため、レビューを依頼できません。',
    )
    expect(submitBlockReason({ dirty: false, itemCount: 2, errorCount: undefined })).toBe('検証しています…')
    expect(submitBlockReason({ dirty: false, itemCount: 2, errorCount: 3 })).toBe(
      '検証エラーが 3 件あります。修正すると、レビューを依頼できます。',
    )
  })

  it('依頼できるときは null', () => {
    expect(submitBlockReason({ dirty: false, itemCount: 2, errorCount: 0 })).toBeNull()
  })
})

describe('reviewError', () => {
  it('409 の競合は、メッセージとぶつかった行を返す', () => {
    const conflicts = [{ master: 'abilities', record_key: 'ab_001', message: '作成後に、リリース v2 で変更されました。' }]
    const error = new ApiError(409, { message: '本番データが変わった行があります。', conflicts })

    expect(reviewError(error)).toEqual({ message: '本番データが変わった行があります。', conflicts })
  })

  it('422 は項目ごとの理由をつなげる', () => {
    const error = new ApiError(422, { message: 'x', errors: { comment: ['却下の理由は必須です。'] } })

    expect(reviewError(error)).toEqual({ message: '却下の理由は必須です。', conflicts: [] })
  })

  it('403 はサーバーのメッセージ、通信の失敗は決まった文言', () => {
    expect(reviewError(new ApiError(403, { message: '自分が作成した変更セットは承認できません。' })).message).toBe(
      '自分が作成した変更セットは承認できません。',
    )
    expect(reviewError(new TypeError('Failed to fetch')).message).toBe(
      '通信に失敗しました。時間をおいてもう一度お試しください。',
    )
  })
})
