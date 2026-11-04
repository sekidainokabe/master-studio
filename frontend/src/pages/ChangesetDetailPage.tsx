import { Link, useParams } from 'react-router'
import { StatusBadge } from '../components/StatusBadge'
import { useChangeset } from '../lib/changesets'
import { formatDateTime } from '../lib/format'

/** 変更セットの詳細。今は基本情報のみで、差分・検証結果・承認などは第5〜6週に追加する */
export function ChangesetDetailPage() {
  const { id } = useParams()
  const changeset = useChangeset(Number(id))

  if (changeset.isPending) {
    return <p>読み込み中…</p>
  }
  if (changeset.isError) {
    return <p role="alert">変更セットを取得できませんでした。存在しない変更セットの可能性があります。</p>
  }

  const c = changeset.data

  return (
    <>
      <p className="breadcrumb">
        <Link to="/changesets">変更セット</Link> ／
      </p>
      <div className="page-title">
        <h1>
          #{c.id} {c.title}
        </h1>
        <StatusBadge status={c.status} />
      </div>

      <dl className="detail-list">
        <dt>作成者</dt>
        <dd>{c.author.name}</dd>
        <dt>作成日時</dt>
        <dd>{formatDateTime(c.created_at)}</dd>
        <dt>元にしたリリース</dt>
        <dd>{c.base_release_version ? `version ${c.base_release_version}` : 'なし'}</dd>
        <dt>変更件数</dt>
        <dd>{c.item_count} 件</dd>
        <dt>承認者</dt>
        <dd>{c.reviewer?.name ?? '未定'}</dd>
      </dl>

      <h2>説明</h2>
      <p className="description">{c.description || '（なし）'}</p>

      <p className="sub">データの編集・差分の確認・レビュー依頼は、今後追加する画面で行えるようになります。</p>
    </>
  )
}
