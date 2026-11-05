import { describe, expect, it } from 'vitest'
import type { CellValue, MasterColumn } from './masters'
import { validateCell, validateRecord } from './validation'

function column(overrides: Partial<MasterColumn> & Pick<MasterColumn, 'key' | 'type'>): MasterColumn {
  return {
    name: overrides.key,
    is_required: false,
    min_value: null,
    max_value: null,
    max_length: null,
    enum_values: null,
    ref_master_key: null,
    ...overrides,
  }
}

// サーバー側の tests/Unit/CellValidatorTest.php と同じ列・同じ例。結果がずれないよう、変えるときは両方を直す
const columns = {
  name: column({ key: 'name', type: 'string', is_required: true, max_length: 5 }),
  hp: column({ key: 'hp', type: 'int', is_required: true, min_value: 1, max_value: 9999 }),
  spawn_frame: column({ key: 'spawn_frame', type: 'int', min_value: 0 }),
  speed: column({ key: 'speed', type: 'float', min_value: 0, max_value: 20 }),
  respawn: column({ key: 'respawn', type: 'bool' }),
  rank: column({ key: 'rank', type: 'enum', is_required: true, enum_values: ['normal', 'boss'] }),
  ability_id: column({ key: 'ability_id', type: 'ref', ref_master_key: 'abilities' }),
}

const cases: [keyof typeof columns, CellValue, string | null][] = [
  // 必須と空欄
  ['name', null, '必須です'],
  ['name', '', '必須です'],
  ['spawn_frame', null, null],
  ['ability_id', '', null],
  // string
  ['name', 'スライム', null],
  ['name', 'あいうえお', null],
  ['name', 'あいうえおか', '5文字以内で入力してください'],
  ['name', '🐉🐉🐉🐉🐉', null],
  ['name', 123, '文字列で入力してください'],
  // int
  ['hp', 1, null],
  ['hp', 9999, null],
  ['hp', 0, '1〜9999 の範囲で入力してください'],
  ['hp', 10000, '1〜9999 の範囲で入力してください'],
  ['hp', 1.5, '整数で入力してください'],
  ['hp', '100', '整数で入力してください'],
  ['spawn_frame', 0, null],
  ['spawn_frame', -1, '0 以上で入力してください'],
  // float
  ['speed', 1, null],
  ['speed', 2.5, null],
  ['speed', 20.5, '0〜20 の範囲で入力してください'],
  ['speed', true, '数値で入力してください'],
  // bool
  ['respawn', true, null],
  ['respawn', false, null],
  ['respawn', 'true', 'はい・いいえで指定してください'],
  // enum
  ['rank', 'boss', null],
  ['rank', 'Boss', '選択肢にない値です'],
  // ref（存在するかどうかは横断検証で見る）
  ['ability_id', 'ab_001', null],
  ['ability_id', 1, '参照先の record_key を指定してください'],
]

describe('validateCell', () => {
  it.each(cases)('%s = %j → %s', (key, value, expected) => {
    expect(validateCell(columns[key], value)).toBe(expected)
  })

  it('値がない列は空欄として扱う', () => {
    expect(validateCell(columns.hp, undefined)).toBe('必須です')
  })
})

describe('validateRecord', () => {
  it('エラーのある列だけを返す', () => {
    const errors = validateRecord(Object.values(columns), {
      name: 'スライム',
      hp: 0,
      speed: 1.5,
      rank: 'king',
    })

    expect(errors).toEqual({
      hp: '1〜9999 の範囲で入力してください',
      rank: '選択肢にない値です',
    })
  })
})
