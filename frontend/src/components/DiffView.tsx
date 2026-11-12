import { Link } from 'react-router'
import { useChangesetRefs } from '../lib/changesetRecords'
import { OPERATION_LABELS, summarizeCounts, useChangesetDiff, type MasterDiff } from '../lib/changesetDiff'
import { useMasterSchema, type CellValue, type MasterColumn, type RefLabels } from '../lib/masters'
import { formatCell } from '../lib/recordView'

/** 変更セットの差分（今の本番との比較）。追加 = 緑、削除 = 赤、変更した列 = 黄で「前 → 後」 */
export function DiffView({ changesetId }: { changesetId: number }) {
  const diff = useChangesetDiff(changesetId)

  if (diff.isError) {
    return <p role="alert">差分を取得できませんでした。</p>
  }
  if (diff.isPending) {
    return <p className="sub">読み込み中…</p>
  }
  if (diff.data.length === 0) {
    return <p className="sub">今の本番と比べて、変わるデータはありません。</p>
  }

  return (
    <>
      {diff.data.map((m) => (
        <MasterDiffTable key={m.master} changesetId={changesetId} diff={m} />
      ))}
    </>
  )
}

function MasterDiffTable({ changesetId, diff }: { changesetId: number; diff: MasterDiff }) {
  const schema = useMasterSchema(diff.master)
  // 参照列の表示名は、変更セットの中で追加した行の名前も出せるよう、編集用データから作る
  const refs = useChangesetRefs(changesetId, schema.data)
  const columns = new Map((schema.data ?? []).map((c) => [c.key, c]))

  return (
    <section className="diff-section">
      <h3>
        <Link to={`/changesets/${changesetId}/edit?master=${diff.master}`}>{diff.name}</Link>
        <span className="sub">（{summarizeCounts(diff.counts)}）</span>
      </h3>
      <div className="table-scroll">
        <table className="data-table diff-table">
          <thead>
            <tr>
              <th>状態</th>
              <th>record_key</th>
              <th>内容</th>
            </tr>
          </thead>
          <tbody>
            {diff.rows.map((row) => (
              <tr key={row.record_key} className={`row-${row.operation}`}>
                <td>{OPERATION_LABELS[row.operation]}</td>
                <td className="mono">{row.record_key}</td>
                <td>
                  <ul className="diff-changes">
                    {row.changes.map((change) => {
                      const column = columns.get(change.column)
                      const name = column?.name ?? change.column
                      return (
                        <li key={change.column}>
                          {name}：
                          {row.operation === 'updated' ? (
                            <span className="cell-changed">
                              <del>{show(change.before, column, refs.labels)}</del> →{' '}
                              <ins>{show(change.after, column, refs.labels)}</ins>
                            </span>
                          ) : (
                            show(row.operation === 'created' ? change.after : change.before, column, refs.labels)
                          )}
                        </li>
                      )
                    })}
                  </ul>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function show(value: CellValue, column: MasterColumn | undefined, refLabels: RefLabels): string {
  const text = column ? formatCell(value, column, refLabels) : String(value ?? '')
  return text === '' ? '（空）' : text
}
