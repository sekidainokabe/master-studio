import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiFetch } from './api'

export type ChangesetStatus = 'draft' | 'in_review' | 'rejected' | 'applied'

export const STATUS_LABELS: Record<ChangesetStatus, string> = {
  draft: '下書き',
  in_review: 'レビュー待ち',
  rejected: '却下',
  applied: '反映済み',
}

export const STATUSES = Object.keys(STATUS_LABELS) as ChangesetStatus[]

type UserRef = { id: number; name: string }

export type Changeset = {
  id: number
  title: string
  description: string | null
  status: ChangesetStatus
  author: UserRef
  /** 詳細 API でのみ返る */
  reviewer?: UserRef | null
  base_release_version?: number | null
  item_count: number
  review_comment: string | null
  submitted_at: string | null
  reviewed_at: string | null
  created_at: string
  updated_at: string
  /** ログイン中のユーザーができる操作（ボタンの表示に使う。最終的にはサーバーが拒否する） */
  can: { update: boolean; delete: boolean }
}

/** Laravel の paginate() が返す形のうち、画面で使う部分 */
export type Paginated<T> = {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

export type ChangesetListParams = {
  status?: ChangesetStatus
  authorId?: number
  page?: number
}

/** 一覧 API のクエリ文字列。未指定の条件は付けない */
export function changesetListQuery({ status, authorId, page }: ChangesetListParams): string {
  const params = new URLSearchParams()
  if (status) params.set('status', status)
  if (authorId !== undefined) params.set('author_id', String(authorId))
  if (page !== undefined && page > 1) params.set('page', String(page))
  const query = params.toString()
  return query === '' ? '' : `?${query}`
}

export const changesetKeys = {
  all: ['changesets'] as const,
  list: (params: ChangesetListParams) => ['changesets', 'list', params] as const,
  detail: (id: number) => ['changesets', 'detail', id] as const,
}

export function useChangesets(params: ChangesetListParams) {
  return useQuery({
    queryKey: changesetKeys.list(params),
    queryFn: () => apiFetch<Paginated<Changeset>>(`/api/changesets${changesetListQuery(params)}`),
    // ページを切り替えている間も前のページを表示したままにし、表がちらつかないようにする
    placeholderData: (previous) => previous,
  })
}

export function useChangeset(id: number) {
  return useQuery({
    queryKey: changesetKeys.detail(id),
    queryFn: () => apiFetch<{ data: Changeset }>(`/api/changesets/${id}`).then((res) => res.data),
  })
}

export type ChangesetInput = {
  title: string
  description: string
}

export function useCreateChangeset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ChangesetInput) =>
      apiFetch<{ data: Changeset }>('/api/changesets', { method: 'POST', body: input }).then((res) => res.data),
    onSuccess: (changeset) => {
      // 一覧は古くなるので取り直させ、詳細は API の結果をそのままキャッシュに入れる
      queryClient.invalidateQueries({ queryKey: [...changesetKeys.all, 'list'] })
      queryClient.setQueryData(changesetKeys.detail(changeset.id), changeset)
    },
  })
}

export function useUpdateChangeset(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ChangesetInput) =>
      apiFetch<{ data: Changeset }>(`/api/changesets/${id}`, { method: 'PATCH', body: input }).then((res) => res.data),
    onSuccess: (changeset) => {
      queryClient.invalidateQueries({ queryKey: [...changesetKeys.all, 'list'] })
      queryClient.setQueryData(changesetKeys.detail(changeset.id), changeset)
    },
  })
}

export function useDeleteChangeset(id: number) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => apiFetch<void>(`/api/changesets/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [...changesetKeys.all, 'list'] })
      // 消えた変更セットの詳細を、キャッシュから表示しないようにする
      queryClient.removeQueries({ queryKey: changesetKeys.detail(id) })
    },
  })
}
