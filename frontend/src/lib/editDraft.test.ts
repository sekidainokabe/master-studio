import { describe, expect, it } from 'vitest'
import type { EditRow } from './changesetRecords'
import { applyDrafts, buildPayload, emptyRecord, parseCellInput, validateNewKey } from './editDraft'
import type { MasterColumn } from './masters'

function column(overrides: Partial<MasterColumn>): MasterColumn {
  return {
    key: 'name',
    name: '名前',
    type: 'string',
    is_required: true,
    min_value: null,
    max_value: null,
    max_length: 20,
    enum_values: null,
    ref_master_key: null,
    ...overrides,
  }
}

const columns = [column({}), column({ key: 'hp', name: 'HP', type: 'int', min_value: 1, max_value: 9999, max_length: null })]

function row(overrides: Partial<EditRow>): EditRow {
  return {
    record_key: 'en_001',
    status: 'unchanged',
    item_id: null,
    data: { name: 'モコモコ', hp: 3 },
    original: { name: 'モコモコ', hp: 3 },
    errors: {},
    ...overrides,
  }
}

describe('applyDrafts', () => {
  it('編集のない行はそのまま表示する', () => {
    expect(applyDrafts([row({})], {}, columns)).toEqual([{ ...row({}), dirty: false }])
  })

  it('値を変えた行は「変更」にして、その場で検証する', () => {
    const [shown] = applyDrafts([row({})], { en_001: { name: 'モコモコ', hp: 0 } }, columns)
    expect(shown).toMatchObject({ status: 'updated', dirty: true, errors: { hp: '1〜9999 の範囲で入力してください' } })
  })

  it('本番と同じ値に戻した行は「変更なし」になる', () => {
    const saved = row({ status: 'updated', data: { name: 'モコモコ', hp: 8 } })
    const [shown] = applyDrafts([saved], { en_001: { name: 'モコモコ', hp: 3 } }, columns)
    expect(shown.status).toBe('unchanged')
  })

  it('削除した行は「削除」にして、エラーを出さない', () => {
    const [shown] = applyDrafts([row({ errors: { hp: '必須です' } })], { en_001: null }, columns)
    expect(shown).toMatchObject({ status: 'deleted', errors: {}, dirty: true })
  })

  it('サーバーにない行は「追加」として末尾に並べる', () => {
    const shown = applyDrafts([row({})], { en_900: emptyRecord(columns) }, columns)
    expect(shown.map((r) => [r.record_key, r.status])).toEqual([
      ['en_001', 'unchanged'],
      ['en_900', 'created'],
    ])
    expect(shown[1].errors).toEqual({ name: '必須です', hp: '必須です' })
  })
})

describe('buildPayload', () => {
  it('編集した行だけを、削除は data: null として送る', () => {
    expect(buildPayload({ en_001: { name: 'A', hp: 1 }, en_002: null })).toEqual({
      records: [
        { record_key: 'en_001', data: { name: 'A', hp: 1 } },
        { record_key: 'en_002', data: null },
      ],
    })
  })
})

describe('parseCellInput', () => {
  const hp = columns[1]
  it.each([
    ['', null],
    ['12', 12],
    ['-3', -3],
    [' 1.5 ', 1.5],
    ['1.', '1.'],
    ['abc', 'abc'],
  ])('数値の列で %j は %j になる', (text, expected) => {
    expect(parseCellInput(hp, text)).toBe(expected)
  })

  it('文字の列では数字も文字のまま', () => {
    expect(parseCellInput(columns[0], '12')).toBe('12')
  })
})

describe('validateNewKey', () => {
  it.each([
    ['', 'record_key を入力してください'],
    ['a'.repeat(51), 'record_key は50文字以内で入力してください'],
    ['En-1', 'record_key は半角の英小文字・数字・_ で入力してください'],
    ['en_001', 'その record_key はすでにあります'],
    ['en_900', null],
  ])('%j → %j', (key, expected) => {
    expect(validateNewKey(key, ['en_001'])).toBe(expected)
  })
})
