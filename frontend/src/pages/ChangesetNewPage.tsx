import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { ApiError } from '../lib/api'
import { useMe } from '../lib/auth'
import { useCreateChangeset } from '../lib/changesets'

export function ChangesetNewPage() {
  const navigate = useNavigate()
  const { data: user } = useMe()
  const create = useCreateChangeset()
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')

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

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    create.mutate({ title, description }, { onSuccess: (changeset) => navigate(`/changesets/${changeset.id}`) })
  }

  const error = create.error instanceof ApiError ? create.error : null
  const titleError = error?.fieldError('title')
  const descriptionError = error?.fieldError('description')

  return (
    <>
      <p className="breadcrumb">
        <Link to="/changesets">変更セット</Link> ／
      </p>
      <h1>変更セットの作成</h1>
      <p className="sub">変更の目的をまとめて入力します。データの編集は、作成後の編集画面で行います。</p>

      <form onSubmit={handleSubmit} className="stack-form">
        <label>
          タイトル（必須、200文字以内）
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            maxLength={200}
            required
            aria-invalid={titleError ? true : undefined}
          />
          {titleError && <span className="error">{titleError}</span>}
        </label>
        <label>
          説明（変更の目的など）
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={5}
            aria-invalid={descriptionError ? true : undefined}
          />
          {descriptionError && <span className="error">{descriptionError}</span>}
        </label>
        {create.isError && !titleError && !descriptionError && (
          <p className="error" role="alert">
            {error?.status === 403 ? '変更セットを作成する権限がありません。' : '作成できませんでした。時間をおいてもう一度お試しください。'}
          </p>
        )}
        <div>
          <button type="submit" disabled={create.isPending}>
            {create.isPending ? '作成中…' : '作成する'}
          </button>
        </div>
      </form>
    </>
  )
}
