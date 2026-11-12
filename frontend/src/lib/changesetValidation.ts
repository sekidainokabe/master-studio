import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './api'
import type { EditRow } from './changesetRecords'

/** 変更セット全体の検証エラー1件。column が null のものは行全体のエラー（参照されている行の削除など） */
export type ValidationError = {
  master: string
  record_key: string
  column: string | null
  kind: 'cell' | 'cross'
  message: string
}

export type ValidationResult = {
  errors: ValidationError[]
  counts: { total: number; cell: number; cross: number }
}

/** 行の errors の中で、行全体のエラーを入れるキー（列の key は英小文字・数字・_ なので重ならない） */
export const ROW_ERROR_KEY = '#row'

export const changesetValidationKey = (changesetId: number) => ['changesets', changesetId, 'validation'] as const

/** 保存済みの内容に対する、単一セルと横断の検証結果（サーバーが全マスタを見て判定する） */
export function useChangesetValidation(changesetId: number) {
  return useQuery({
    queryKey: changesetValidationKey(changesetId),
    queryFn: () => apiFetch<{ data: ValidationResult }>(`/api/changesets/${changesetId}/validation`).then((res) => res.data),
  })
}

/**
 * 表の行に、サーバーの横断検証のエラーを重ねる。
 * 単一セルのエラーは画面でその場で出しているので、重ねるのは横断（kind=cross）だけ。
 * 未保存の編集がある行は、サーバーの結果が古い内容に対するものなので重ねない（保存のあとに取り直す）
 */
export function mergeCrossErrors<T extends EditRow & { dirty?: boolean }>(
  rows: T[],
  errors: ValidationError[],
  masterKey: string,
): T[] {
  const byRow = new Map<string, ValidationError[]>()
  for (const error of errors) {
    if (error.kind === 'cross' && error.master === masterKey) {
      byRow.set(error.record_key, [...(byRow.get(error.record_key) ?? []), error])
    }
  }
  if (byRow.size === 0) {
    return rows
  }

  return rows.map((row) => {
    const crossErrors = byRow.get(row.record_key)
    if (!crossErrors || row.dirty) {
      return row
    }
    const merged = { ...row.errors }
    for (const { column, message } of crossErrors) {
      const key = column ?? ROW_ERROR_KEY
      merged[key] = merged[key] ? `${merged[key]}／${message}` : message
    }
    return { ...row, errors: merged }
  })
}

/** マスタごとのエラー件数（タブに表示する） */
export function countByMaster(errors: ValidationError[]): Record<string, number> {
  const counts: Record<string, number> = {}
  for (const error of errors) {
    counts[error.master] = (counts[error.master] ?? 0) + 1
  }
  return counts
}
