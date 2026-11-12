import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './api'
import type { CellValue } from './masters'

export type DiffOperation = 'created' | 'updated' | 'deleted'

export type DiffCounts = { created: number; updated: number; deleted: number }

/** 1つのマスタの差分。変更のある行と、その中で値が変わる列だけを持つ */
export type MasterDiff = {
  master: string
  name: string
  counts: DiffCounts
  rows: {
    record_key: string
    operation: DiffOperation
    changes: { column: string; before: CellValue; after: CellValue }[]
  }[]
}

export const changesetDiffKey = (changesetId: number) => ['changesets', changesetId, 'diff'] as const

/** 今の本番と比べた差分（承認したら本番がどう変わるか） */
export function useChangesetDiff(changesetId: number) {
  return useQuery({
    queryKey: changesetDiffKey(changesetId),
    queryFn: () => apiFetch<{ data: MasterDiff[] }>(`/api/changesets/${changesetId}/diff`).then((res) => res.data),
  })
}

export const OPERATION_LABELS: Record<DiffOperation, string> = {
  created: '追加',
  updated: '変更',
  deleted: '削除',
}

/** 「追加 1・変更 2」のような件数の要約。0 件の操作は省く */
export function summarizeCounts(counts: DiffCounts): string {
  return (Object.keys(OPERATION_LABELS) as DiffOperation[])
    .filter((op) => counts[op] > 0)
    .map((op) => `${OPERATION_LABELS[op]} ${counts[op]}`)
    .join('・')
}
