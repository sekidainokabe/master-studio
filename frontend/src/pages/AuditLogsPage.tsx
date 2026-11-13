import { Link, useSearchParams } from 'react-router'
import { ROLE_LABELS, useMe } from '../lib/auth'
import {
  AUDIT_ACTION_LABELS,
  AUDIT_ACTIONS,
  auditDetailText,
  useAuditLogs,
  useUsers,
  type AuditAction,
  type AuditLog,
} from '../lib/auditLogs'
import { formatDateTime } from '../lib/format'

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/

/** 操作履歴（approver・admin だけ）。絞り込み条件とページ番号は URL に持たせる */
export function AuditLogsPage() {
  const { data: me } = useMe()

  // ナビゲーションには出していないが、URL を直接開かれたときのため。最終的にはサーバーが 403 を返す
  if (me && !me.can.view_audit_logs) {
    return (
      <>
        <h1>操作履歴</h1>
        <p role="alert">操作履歴は、承認者と管理者だけが見られます。</p>
      </>
    )
  }
  return <AuditLogList />
}

function AuditLogList() {
  const [searchParams, setSearchParams] = useSearchParams()

  const userParam = Number(searchParams.get('user'))
  const userId = Number.isInteger(userParam) && userParam > 0 ? userParam : undefined
  const actionParam = searchParams.get('action')
  const action = AUDIT_ACTIONS.includes(actionParam as AuditAction) ? (actionParam as AuditAction) : undefined
  const fromParam = searchParams.get('from') ?? ''
  const toParam = searchParams.get('to') ?? ''
  const from = DATE_PATTERN.test(fromParam) ? fromParam : undefined
  const to = DATE_PATTERN.test(toParam) ? toParam : undefined
  const page = Math.max(1, Number(searchParams.get('page')) || 1)

  const users = useUsers()
  const logs = useAuditLogs({ userId, action, from, to, page })
  const meta = logs.data?.meta

  // 条件を変えたら1ページ目に戻す
  const updateParams = (changes: Record<string, string | null>) => {
    const next = new URLSearchParams(searchParams)
    for (const [key, value] of Object.entries(changes)) {
      if (value === null || value === '') next.delete(key)
      else next.set(key, value)
    }
    if (!('page' in changes)) next.delete('page')
    setSearchParams(next)
  }

  return (
    <>
      <h1>操作履歴</h1>

      <div className="filters">
        <label className="filter-select">
          利用者
          <select value={userId ?? ''} onChange={(e) => updateParams({ user: e.target.value })}>
            <option value="">すべて</option>
            {users.data?.map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}（{ROLE_LABELS[u.role]}）
              </option>
            ))}
          </select>
        </label>
        <label className="filter-select">
          操作
          <select value={action ?? ''} onChange={(e) => updateParams({ action: e.target.value })}>
            <option value="">すべて</option>
            {AUDIT_ACTIONS.map((a) => (
              <option key={a} value={a}>
                {AUDIT_ACTION_LABELS[a]}
              </option>
            ))}
          </select>
        </label>
        <label className="filter-select">
          期間
          <input type="date" value={from ?? ''} max={to} onChange={(e) => updateParams({ from: e.target.value })} />
        </label>
        <label className="filter-select">
          〜
          <input type="date" value={to ?? ''} min={from} onChange={(e) => updateParams({ to: e.target.value })} />
        </label>
        {meta && <span className="sub">{meta.total} 件</span>}
      </div>

      {logs.isPending && <p>読み込み中…</p>}
      {logs.isError && <p role="alert">操作履歴を取得できませんでした。</p>}
      {logs.isSuccess && (
        <>
          <div className="table-scroll">
            <table className="data-table">
              <thead>
                <tr>
                  <th>日時</th>
                  <th>利用者</th>
                  <th>操作</th>
                  <th>対象</th>
                  <th>内容</th>
                </tr>
              </thead>
              <tbody>
                {logs.data.data.map((log) => (
                  <tr key={log.id}>
                    <td className="nowrap">{formatDateTime(log.created_at)}</td>
                    <td className="nowrap">{log.user?.name ?? '（削除された利用者）'}</td>
                    <td className="nowrap">{log.action_label}</td>
                    <td>
                      <Target log={log} />
                    </td>
                    <td>{auditDetailText(log)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {logs.data.data.length === 0 && <p className="sub">条件に合う操作はありません。</p>}
        </>
      )}

      {meta && meta.last_page > 1 && (
        <nav className="pagination" aria-label="ページ送り">
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => updateParams({ page: page - 1 > 1 ? String(page - 1) : null })}
          >
            前へ
          </button>
          <span>
            {meta.current_page} / {meta.last_page} ページ
          </span>
          <button
            type="button"
            disabled={page >= meta.last_page}
            onClick={() => updateParams({ page: String(page + 1) })}
          >
            次へ
          </button>
        </nav>
      )}
    </>
  )
}

function Target({ log }: { log: AuditLog }) {
  if (log.target.type !== 'changeset') {
    return <span className="sub">—</span>
  }
  if (log.target.title === null) {
    return <span className="sub">#{log.target.id}（削除済み）</span>
  }
  return (
    <Link to={`/changesets/${log.target.id}`}>
      #{log.target.id} {log.target.title}
    </Link>
  )
}
