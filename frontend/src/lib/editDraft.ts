import type { EditRow } from './changesetRecords'
import type { CellValue, MasterColumn } from './masters'
import { validateRecord } from './validation'

/**
 * まだ保存していない編集内容。record_key → 行の値（null は「削除する」）。
 * 画面ではサーバーの行にこれを重ねて表示し、保存のときはこれだけを PUT で送る
 */
export type Drafts = Record<string, Record<string, CellValue> | null>

export type DisplayRow = EditRow & {
  /** 保存していない編集があるか */
  dirty: boolean
}

/** サーバーの行に、保存前の編集を重ねる。編集した行は、その場で検証し直す */
export function applyDrafts(rows: EditRow[], drafts: Drafts, columns: MasterColumn[]): DisplayRow[] {
  const shown: DisplayRow[] = rows.map((row) => {
    if (!(row.record_key in drafts)) {
      return { ...row, dirty: false }
    }
    const data = drafts[row.record_key]
    if (data === null) {
      // 削除する行は本番に残らないので、検証しない（サーバーと同じ）
      return { ...row, status: 'deleted', errors: {}, dirty: true }
    }
    const status = row.original === null ? 'created' : sameData(row.original, data, columns) ? 'unchanged' : 'updated'
    return { ...row, status, data, errors: validateRecord(columns, data), dirty: true }
  })

  const existing = new Set(rows.map((row) => row.record_key))
  const added: DisplayRow[] = Object.entries(drafts).flatMap(([recordKey, data]) =>
    existing.has(recordKey) || data === null
      ? []
      : [
          {
            record_key: recordKey,
            status: 'created' as const,
            item_id: null,
            data,
            original: null,
            errors: validateRecord(columns, data),
            dirty: true,
          },
        ],
  )

  return [...shown, ...added]
}

function sameData(a: Record<string, CellValue>, b: Record<string, CellValue>, columns: MasterColumn[]): boolean {
  return columns.every((column) => (a[column.key] ?? null) === (b[column.key] ?? null))
}

/** PUT /api/changesets/{id}/masters/{key}/records で送るデータ */
export function buildPayload(drafts: Drafts): { records: { record_key: string; data: Record<string, CellValue> | null }[] } {
  return { records: Object.entries(drafts).map(([record_key, data]) => ({ record_key, data })) }
}

/** 新しい行の値。すべての列を空にしておき、必須の列はエラーで入力を促す */
export function emptyRecord(columns: MasterColumn[]): Record<string, CellValue> {
  return Object.fromEntries(columns.map((column) => [column.key, null]))
}

const NUMBER_PATTERN = /^-?\d+(\.\d+)?$/

/**
 * 入力欄の文字を、列の型に合わせた値にする。
 * 数値として読めない文字（入力途中の「1.」など）は文字のまま残し、検証のエラーで知らせる
 */
export function parseCellInput(column: MasterColumn, text: string): CellValue {
  if (text === '') {
    return null
  }
  if ((column.type === 'int' || column.type === 'float') && NUMBER_PATTERN.test(text.trim())) {
    return Number(text.trim())
  }
  return text
}

/** 追加する行の record_key の検証（サーバーの UpdateChangesetRecordsRequest と同じルール） */
export function validateNewKey(recordKey: string, existingKeys: Iterable<string>): string | null {
  if (recordKey === '') {
    return 'record_key を入力してください'
  }
  if (recordKey.length > 50) {
    return 'record_key は50文字以内で入力してください'
  }
  if (!/^[a-z0-9_]+$/.test(recordKey)) {
    return 'record_key は半角の英小文字・数字・_ で入力してください'
  }
  if (new Set(existingKeys).has(recordKey)) {
    return 'その record_key はすでにあります'
  }
  return null
}
