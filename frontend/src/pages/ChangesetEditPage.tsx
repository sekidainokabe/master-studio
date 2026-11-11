import { useMemo } from 'react'
import { createColumnHelper, tableFeatures, useTable } from '@tanstack/react-table'
import { Link, useParams, useSearchParams } from 'react-router'
import { StatusBadge } from '../components/StatusBadge'
import { useChangeset } from '../lib/changesets'
import {
  countByStatus,
  isCellChanged,
  useChangesetRecords,
  useChangesetRefs,
  type EditRow,
  type RowStatus,
} from '../lib/changesetRecords'
import { useMasters, useMasterSchema, type MasterColumn, type RefLabels } from '../lib/masters'
import { formatCell } from '../lib/recordView'

/**
 * 変更セットの編集画面。マスタごとのタブで、本番データに変更を重ねた表を表示する。
 * 選んでいるタブは URL（?master=）に持たせ、再読み込みやリンクの共有でも同じタブを開けるようにする
 */
export function ChangesetEditPage() {
  const { id } = useParams()
  const changesetId = Number(id)
  const changeset = useChangeset(changesetId)
  const masters = useMasters()
  const [searchParams] = useSearchParams()

  if (changeset.isError || masters.isError) {
    return <p role="alert">変更セットを取得できませんでした。存在しない変更セットの可能性があります。</p>
  }
  if (changeset.isPending || masters.isPending) {
    return <p>読み込み中…</p>
  }

  const c = changeset.data
  const masterKey = searchParams.get('master') ?? masters.data[0]?.key ?? ''
  const master = masters.data.find((m) => m.key === masterKey)

  return (
    <>
      <p className="breadcrumb">
        <Link to="/changesets">変更セット</Link> ／ <Link to={`/changesets/${c.id}`}>#{c.id}</Link> ／
      </p>
      <div className="page-title">
        <h1>
          #{c.id} {c.title}
        </h1>
        <StatusBadge status={c.status} />
      </div>
      {!c.can.update && (
        <p className="notice">
          {c.status === 'draft'
            ? 'この変更セットは閲覧のみです。編集できるのは作成者と管理者です。'
            : '下書きではないため、閲覧のみです。'}
        </p>
      )}

      <nav className="tabs" aria-label="マスタ">
        {masters.data.map((m) => (
          <Link
            key={m.key}
            to={`?master=${m.key}`}
            className={m.key === masterKey ? 'tab active' : 'tab'}
            aria-current={m.key === masterKey ? 'page' : undefined}
          >
            {m.name}
          </Link>
        ))}
      </nav>

      {master ? (
        // マスタを切り替えたら表を作り直し、前のマスタの状態を持ち越さない
        <EditGrid key={master.key} changesetId={c.id} masterKey={master.key} />
      ) : (
        <p role="alert">指定されたマスタはありません。上のタブから選んでください。</p>
      )}
    </>
  )
}

// 列定義やデータの参照が描画のたびに変わると、表の計算がやり直しになるため、機能の定義はモジュールに1つだけ置く
const features = tableFeatures({})
const helper = createColumnHelper<typeof features, EditRow>()

const STATUS_TEXT: Record<RowStatus, string> = {
  unchanged: '',
  created: '追加',
  updated: '変更',
  deleted: '削除',
}

function EditGrid({ changesetId, masterKey }: { changesetId: number; masterKey: string }) {
  const schema = useMasterSchema(masterKey)
  const rows = useChangesetRecords(changesetId, masterKey)
  const refs = useChangesetRefs(changesetId, schema.data)

  const columns = useMemo(() => buildColumns(schema.data ?? [], refs.labels), [schema.data, refs.labels])
  const data = useMemo(() => rows.data ?? [], [rows.data])
  const table = useTable({ features, columns, data, getRowId: (row) => row.record_key })

  if (schema.isError || rows.isError) {
    return <p role="alert">データを取得できませんでした。</p>
  }
  if (schema.isPending || rows.isPending || refs.isPending) {
    return <p>読み込み中…</p>
  }

  const counts = countByStatus(rows.data)

  return (
    <>
      <p className="sub">
        {rows.data.length} 件／変更 {counts.changed} 件／エラー {counts.errors} 件
      </p>
      <div className="table-scroll">
        <table className="data-table edit-table">
          <thead>
            {table.getHeaderGroups().map((group) => (
              <tr key={group.id}>
                {group.headers.map((header) => {
                  const column = schema.data.find((c) => c.key === header.column.id)
                  return (
                    <th key={header.id} className={column && isNumeric(column) ? 'num' : undefined}>
                      <table.FlexRender header={header} />
                    </th>
                  )
                })}
              </tr>
            ))}
          </thead>
          <tbody>
            {table.getRowModel().rows.map((row) => (
              <tr key={row.id} className={`row-${row.original.status}`}>
                {row.getAllCells().map((cell) => {
                  const column = schema.data.find((c) => c.key === cell.column.id)
                  const error = column ? row.original.errors[column.key] : undefined
                  const classes = [
                    column && isNumeric(column) ? 'num' : '',
                    column && isCellChanged(row.original, column.key) ? 'cell-changed' : '',
                    error ? 'cell-error' : '',
                  ].filter(Boolean)
                  return (
                    <td key={cell.id} className={classes.join(' ') || undefined} title={error}>
                      <table.FlexRender cell={cell} />
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {rows.data.length === 0 && <p className="sub">このマスタにはまだデータがありません。</p>}
    </>
  )
}

function buildColumns(columns: MasterColumn[], refLabels: RefLabels) {
  return helper.columns([
    // 色だけに頼らず、行の状態を文字でも示す
    helper.accessor('status', {
      header: '状態',
      cell: (info) => STATUS_TEXT[info.getValue()],
    }),
    helper.accessor('record_key', {
      header: 'record_key',
      cell: (info) => <span className="mono">{info.getValue()}</span>,
    }),
    ...columns.map((column) =>
      helper.accessor((row) => row.data[column.key] ?? null, {
        id: column.key,
        header: () => (
          <>
            {column.name}
            <div className="sub">{column.key}</div>
          </>
        ),
        cell: (info) => formatCell(info.getValue(), column, refLabels),
      }),
    ),
  ])
}

function isNumeric(column: MasterColumn): boolean {
  return column.type === 'int' || column.type === 'float'
}
