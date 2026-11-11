import { useCallback } from 'react'
import { useMutation, useQueries, useQuery, useQueryClient, type UseQueryResult } from '@tanstack/react-query'
import { apiFetch } from './api'
import { changesetKeys } from './changesets'
import type { CellValue, MasterColumn, RefLabels } from './masters'

export type RowStatus = 'unchanged' | 'created' | 'updated' | 'deleted'

/** 変更セットの編集用データの1行（本番データに変更を重ねたもの） */
export type EditRow = {
  record_key: string
  status: RowStatus
  /** 変更の記録（changeset_items）の id。変更していない行は null */
  item_id: number | null
  data: Record<string, CellValue>
  /** 変更前の本番の値。変更セットで追加した行は null */
  original: Record<string, CellValue> | null
  /** 列の key → エラーの理由 */
  errors: Record<string, string>
}

export type RefOption = { value: string; label: string }

export const changesetRecordKeys = {
  records: (changesetId: number, masterKey: string) => ['changesets', changesetId, 'records', masterKey] as const,
}

function fetchRows(changesetId: number, masterKey: string) {
  return apiFetch<{ data: EditRow[] }>(`/api/changesets/${changesetId}/masters/${masterKey}/records`).then(
    (res) => res.data,
  )
}

export function useChangesetRecords(changesetId: number, masterKey: string) {
  return useQuery({
    queryKey: changesetRecordKeys.records(changesetId, masterKey),
    queryFn: () => fetchRows(changesetId, masterKey),
  })
}

/** 編集した行をまとめて保存する。サーバーは保存後の行（検証結果つき）を返す */
export function useSaveChangesetRecords(changesetId: number, masterKey: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: { records: { record_key: string; data: Record<string, CellValue> | null }[] }) =>
      apiFetch<{ data: EditRow[] }>(`/api/changesets/${changesetId}/masters/${masterKey}/records`, {
        method: 'PUT',
        body: payload,
      }).then((res) => res.data),
    onSuccess: (rows) => {
      queryClient.setQueryData(changesetRecordKeys.records(changesetId, masterKey), rows)
      // 変更件数や更新日時が変わるので、変更セットの詳細と一覧も取り直させる
      queryClient.invalidateQueries({ queryKey: changesetKeys.detail(changesetId) })
      queryClient.invalidateQueries({ queryKey: [...changesetKeys.all, 'list'] })
    },
  })
}

/**
 * 参照列の表示名と選択肢を作る。
 * 本番データではなく「同じ変更セットの編集用データ」から作るので、変更セットで追加した行も選べる（SPEC 5章）
 */
export function useChangesetRefs(
  changesetId: number,
  columns: MasterColumn[] | undefined,
): { labels: RefLabels; options: Record<string, RefOption[]>; isPending: boolean } {
  const refKeysText = [...new Set((columns ?? []).flatMap((c) => (c.ref_master_key ? [c.ref_master_key] : [])))].join(
    ',',
  )

  // combine は関数の参照が変わると毎回作り直される。結果が毎回新しくなると表の列定義も毎回作り直しになるため、
  // 参照先が変わらない限り同じ関数を使い、取得結果が変わったときだけ作り直させる
  const combine = useCallback(
    (results: UseQueryResult<EditRow[]>[]) => {
      const refKeys = refKeysText === '' ? [] : refKeysText.split(',')
      const built = results.map((r) => buildRefs(r.data ?? []))
      return {
        isPending: results.some((r) => r.isPending),
        labels: Object.fromEntries(refKeys.map((key, i) => [key, built[i].labels])),
        options: Object.fromEntries(refKeys.map((key, i) => [key, built[i].options])),
      }
    },
    [refKeysText],
  )

  return useQueries({
    queries: (refKeysText === '' ? [] : refKeysText.split(',')).map((key) => ({
      queryKey: changesetRecordKeys.records(changesetId, key),
      queryFn: () => fetchRows(changesetId, key),
    })),
    combine,
  })
}

/**
 * 参照先の行から、表示名の対応表と選択肢を作る。
 * 削除する行は選択肢に出さない（参照すると反映後に参照切れになるため）。表示名には残し、既存の参照は読めるようにする
 */
export function buildRefs(rows: EditRow[]): { labels: Map<string, string>; options: RefOption[] } {
  const labels = new Map(rows.map((row) => [row.record_key, rowLabel(row)]))
  const options = rows
    .filter((row) => row.status !== 'deleted')
    .map((row) => ({ value: row.record_key, label: `${row.record_key} ${rowLabel(row)}` }))
  return { labels, options }
}

function rowLabel(row: EditRow): string {
  const name = row.data.name
  return typeof name === 'string' && name !== '' ? name : row.record_key
}

/** 本番の値から変わったセルかどうか（追加した行は、すべてのセルを変更扱いにしない。行の色で示す） */
export function isCellChanged(row: EditRow, columnKey: string): boolean {
  if (row.status !== 'updated' || row.original === null) {
    return false
  }
  return (row.original[columnKey] ?? null) !== (row.data[columnKey] ?? null)
}

export function countByStatus(rows: EditRow[]): { changed: number; errors: number } {
  return {
    changed: rows.filter((row) => row.status !== 'unchanged').length,
    errors: rows.filter((row) => Object.keys(row.errors).length > 0).length,
  }
}
