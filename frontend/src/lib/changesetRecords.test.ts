import { describe, expect, it } from 'vitest'
import { buildRefs, countByStatus, isCellChanged, type EditRow } from './changesetRecords'

function row(overrides: Partial<EditRow>): EditRow {
  return {
    record_key: 'ab_001',
    status: 'unchanged',
    item_id: null,
    data: { name: '体当たり' },
    original: { name: '体当たり' },
    errors: {},
    ...overrides,
  }
}

describe('buildRefs', () => {
  const rows = [
    row({ record_key: 'ab_001' }),
    row({ record_key: 'ab_900', status: 'created', data: { name: '新技' }, original: null }),
    row({ record_key: 'ab_002', status: 'deleted', data: { name: '消す技' } }),
    row({ record_key: 'ab_003', data: { name: '' } }),
  ]

  it('変更セットで追加した行を選択肢に含め、削除する行は除く', () => {
    expect(buildRefs(rows).options).toEqual([
      { value: 'ab_001', label: 'ab_001 体当たり' },
      { value: 'ab_900', label: 'ab_900 新技' },
      { value: 'ab_003', label: 'ab_003 ab_003' },
    ])
  })

  it('削除する行も表示名には残す', () => {
    expect(buildRefs(rows).labels.get('ab_002')).toBe('消す技')
  })
})

describe('isCellChanged', () => {
  it('更新した行で、本番と値が違うセルだけ変更扱いにする', () => {
    const r = row({ status: 'updated', data: { name: '体当たり', hp: 20 }, original: { name: '体当たり', hp: 10 } })
    expect(isCellChanged(r, 'hp')).toBe(true)
    expect(isCellChanged(r, 'name')).toBe(false)
  })

  it('追加した行と変更していない行は変更扱いにしない', () => {
    expect(isCellChanged(row({ status: 'created', original: null }), 'name')).toBe(false)
    expect(isCellChanged(row({}), 'name')).toBe(false)
  })
})

describe('countByStatus', () => {
  it('変更した行とエラーのある行を数える', () => {
    const rows = [row({}), row({ status: 'updated', errors: { hp: '必須です' } }), row({ status: 'deleted' })]
    expect(countByStatus(rows)).toEqual({ changed: 2, errors: 1 })
  })
})
