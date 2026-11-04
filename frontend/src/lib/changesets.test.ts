import { describe, expect, it } from 'vitest'
import { changesetListQuery } from './changesets'

describe('changesetListQuery', () => {
  it('条件がなければクエリ文字列を付けない', () => {
    expect(changesetListQuery({})).toBe('')
  })

  it('状態・作成者・ページを付ける', () => {
    expect(changesetListQuery({ status: 'in_review', authorId: 3, page: 2 })).toBe('?status=in_review&author_id=3&page=2')
  })

  it('1ページ目は page を付けない', () => {
    expect(changesetListQuery({ status: 'draft', page: 1 })).toBe('?status=draft')
  })
})
