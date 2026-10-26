import { Link } from 'react-router'
import { formatDateTime } from '../lib/format'
import { useMasters } from '../lib/masters'

export function MastersPage() {
  const masters = useMasters()

  return (
    <>
      <h1>マスタ一覧</h1>
      {masters.isPending && <p>読み込み中…</p>}
      {masters.isError && <p role="alert">マスタの一覧を取得できませんでした。</p>}
      {masters.isSuccess && (
        <table className="data-table">
          <thead>
            <tr>
              <th>マスタ</th>
              <th>説明</th>
              <th className="num">件数</th>
              <th>最終更新</th>
            </tr>
          </thead>
          <tbody>
            {masters.data.map((master) => (
              <tr key={master.key}>
                <td>
                  <Link to={`/masters/${master.key}`}>{master.name}</Link>
                  <div className="sub">{master.key}</div>
                </td>
                <td>{master.description}</td>
                <td className="num">{master.record_count}</td>
                <td>{formatDateTime(master.last_updated_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  )
}
