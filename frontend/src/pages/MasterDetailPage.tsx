import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useMe } from '../lib/auth'
import { formatDateTime } from '../lib/format'
import { useMasterRecords, useMasters, useMasterSchema, useRefLabels, type MasterColumn } from '../lib/masters'
import { filterRecords, formatCell, type RecordFilter } from '../lib/recordView'

export function MasterDetailPage() {
  const { key = '' } = useParams()
  // マスタを切り替えたときに検索条件を持ち越さないよう、key ごとに作り直す
  return <MasterDetail key={key} masterKey={key} />
}

function MasterDetail({ masterKey }: { masterKey: string }) {
  const { data: user } = useMe()
  const masters = useMasters()
  const schema = useMasterSchema(masterKey)
  const records = useMasterRecords(masterKey)
  const refs = useRefLabels(schema.data)
  const [filter, setFilter] = useState<RecordFilter>({ keyword: '', enumValues: {} })

  const master = masters.data?.find((m) => m.key === masterKey)

  if (schema.isError || records.isError) {
    return <p role="alert">マスタを取得できませんでした。存在しないマスタの可能性があります。</p>
  }
  if (schema.isPending || records.isPending || refs.isPending) {
    return <p>読み込み中…</p>
  }

  const columns = schema.data
  const enumColumns = columns.filter((c) => c.type === 'enum')
  const visible = filterRecords(records.data, columns, refs.labels, filter)

  return (
    <>
      <p className="breadcrumb">
        <Link to="/masters">マスタ一覧</Link> ／
      </p>
      <div className="page-title">
        <h1>{master?.name ?? masterKey}</h1>
        {user?.can.create_changeset && (
          <Link to="/changesets/new" className="button-link">
            変更セットを作成
          </Link>
        )}
      </div>
      <p className="sub">本番データ（読み取り専用）。変更は変更セットを作成して行います。</p>

      <div className="filters">
        <input
          type="search"
          placeholder="キーワードで検索"
          aria-label="キーワードで検索"
          value={filter.keyword}
          onChange={(e) => setFilter({ ...filter, keyword: e.target.value })}
        />
        {enumColumns.map((column) => (
          <EnumFilter
            key={column.key}
            column={column}
            value={filter.enumValues[column.key] ?? ''}
            onChange={(value) => setFilter({ ...filter, enumValues: { ...filter.enumValues, [column.key]: value } })}
          />
        ))}
        <span className="sub">
          {records.data.length} 件中 {visible.length} 件
        </span>
      </div>

      <div className="table-scroll">
        <table className="data-table">
          <thead>
            <tr>
              <th>record_key</th>
              {columns.map((column) => (
                <th key={column.key} className={isNumeric(column) ? 'num' : undefined}>
                  {column.name}
                  <div className="sub">{column.key}</div>
                </th>
              ))}
              <th>最終更新</th>
            </tr>
          </thead>
          <tbody>
            {visible.map((record) => (
              <tr key={record.record_key}>
                <td className="mono">{record.record_key}</td>
                {columns.map((column) => (
                  <td key={column.key} className={isNumeric(column) ? 'num' : undefined}>
                    {formatCell(record.data[column.key] ?? null, column, refs.labels)}
                  </td>
                ))}
                <td className="nowrap">{formatDateTime(record.updated_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {visible.length === 0 && <p className="sub">条件に合うレコードはありません。</p>}
    </>
  )
}

function isNumeric(column: MasterColumn): boolean {
  return column.type === 'int' || column.type === 'float'
}

function EnumFilter({
  column,
  value,
  onChange,
}: {
  column: MasterColumn
  value: string
  onChange: (value: string) => void
}) {
  return (
    <label className="filter-select">
      {column.name}
      <select value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">すべて</option>
        {(column.enum_values ?? []).map((v) => (
          <option key={v} value={v}>
            {v}
          </option>
        ))}
      </select>
    </label>
  )
}
