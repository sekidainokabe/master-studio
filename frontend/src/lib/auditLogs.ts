import { useQuery } from '@tanstack/react-query'
import { apiFetch } from './api'
import type { UserRole } from './auth'
import type { Paginated } from './changesets'

export type AuditAction =
  | 'login'
  | 'changeset.create'
  | 'changeset.update'
  | 'changeset.delete'
  | 'records.save'
  | 'records.revert'
  | 'records.import_csv'
  | 'changeset.submit'
  | 'changeset.approve'
  | 'changeset.reject'
  | 'changeset.reopen'

/** 絞り込みの選択肢。表示名はサーバーの AuditAction::label() と同じ */
export const AUDIT_ACTION_LABELS: Record<AuditAction, string> = {
  login: 'ログイン',
  'changeset.create': '変更セットの作成',
  'changeset.update': '変更セットの編集',
  'changeset.delete': '変更セットの削除',
  'records.save': 'データの保存',
  'records.revert': '変更の取り消し',
  'records.import_csv': 'CSV の取り込み',
  'changeset.submit': 'レビュー依頼',
  'changeset.approve': '承認',
  'changeset.reject': '却下',
  'changeset.reopen': '下書きに戻す',
}

export const AUDIT_ACTIONS = Object.keys(AUDIT_ACTION_LABELS) as AuditAction[]

export type AuditLog = {
  id: number
  user: { id: number; name: string } | null
  action: AuditAction
  action_label: string
  /** title は変更セットのタイトル。削除済みや、ログインのように利用者が対象のときは null */
  target: { type: 'changeset' | 'user'; id: number; title: string | null }
  detail: Record<string, unknown> | null
  created_at: string
}

export type UserOption = { id: number; name: string; role: UserRole }

export type AuditLogParams = {
  userId?: number
  action?: AuditAction
  /** 画面で選んだ日付（YYYY-MM-DD）。その日を含む */
  from?: string
  to?: string
  page?: number
}

/**
 * 日付（YYYY-MM-DD）を、ブラウザのタイムゾーンでのその日の 0 時の日時にする。
 * サーバーは利用者のタイムゾーンを知らないため、「どの瞬間からどの瞬間まで」に直して送る
 */
export function startOfLocalDay(date: string, addDays = 0): string {
  const [y, m, d] = date.split('-').map(Number)
  return new Date(y, m - 1, d + addDays).toISOString()
}

/** 一覧 API のクエリ文字列。to はその日を含めたいので、翌日の 0 時（を含まない）にする */
export function auditLogQuery({ userId, action, from, to, page }: AuditLogParams): string {
  const params = new URLSearchParams()
  if (userId !== undefined) params.set('user_id', String(userId))
  if (action) params.set('action', action)
  if (from) params.set('from', startOfLocalDay(from))
  if (to) params.set('to', startOfLocalDay(to, 1))
  if (page !== undefined && page > 1) params.set('page', String(page))
  const query = params.toString()
  return query === '' ? '' : `?${query}`
}

const num = (value: unknown) => (typeof value === 'number' ? value : 0)
const text = (value: unknown) => (typeof value === 'string' ? value : '')

/** 操作ごとの補足（detail）を、一覧に出す短い文にする */
export function auditDetailText(log: Pick<AuditLog, 'action' | 'detail'>): string {
  const d = log.detail ?? {}
  switch (log.action) {
    case 'changeset.create':
      return `「${text(d.title)}」`
    case 'changeset.update':
      return `変更した項目：${Object.keys((d.after as Record<string, unknown> | undefined) ?? {}).join('・')}`
    case 'changeset.delete':
      return `「${text(d.title)}」（変更 ${num(d.item_count)} 件）`
    case 'records.save':
      return `${text(d.master)}：追加 ${num(d.created)}・更新 ${num(d.updated)}・削除 ${num(d.deleted)}・取り消し ${num(d.reverted)}`
    case 'records.revert':
      return `${text(d.master)} / ${text(d.record_key)}`
    case 'records.import_csv':
      return `${text(d.master)}（${text(d.filename)}）：追加 ${num(d.created)}・更新 ${num(d.updated)}・変化なし ${num(d.unchanged)}`
    case 'changeset.submit':
      return `変更 ${num(d.item_count)} 件`
    case 'changeset.approve':
      return `v${num(d.version)} を作成（変更 ${num(d.item_count)} 件）${d.comment ? `：${text(d.comment)}` : ''}`
    case 'changeset.reject':
      return `理由：${text(d.comment)}`
    case 'changeset.reopen':
      return `基準を v${num(d.base_release_version)} に付け直し`
    default:
      return ''
  }
}

export const auditLogKeys = {
  list: (params: AuditLogParams) => ['audit-logs', 'list', params] as const,
  users: ['users'] as const,
}

export function useAuditLogs(params: AuditLogParams) {
  return useQuery({
    queryKey: auditLogKeys.list(params),
    queryFn: () => apiFetch<Paginated<AuditLog>>(`/api/audit-logs${auditLogQuery(params)}`),
    placeholderData: (previous) => previous,
  })
}

export function useUsers() {
  return useQuery({
    queryKey: auditLogKeys.users,
    queryFn: () => apiFetch<{ data: UserOption[] }>('/api/users').then((res) => res.data),
    staleTime: 5 * 60 * 1000,
  })
}
