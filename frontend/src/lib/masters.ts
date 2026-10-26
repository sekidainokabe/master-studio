import { useQueries, useQuery } from '@tanstack/react-query'
import { apiFetch } from './api'

export type MasterSummary = {
  key: string
  name: string
  description: string | null
  record_count: number
  last_updated_at: string | null
}

export type ColumnType = 'int' | 'float' | 'string' | 'bool' | 'enum' | 'ref'

export type MasterColumn = {
  key: string
  name: string
  type: ColumnType
  is_required: boolean
  min_value: number | null
  max_value: number | null
  max_length: number | null
  enum_values: string[] | null
  ref_master_key: string | null
}

export type CellValue = string | number | boolean | null

export type MasterRecord = {
  record_key: string
  data: Record<string, CellValue>
  updated_at: string | null
  updated_by: { id: number; name: string } | null
}

/** 参照先マスタごとの「record_key → 表示名」の対応表 */
export type RefLabels = Record<string, Map<string, string>>

export const masterKeys = {
  all: ['masters'] as const,
  schema: (key: string) => ['masters', key, 'schema'] as const,
  records: (key: string) => ['masters', key, 'records'] as const,
}

export function useMasters() {
  return useQuery({
    queryKey: masterKeys.all,
    queryFn: () => apiFetch<{ data: MasterSummary[] }>('/api/masters').then((res) => res.data),
  })
}

export function useMasterSchema(key: string) {
  return useQuery({
    queryKey: masterKeys.schema(key),
    queryFn: () => apiFetch<{ data: MasterColumn[] }>(`/api/masters/${key}/schema`).then((res) => res.data),
  })
}

function fetchRecords(key: string) {
  return apiFetch<{ data: MasterRecord[] }>(`/api/masters/${key}/records`).then((res) => res.data)
}

export function useMasterRecords(key: string) {
  return useQuery({ queryKey: masterKeys.records(key), queryFn: () => fetchRecords(key) })
}

/**
 * 参照列の表示名を作るため、参照先マスタの本番データをまとめて取得する。
 * 同じマスタ（stages の自己参照など）はキャッシュが共有されるので、二重には取得しない。
 */
export function useRefLabels(columns: MasterColumn[] | undefined): { labels: RefLabels; isPending: boolean } {
  const refKeys = [...new Set((columns ?? []).flatMap((c) => (c.ref_master_key ? [c.ref_master_key] : [])))]

  return useQueries({
    queries: refKeys.map((key) => ({ queryKey: masterKeys.records(key), queryFn: () => fetchRecords(key) })),
    combine: (results) => ({
      isPending: results.some((r) => r.isPending),
      labels: Object.fromEntries(
        refKeys.map((key, i) => [key, new Map((results[i].data ?? []).map((r) => [r.record_key, recordLabel(r)]))]),
      ),
    }),
  })
}

/** レコードの表示名。name 列があればそれを、なければ record_key を使う */
export function recordLabel(record: MasterRecord): string {
  const name = record.data.name
  return typeof name === 'string' && name !== '' ? name : record.record_key
}
