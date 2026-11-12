import { useChangesetValidation, type ValidationError } from '../lib/changesetValidation'
import { useMasterSchema, type MasterSummary } from '../lib/masters'

export type JumpTarget = { master: string; recordKey: string; column: string | null }

/**
 * 変更セット全体の検証結果（保存済みの内容）。エラーを押すと、そのマスタのタブに移って行まで移動する
 */
export function ValidationPanel({
  changesetId,
  masters,
  onJump,
}: {
  changesetId: number
  masters: MasterSummary[]
  onJump: (target: JumpTarget) => void
}) {
  const validation = useChangesetValidation(changesetId)

  if (validation.isError) {
    return (
      <section className="validation-panel">
        <p role="alert">検証結果を取得できませんでした。</p>
      </section>
    )
  }
  if (validation.isPending) {
    return (
      <section className="validation-panel">
        <p className="sub">検証しています…</p>
      </section>
    )
  }

  const { errors, counts } = validation.data

  return (
    <section className="validation-panel" aria-labelledby="validation-title">
      <h2 id="validation-title" aria-live="polite">
        {counts.total === 0
          ? '検証結果：エラーはありません'
          : `検証結果：エラー ${counts.total} 件（入力 ${counts.cell} 件・マスタ間 ${counts.cross} 件）`}
        {validation.isFetching && <span className="sub">（更新中…）</span>}
      </h2>
      <p className="sub">保存した内容を、すべてのマスタについて検証した結果です。未保存の変更は、保存したあとに反映されます。</p>
      {counts.total > 0 && (
        <div className="validation-list">
          {masters
            .filter((m) => errors.some((e) => e.master === m.key))
            .map((m) => (
              <ValidationGroup
                key={m.key}
                master={m}
                errors={errors.filter((e) => e.master === m.key)}
                onJump={onJump}
              />
            ))}
        </div>
      )}
    </section>
  )
}

function ValidationGroup({
  master,
  errors,
  onJump,
}: {
  master: MasterSummary
  errors: ValidationError[]
  onJump: (target: JumpTarget) => void
}) {
  // 列名を出すためにスキーマを使う（編集画面と同じキャッシュを使うので、追加の取得はほとんど起きない）
  const schema = useMasterSchema(master.key)
  const columnName = (key: string | null) =>
    key === null ? '行' : (schema.data?.find((c) => c.key === key)?.name ?? key)

  return (
    <>
      <h3>
        {master.name}（{errors.length} 件）
      </h3>
      <ul>
        {errors.map((e, i) => (
          <li key={i}>
            <button
              type="button"
              className="link"
              onClick={() => onJump({ master: e.master, recordKey: e.record_key, column: e.column })}
            >
              <span className="mono">{e.record_key}</span> {columnName(e.column)}
            </button>
            ：{e.message}
            {e.kind === 'cross' && <span className="badge-cross">マスタ間</span>}
          </li>
        ))}
      </ul>
    </>
  )
}
