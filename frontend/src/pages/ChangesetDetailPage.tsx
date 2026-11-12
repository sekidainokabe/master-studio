import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { ChangesetForm } from '../components/ChangesetForm'
import { DiffView } from '../components/DiffView'
import { StatusBadge } from '../components/StatusBadge'
import { useChangesetValidation } from '../lib/changesetValidation'
import { useChangeset, useDeleteChangeset, useUpdateChangeset, type Changeset } from '../lib/changesets'
import { formatDateTime } from '../lib/format'

/** 変更セットの詳細。基本情報、検証結果の件数、今の本番との差分。レビュー依頼・承認は第6週に追加する */
export function ChangesetDetailPage() {
  const { id } = useParams()
  const changeset = useChangeset(Number(id))

  if (changeset.isPending) {
    return <p>読み込み中…</p>
  }
  if (changeset.isError) {
    return <p role="alert">変更セットを取得できませんでした。存在しない変更セットの可能性があります。</p>
  }

  // key を付けて、別の変更セットに移ったときに編集中の状態を持ち越さないようにする
  return <ChangesetDetail key={changeset.data.id} changeset={changeset.data} />
}

function ChangesetDetail({ changeset: c }: { changeset: Changeset }) {
  const navigate = useNavigate()
  const [editing, setEditing] = useState(false)
  const update = useUpdateChangeset(c.id)
  const remove = useDeleteChangeset(c.id)

  const handleDelete = () => {
    if (window.confirm(`「${c.title}」を削除します。中の変更もすべて消えます。よろしいですか？`)) {
      remove.mutate(undefined, { onSuccess: () => navigate('/changesets', { replace: true }) })
    }
  }

  return (
    <>
      <p className="breadcrumb">
        <Link to="/changesets">変更セット</Link> ／
      </p>

      {editing ? (
        <>
          <h1>変更セットの編集</h1>
          <ChangesetForm
            initial={{ title: c.title, description: c.description ?? '' }}
            submitLabel="保存する"
            pendingLabel="保存中…"
            isPending={update.isPending}
            error={update.error}
            onSubmit={(input) => update.mutate(input, { onSuccess: () => setEditing(false) })}
            onCancel={() => {
              update.reset()
              setEditing(false)
            }}
          />
        </>
      ) : (
        <>
          <div className="page-title">
            <h1>
              #{c.id} {c.title}
            </h1>
            <StatusBadge status={c.status} />
          </div>

          <div className="button-row">
            {/* 編集できない人にも中身は見せる（承認者のレビューなど） */}
            <Link to={`/changesets/${c.id}/edit`} className="button-link">
              {c.can.update ? 'データを編集' : 'データを見る'}
            </Link>
            {c.can.update && (
              <button type="button" className="secondary" onClick={() => setEditing(true)}>
                タイトル・説明を編集
              </button>
            )}
            {c.can.delete && (
              <button type="button" className="danger" onClick={handleDelete} disabled={remove.isPending}>
                {remove.isPending ? '削除中…' : '削除'}
              </button>
            )}
          </div>
          {remove.isError && (
            <p className="error" role="alert">
              削除できませんでした。
            </p>
          )}

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

          <h2>検証結果</h2>
          <ValidationSummary changesetId={c.id} />

          <h2>差分（今の本番との比較）</h2>
          <DiffView changesetId={c.id} />

          <p className="sub">レビュー依頼と承認は、第6週に追加します。</p>
        </>
      )}
    </>
  )
}

/** 検証エラーの件数。一覧は編集画面のパネルで見る */
function ValidationSummary({ changesetId }: { changesetId: number }) {
  const validation = useChangesetValidation(changesetId)

  if (validation.isError) {
    return <p role="alert">検証結果を取得できませんでした。</p>
  }
  if (validation.isPending) {
    return <p className="sub">検証しています…</p>
  }

  const { counts } = validation.data
  if (counts.total === 0) {
    return <p>エラーはありません。</p>
  }
  return (
    <p className="error">
      エラーが {counts.total} 件あります（入力 {counts.cell} 件・マスタ間 {counts.cross} 件）。
      <Link to={`/changesets/${changesetId}/edit`}>編集画面で確認する</Link>
    </p>
  )
}
