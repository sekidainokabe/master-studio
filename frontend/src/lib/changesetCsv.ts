import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiFetch } from './api'
import { changesetKeys } from './changesets'

export type ImportCounts = { created: number; updated: number; unchanged: number }

export function exportCsvUrl(changesetId: number, masterKey: string): string {
  return `/api/changesets/${changesetId}/masters/${masterKey}/export`
}

/**
 * CSV を取り込む。サーバーは保存まで行うので、成功したら編集用データ・検証結果・差分をまとめて取り直す
 */
export function useImportCsv(changesetId: number, masterKey: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (file: File) => {
      const form = new FormData()
      form.append('file', file)
      return apiFetch<{ data: ImportCounts }>(`/api/changesets/${changesetId}/masters/${masterKey}/import`, {
        method: 'POST',
        body: form,
      }).then((res) => res.data)
    },
    onSuccess: () => {
      // ['changesets', id, ...] で始まるもの（編集用データ、検証結果、差分）をすべて取り直す
      queryClient.invalidateQueries({ queryKey: ['changesets', changesetId] })
      queryClient.invalidateQueries({ queryKey: changesetKeys.detail(changesetId) })
      queryClient.invalidateQueries({ queryKey: [...changesetKeys.all, 'list'] })
    },
  })
}

/** 「追加 1 件・更新 2 件・変化なし 3 件」 */
export function importSummary(counts: ImportCounts): string {
  return `追加 ${counts.created} 件・更新 ${counts.updated} 件・変化なし ${counts.unchanged} 件`
}

/** 取り込めなかった理由。422 はサーバーが返した理由（何行目のどこが悪いか）をすべて並べる */
export function importErrorMessages(error: unknown): string[] {
  if (error instanceof ApiError) {
    switch (error.status) {
      case 403:
        return ['取り込む権限がありません。']
      case 409:
        return ['下書きではなくなったため、取り込めませんでした。画面を再読み込みしてください。']
      case 422: {
        const errors = (error.body as { errors?: Record<string, string[]> } | null)?.errors
        const messages = errors ? Object.values(errors).flat() : []
        return messages.length > 0 ? messages : ['取り込めない CSV です。']
      }
    }
  }
  return ['取り込めませんでした。時間をおいてもう一度お試しください。']
}
