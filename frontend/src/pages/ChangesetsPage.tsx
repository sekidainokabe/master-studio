import { Link, useSearchParams } from 'react-router'
import { StatusBadge } from '../components/StatusBadge'
import { useMe } from '../lib/auth'
import { STATUS_LABELS, STATUSES, useChangesets, type ChangesetStatus } from '../lib/changesets'
import { formatDateTime } from '../lib/format'

/**
 * 変更セット一覧。絞り込み条件とページ番号は URL に持たせ、再読み込み・「戻る」・URL の共有で同じ一覧を開けるようにする。
 */
export function ChangesetsPage() {
  const { data: user } = useMe()
  const [searchParams, setSearchParams] = useSearchParams()

  const statusParam = searchParams.get('status')
  const status = STATUSES.includes(statusParam as ChangesetStatus) ? (statusParam as ChangesetStatus) : undefined
  const mine = searchParams.get('mine') === '1'
  const page = Math.max(1, Number(searchParams.get('page')) || 1)

  const changesets = useChangesets({ status, authorId: mine ? user?.id : undefined, page })

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

  const meta = changesets.data?.meta

  return (
    <>
      <div className="page-title">
        <h1>変更セット</h1>
        {user?.can.create_changeset && (
          <Link to="/changesets/new" className="button-link">
            新規作成
          </Link>
        )}
      </div>

      <div className="filters">
        <label className="filter-select">
          状態
          <select value={status ?? ''} onChange={(e) => updateParams({ status: e.target.value })}>
            <option value="">すべて</option>
            {STATUSES.map((s) => (
              <option key={s} value={s}>
                {STATUS_LABELS[s]}
              </option>
            ))}
          </select>
        </label>
        <label className="filter-select">
          <input type="checkbox" checked={mine} onChange={(e) => updateParams({ mine: e.target.checked ? '1' : null })} />
          自分が作成したもののみ
        </label>
        {meta && <span className="sub">{meta.total} 件</span>}
      </div>

      {changesets.isPending && <p>読み込み中…</p>}
      {changesets.isError && <p role="alert">変更セットの一覧を取得できませんでした。</p>}
      {changesets.isSuccess && (
        <>
          <table className="data-table">
            <thead>
              <tr>
                <th className="num">ID</th>
                <th>タイトル</th>
                <th>状態</th>
                <th>作成者</th>
                <th className="num">変更件数</th>
                <th>作成日時</th>
              </tr>
            </thead>
            <tbody>
              {changesets.data.data.map((changeset) => (
                <tr key={changeset.id}>
                  <td className="num">{changeset.id}</td>
                  <td>
                    <Link to={`/changesets/${changeset.id}`}>{changeset.title}</Link>
                  </td>
                  <td>
                    <StatusBadge status={changeset.status} />
                  </td>
                  <td>{changeset.author.name}</td>
                  <td className="num">{changeset.item_count}</td>
                  <td className="nowrap">{formatDateTime(changeset.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {changesets.data.data.length === 0 && <p className="sub">条件に合う変更セットはありません。</p>}
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
