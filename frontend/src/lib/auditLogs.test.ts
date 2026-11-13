import { describe, expect, it } from 'vitest'
import { auditDetailText, auditLogQuery, startOfLocalDay } from './auditLogs'

describe('startOfLocalDay', () => {
  it('ブラウザのタイムゾーンでの、その日の 0 時にする', () => {
    expect(startOfLocalDay('2026-11-13')).toBe(new Date(2026, 10, 13).toISOString())
  })

  it('月末をまたいで翌日にできる', () => {
    expect(startOfLocalDay('2026-11-30', 1)).toBe(new Date(2026, 11, 1).toISOString())
  })
})

describe('auditLogQuery', () => {
  it('条件がなければクエリ文字列を付けない', () => {
    expect(auditLogQuery({})).toBe('')
  })

  it('期間の終わりは、その日を含むよう翌日の 0 時にする', () => {
    const params = new URLSearchParams(auditLogQuery({ userId: 2, action: 'login', from: '2026-11-13', to: '2026-11-13', page: 2 }))
    expect(params.get('user_id')).toBe('2')
    expect(params.get('action')).toBe('login')
    expect(params.get('from')).toBe(new Date(2026, 10, 13).toISOString())
    expect(params.get('to')).toBe(new Date(2026, 10, 14).toISOString())
    expect(params.get('page')).toBe('2')
  })
})

describe('auditDetailText', () => {
  it('保存は件数をまとめる', () => {
    expect(
      auditDetailText({
        action: 'records.save',
        detail: { master: 'abilities', created: 1, updated: 2, deleted: 0, reverted: 0 },
      }),
    ).toBe('abilities：追加 1・更新 2・削除 0・取り消し 0')
  })

  it('承認は版とコメントを出す。コメントがなければ付けない', () => {
    expect(auditDetailText({ action: 'changeset.approve', detail: { version: 2, item_count: 1, comment: 'OK' } })).toBe(
      'v2 を作成（変更 1 件）：OK',
    )
    expect(auditDetailText({ action: 'changeset.approve', detail: { version: 2, item_count: 1, comment: null } })).toBe(
      'v2 を作成（変更 1 件）',
    )
  })

  it('ログインのように補足がない操作は空にする', () => {
    expect(auditDetailText({ action: 'login', detail: null })).toBe('')
  })
})
