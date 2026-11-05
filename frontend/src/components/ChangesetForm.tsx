import { useState, type FormEvent } from 'react'
import { ApiError } from '../lib/api'
import type { ChangesetInput } from '../lib/changesets'

type Props = {
  initial?: ChangesetInput
  submitLabel: string
  pendingLabel: string
  isPending: boolean
  error: unknown
  onSubmit: (input: ChangesetInput) => void
  onCancel?: () => void
}

/** 変更セットのタイトル・説明の入力フォーム（作成と編集で共通） */
export function ChangesetForm({ initial, submitLabel, pendingLabel, isPending, error, onSubmit, onCancel }: Props) {
  const [title, setTitle] = useState(initial?.title ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    onSubmit({ title, description })
  }

  const apiError = error instanceof ApiError ? error : null
  const titleError = apiError?.fieldError('title')
  const descriptionError = apiError?.fieldError('description')

  return (
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
      {error != null && !titleError && !descriptionError && (
        <p className="error" role="alert">
          {errorMessage(apiError)}
        </p>
      )}
      <div className="button-row">
        <button type="submit" disabled={isPending}>
          {isPending ? pendingLabel : submitLabel}
        </button>
        {onCancel && (
          <button type="button" className="secondary" onClick={onCancel} disabled={isPending}>
            キャンセル
          </button>
        )}
      </div>
    </form>
  )
}

function errorMessage(error: ApiError | null): string {
  switch (error?.status) {
    case 403:
      return '権限がありません。'
    case 409:
      return '下書きの変更セットだけ変更できます。'
    default:
      return '保存できませんでした。時間をおいてもう一度お試しください。'
  }
}
