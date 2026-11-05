import { Link, useNavigate } from 'react-router'
import { ChangesetForm } from '../components/ChangesetForm'
import { useMe } from '../lib/auth'
import { useCreateChangeset } from '../lib/changesets'

export function ChangesetNewPage() {
  const navigate = useNavigate()
  const { data: user } = useMe()
  const create = useCreateChangeset()

  // ボタンを出していなくても URL を直接開けるため、画面でも案内する（最終的にはサーバーが 403 で拒否する）
  if (!user?.can.create_changeset) {
    return (
      <>
        <h1>変更セットの作成</h1>
        <p role="alert">変更セットを作成する権限がありません。作成できるのは編集者と管理者です。</p>
        <Link to="/changesets">変更セット一覧へ戻る</Link>
      </>
    )
  }

  return (
    <>
      <p className="breadcrumb">
        <Link to="/changesets">変更セット</Link> ／
      </p>
      <h1>変更セットの作成</h1>
      <p className="sub">変更の目的をまとめて入力します。データの編集は、作成後の編集画面で行います。</p>

      <ChangesetForm
        submitLabel="作成する"
        pendingLabel="作成中…"
        isPending={create.isPending}
        error={create.error}
        onSubmit={(input) =>
          create.mutate(input, { onSuccess: (changeset) => navigate(`/changesets/${changeset.id}`) })
        }
      />
    </>
  )
}
