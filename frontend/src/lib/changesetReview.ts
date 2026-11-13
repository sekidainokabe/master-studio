import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError, apiFetch } from './api'
import { changesetKeys, type Changeset } from './changesets'
import { masterKeys } from './masters'
import { releaseKeys } from './releases'

/** 変更セットの状態を変える操作（レビュー依頼・承認・却下・下書きに戻す） */
export type ReviewAction = 'submit' | 'approve' | 'reject' | 'reopen'

export type ReviewInput = { action: ReviewAction; comment?: string }

/** 承認のときに、作成後の本番の変更とぶつかった行（409） */
export type Conflict = { master: string; record_key: string; message: string }

export function useReviewAction(changesetId: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ action, comment }: ReviewInput) =>
      apiFetch<{ data: Changeset }>(`/api/changesets/${changesetId}/${action}`, {
        method: 'POST',
        body: comment === undefined ? {} : { comment },
      }).then((res) => res.data),
    onSuccess: (changeset, { action }) => {
      queryClient.setQueryData(changesetKeys.detail(changesetId), changeset)
      queryClient.invalidateQueries({ queryKey: [...changesetKeys.all, 'list'] })
      if (action === 'approve') {
        // 本番データが変わったため、本番の閲覧と、本番に変更を重ねている他の変更セットの表示も取り直す
        queryClient.invalidateQueries({ queryKey: masterKeys.all })
        queryClient.invalidateQueries({ queryKey: changesetKeys.all })
        // 新しいリリースが1つ増える
        queryClient.invalidateQueries({ queryKey: [...releaseKeys.all, 'list'] })
      } else {
        // 下書きに戻すと基準のリリースが変わり、差分や検証の結果が変わることがある
        queryClient.invalidateQueries({ queryKey: ['changesets', changesetId] })
      }
    },
  })
}

/**
 * レビューを依頼できない理由。依頼できるときは null。
 * 最終的にはサーバーが 422 で拒否するが、押す前に理由を見せて迷わせないようにする
 */
export function submitBlockReason({
  dirty,
  itemCount,
  errorCount,
}: {
  dirty: boolean
  itemCount: number
  /** 検証の結果を取得中のときは undefined */
  errorCount: number | undefined
}): string | null {
  if (dirty) {
    return '未保存の変更を保存すると、レビューを依頼できます。'
  }
  if (itemCount === 0) {
    return '変更が1件もないため、レビューを依頼できません。'
  }
  if (errorCount === undefined) {
    return '検証しています…'
  }
  if (errorCount > 0) {
    return `検証エラーが ${errorCount} 件あります。修正すると、レビューを依頼できます。`
  }
  return null
}

/** 操作が失敗したときに画面に出す内容。409 の競合では、ぶつかった行の一覧も返す */
export function reviewError(error: unknown): { message: string; conflicts: Conflict[] } {
  if (!(error instanceof ApiError)) {
    return { message: '通信に失敗しました。時間をおいてもう一度お試しください。', conflicts: [] }
  }
  const body = error.body as { message?: string; conflicts?: Conflict[]; errors?: Record<string, string[]> } | null
  if (error.status === 422) {
    const messages = body?.errors ? Object.values(body.errors).flat() : []
    return { message: messages.join(' ') || '入力内容を確認してください。', conflicts: [] }
  }
  return {
    message: body?.message || `操作できませんでした（HTTP ${error.status}）。画面を再読み込みしてください。`,
    conflicts: body?.conflicts ?? [],
  }
}
