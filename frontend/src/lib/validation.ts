import type { CellValue, MasterColumn } from './masters'

/**
 * 単一セルの検証（docs/SPEC.md 5章）：型、必須、数値範囲、文字数、列挙値。
 * 入力してすぐに結果を出すためのもの。サーバーの app/Validation/CellValidator.php も同じルールで最終判定するので、
 * ルールやメッセージを変えるときは両方を直し、両方のテストにある共通の例も合わせて直す。
 *
 * 参照先が存在するかどうかは、他のレコードを見ないと分からないため、ここでは扱わない（横断検証、第5週）。
 */
export function validateCell(column: MasterColumn, value: CellValue | undefined): string | null {
  // 空欄は null と '' のどちらでも表せるので、同じものとして扱う
  if (value === null || value === undefined || value === '') {
    return column.is_required ? '必須です' : null
  }

  switch (column.type) {
    case 'int':
      if (typeof value !== 'number' || !Number.isInteger(value)) {
        return '整数で入力してください'
      }
      return checkRange(column, value)
    case 'float':
      if (typeof value !== 'number' || !Number.isFinite(value)) {
        return '数値で入力してください'
      }
      return checkRange(column, value)
    case 'string':
      if (typeof value !== 'string') {
        return '文字列で入力してください'
      }
      // length は UTF-16 の単位で数えるため、絵文字などが2文字になる。サーバーの mb_strlen と合わせて、文字単位で数える
      if (column.max_length !== null && [...value].length > column.max_length) {
        return `${column.max_length}文字以内で入力してください`
      }
      return null
    case 'bool':
      return typeof value === 'boolean' ? null : 'はい・いいえで指定してください'
    case 'enum':
      return typeof value === 'string' && (column.enum_values ?? []).includes(value) ? null : '選択肢にない値です'
    case 'ref':
      return typeof value === 'string' ? null : '参照先の record_key を指定してください'
  }
}

function checkRange(column: MasterColumn, value: number): string | null {
  const { min_value: min, max_value: max } = column
  if (min !== null && max !== null) {
    return value < min || value > max ? `${min}〜${max} の範囲で入力してください` : null
  }
  if (min !== null && value < min) {
    return `${min} 以上で入力してください`
  }
  if (max !== null && value > max) {
    return `${max} 以下で入力してください`
  }
  return null
}

/** 1行分の検証結果。エラーのある列だけを、列の key → メッセージで返す */
export function validateRecord(columns: MasterColumn[], data: Record<string, CellValue>): Record<string, string> {
  const errors: Record<string, string> = {}
  for (const column of columns) {
    const error = validateCell(column, data[column.key])
    if (error !== null) {
      errors[column.key] = error
    }
  }
  return errors
}
