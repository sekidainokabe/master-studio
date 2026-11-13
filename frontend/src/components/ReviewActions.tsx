import { useId, useState } from 'react'
import { useNavigate } from 'react-router'
import { reviewError, submitBlockReason, useReviewAction } from '../lib/changesetReview'
import { useChangesetValidation } from '../lib/changesetValidation'
import type { Changeset } from '../lib/changesets'

/**
 * 「レビューを依頼」ボタン。依頼できないときは押せなくし、理由をボタンの横に出す。
 * 編集画面では未保存の編集の有無（dirty）を受け取る。詳細画面では常に false
 */
export function SubmitReviewButton({ changeset, dirty }: { changeset: Changeset; dirty: boolean }) {
  const navigate = useNavigate()
  const validation = useChangesetValidation(changeset.id)
  const review = useReviewAction(changeset.id)
  const reasonId = useId()

  const reason = submitBlockReason({
    dirty,
    itemCount: changeset.item_count,
    errorCount: validation.data?.counts.total,
  })

  const handleSubmit = () => {
    review.mutate(
      { action: 'submit' },
      // 依頼したあとは編集できないので、承認者が見るのと同じ詳細画面に移る
      { onSuccess: () => navigate(`/changesets/${changeset.id}`) },
    )
  }

  return (
    <div className="review-actions">
      <button
        type="button"
        onClick={handleSubmit}
        disabled={reason !== null || review.isPending}
        aria-describedby={reason ? reasonId : undefined}
      >
        {review.isPending ? '依頼中…' : 'レビューを依頼'}
      </button>
      {reason && (
        <span id={reasonId} className="sub">
          {reason}
        </span>
      )}
      <ReviewErrorMessage error={review.error} />
    </div>
  )
}

/** 詳細画面の、状態に応じた操作（承認・却下、下書きに戻す） */
export function ReviewPanel({ changeset: c }: { changeset: Changeset }) {
  const review = useReviewAction(c.id)
  const validation = useChangesetValidation(c.id)
  const [comment, setComment] = useState('')
  const commentId = useId()

  if (!c.can.approve && !c.can.reject && !c.can.reopen && !c.can.submit) {
    return null
  }

  const errorCount = validation.data?.counts.total
  const handleApprove = () => {
    if (window.confirm('本番データに反映し、新しいリリースを作ります。よろしいですか？')) {
      review.mutate({ action: 'approve', comment: comment.trim() === '' ? undefined : comment })
    }
  }

  return (
    <section className="review-panel" aria-label="レビューの操作">
      {c.can.submit && <SubmitReviewButton changeset={c} dirty={false} />}

      {(c.can.approve || c.can.reject) && (
        <>
          <label htmlFor={commentId}>コメント（却下するときは理由を必ず書いてください）</label>
          <textarea id={commentId} rows={3} maxLength={1000} value={comment} onChange={(e) => setComment(e.target.value)} />
          <div className="button-row">
            {c.can.approve && (
              <button
                type="button"
                onClick={handleApprove}
                // エラーがあればサーバーも 422 で止めるが、押す前に分かるようにする
                disabled={review.isPending || errorCount !== 0}
              >
                {review.isPending ? '処理中…' : '承認して本番に反映'}
              </button>
            )}
            {c.can.reject && (
              <button
                type="button"
                className="danger"
                onClick={() => review.mutate({ action: 'reject', comment })}
                disabled={review.isPending || comment.trim() === ''}
              >
                却下
              </button>
            )}
          </div>
          {errorCount !== undefined && errorCount > 0 && (
            <p className="sub">検証エラーがあるため承認できません。却下して、作成者に修正を依頼してください。</p>
          )}
        </>
      )}

      {c.can.reopen && (
        <div className="review-actions">
          <button type="button" onClick={() => review.mutate({ action: 'reopen' })} disabled={review.isPending}>
            下書きに戻して修正する
          </button>
          <span className="sub">基準のリリースが最新に付け直されます。差分を見直してから、もう一度依頼してください。</span>
        </div>
      )}

      <ReviewErrorMessage error={review.error} />
    </section>
  )
}

function ReviewErrorMessage({ error }: { error: unknown }) {
  if (!error) {
    return null
  }
  const { message, conflicts } = reviewError(error)
  return (
    <div className="error review-error" role="alert">
      <p>{message}</p>
      {conflicts.length > 0 && (
        <ul>
          {conflicts.map((c) => (
            <li key={`${c.master}:${c.record_key}`}>
              <span className="mono">
                {c.master} / {c.record_key}
              </span>
              ：{c.message}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
