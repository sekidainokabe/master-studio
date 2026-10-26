import { describe, expect, it } from 'vitest'
import type { MasterColumn, MasterRecord, RefLabels } from './masters'
import { filterRecords, formatCell } from './recordView'

function column(key: string, type: MasterColumn['type'], extra: Partial<MasterColumn> = {}): MasterColumn {
  return {
    key,
    name: key,
    type,
    is_required: false,
    min_value: null,
    max_value: null,
    max_length: null,
    enum_values: null,
    ref_master_key: null,
    ...extra,
  }
}

const columns = [
  column('name', 'string'),
  column('rank', 'enum', { enum_values: ['normal', 'mid_boss', 'boss'] }),
  column('hp', 'int'),
  column('ability_id', 'ref', { ref_master_key: 'abilities' }),
  column('flying', 'bool'),
]

const refLabels: RefLabels = {
  abilities: new Map([
    ['ab_001', '体当たり'],
    ['ab_014', '火柱'],
  ]),
}

function record(key: string, data: MasterRecord['data']): MasterRecord {
  return { record_key: key, data, updated_at: null, updated_by: null }
}

const records = [
  record('en_001', { name: 'モコモコ', rank: 'normal', hp: 3, ability_id: null, flying: false }),
  record('en_004', { name: 'トゲッコ', rank: 'normal', hp: 5, ability_id: 'ab_001', flying: false }),
  record('en_028', { name: 'Volka', rank: 'boss', hp: 800, ability_id: 'ab_014', flying: true }),
]

const noFilter = { keyword: '', enumValues: {} }

describe('formatCell', () => {
  it('参照列は key と参照先の名前を並べる', () => {
    expect(formatCell('ab_001', columns[3], refLabels)).toBe('ab_001 体当たり')
  })

  it('参照先が見つからないときは key だけを表示する', () => {
    expect(formatCell('ab_999', columns[3], refLabels)).toBe('ab_999')
  })

  it('null は空文字、bool は「はい / いいえ」にする', () => {
    expect(formatCell(null, columns[3], refLabels)).toBe('')
    expect(formatCell(true, columns[4], refLabels)).toBe('はい')
    expect(formatCell(false, columns[4], refLabels)).toBe('いいえ')
  })

  it('数値は文字列にする', () => {
    expect(formatCell(800, columns[2], refLabels)).toBe('800')
  })
})

describe('filterRecords', () => {
  const keys = (result: MasterRecord[]) => result.map((r) => r.record_key)

  it('条件がなければすべて返す', () => {
    expect(keys(filterRecords(records, columns, refLabels, noFilter))).toEqual(['en_001', 'en_004', 'en_028'])
  })

  it('キーワードは名前・record_key・参照先の名前に部分一致する', () => {
    expect(keys(filterRecords(records, columns, refLabels, { ...noFilter, keyword: 'トゲ' }))).toEqual(['en_004'])
    expect(keys(filterRecords(records, columns, refLabels, { ...noFilter, keyword: 'en_02' }))).toEqual(['en_028'])
    expect(keys(filterRecords(records, columns, refLabels, { ...noFilter, keyword: '体当たり' }))).toEqual(['en_004'])
  })

  it('キーワードは大文字・小文字を区別せず、前後の空白を無視する', () => {
    expect(keys(filterRecords(records, columns, refLabels, { ...noFilter, keyword: '  volka ' }))).toEqual(['en_028'])
  })

  it('列挙型の列で絞り込み、空の値は条件にしない', () => {
    expect(keys(filterRecords(records, columns, refLabels, { keyword: '', enumValues: { rank: 'boss' } }))).toEqual([
      'en_028',
    ])
    expect(keys(filterRecords(records, columns, refLabels, { keyword: '', enumValues: { rank: '' } }))).toHaveLength(3)
  })

  it('キーワードと列挙型の絞り込みは両方を満たすものだけを返す', () => {
    expect(
      keys(filterRecords(records, columns, refLabels, { keyword: 'モコ', enumValues: { rank: 'boss' } })),
    ).toEqual([])
  })
})
