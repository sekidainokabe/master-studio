import { describe, expect, it } from 'vitest'
import type { EditRow } from './changesetRecords'
import { countByMaster, mergeCrossErrors, ROW_ERROR_KEY, type ValidationError } from './changesetValidation'

function row(recordKey: string, overrides: Partial<EditRow & { dirty: boolean }> = {}): EditRow & { dirty: boolean } {
  return {
    record_key: recordKey,
    status: 'unchanged',
    item_id: null,
    data: {},
    original: {},
    errors: {},
    dirty: false,
    ...overrides,
  }
}

function error(overrides: Partial<ValidationError>): ValidationError {
  return { master: 'enemies', record_key: 'en_001', column: 'ability_id', kind: 'cross', message: '参照先がありません：ab_999', ...overrides }
}

describe('mergeCrossErrors', () => {
  it('横断のエラーを、そのマスタの行の列に重ねる', () => {
    const input = [row('en_001'), row('en_002')]
    const rows = mergeCrossErrors(input, [error({})], 'enemies')

    expect(rows[0].errors).toEqual({ ability_id: '参照先がありません：ab_999' })
    // エラーのない行は同じオブジェクトのまま（表の再計算を減らす）
    expect(rows[1]).toBe(input[1])
  })

  it('画面の単一セルのエラーと同じ列なら、両方の理由をつなげる', () => {
    const rows = mergeCrossErrors([row('en_001', { errors: { ability_id: '必須です' } })], [error({})], 'enemies')

    expect(rows[0].errors.ability_id).toBe('必須です／参照先がありません：ab_999')
  })

  it('列のないエラーは行全体のエラーにする', () => {
    const rows = mergeCrossErrors(
      [row('ab_001', { status: 'deleted' })],
      [error({ master: 'abilities', record_key: 'ab_001', column: null, message: '参照されているため削除できません（敵 en_001）' })],
      'abilities',
    )

    expect(rows[0].errors).toEqual({ [ROW_ERROR_KEY]: '参照されているため削除できません（敵 en_001）' })
  })

  it('単一セルのエラー、他のマスタのエラー、未保存の編集がある行には重ねない', () => {
    const input = [row('en_001', { dirty: true }), row('en_002')]
    const rows = mergeCrossErrors(
      input,
      [error({}), error({ record_key: 'en_002', kind: 'cell', message: '必須です' }), error({ master: 'stages', record_key: 'en_002' })],
      'enemies',
    )

    expect(rows[0].errors).toEqual({})
    expect(rows[1].errors).toEqual({})
  })
})

describe('countByMaster', () => {
  it('マスタごとに件数を数える', () => {
    expect(countByMaster([error({}), error({ kind: 'cell' }), error({ master: 'stages' })])).toEqual({ enemies: 2, stages: 1 })
  })
})
