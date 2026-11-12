import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { createColumnHelper, tableFeatures, useTable } from '@tanstack/react-table'
import { Link, useBlocker, useNavigate, useParams, useSearchParams } from 'react-router'
import { StatusBadge } from '../components/StatusBadge'
import { ValidationPanel, type JumpTarget } from '../components/ValidationPanel'
import { ApiError } from '../lib/api'
import { useChangeset } from '../lib/changesets'
import {
  countByStatus,
  isCellChanged,
  useChangesetRecords,
  useChangesetRefs,
  useSaveChangesetRecords,
  type RefOption,
  type RowStatus,
} from '../lib/changesetRecords'
import {
  countByMaster,
  mergeCrossErrors,
  ROW_ERROR_KEY,
  useChangesetValidation,
  type ValidationError,
} from '../lib/changesetValidation'
import {
  applyDrafts,
  buildPayload,
  emptyRecord,
  parseCellInput,
  validateNewKey,
  type DisplayRow,
  type Drafts,
} from '../lib/editDraft'
import { useMasters, useMasterSchema, type CellValue, type MasterColumn, type RefLabels } from '../lib/masters'
import { formatCell } from '../lib/recordView'

/**
 * 変更セットの編集画面。マスタごとのタブで、本番データに変更を重ねた表を表示・編集する。
 * 選んでいるタブは URL（?master=）に持たせ、再読み込みやリンクの共有でも同じタブを開けるようにする
 */
export function ChangesetEditPage() {
  const { id } = useParams()
  const changesetId = Number(id)
  const changeset = useChangeset(changesetId)
  const masters = useMasters()
  const validation = useChangesetValidation(changesetId)
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  // 検証結果のパネルで押したエラーの場所。表が描画されたら、その行まで移動する
  const [jump, setJump] = useState<JumpTarget | null>(null)
  const clearJump = useCallback(() => setJump(null), [])

  if (changeset.isError || masters.isError) {
    return <p role="alert">変更セットを取得できませんでした。存在しない変更セットの可能性があります。</p>
  }
  if (changeset.isPending || masters.isPending) {
    return <p>読み込み中…</p>
  }

  const c = changeset.data
  const masterKey = searchParams.get('master') ?? masters.data[0]?.key ?? ''
  const master = masters.data.find((m) => m.key === masterKey)
  const errorCounts = countByMaster(validation.data?.errors ?? [])

  const handleJump = (target: JumpTarget) => {
    if (target.master !== masterKey) {
      // 未保存の編集があれば、タブの切り替えと同じく離脱の確認が出る
      navigate(`?master=${target.master}`)
    }
    setJump(target)
  }

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

      <ValidationPanel changesetId={c.id} masters={masters.data} onJump={handleJump} />

      <nav className="tabs" aria-label="マスタ">
        {masters.data.map((m) => (
          <Link
            key={m.key}
            to={`?master=${m.key}`}
            className={m.key === masterKey ? 'tab active' : 'tab'}
            aria-current={m.key === masterKey ? 'page' : undefined}
          >
            {m.name}
            {errorCounts[m.key] > 0 && (
              <span className="tab-count" aria-label={`エラー ${errorCounts[m.key]} 件`}>
                {errorCounts[m.key]}
              </span>
            )}
          </Link>
        ))}
      </nav>

      {master ? (
        // マスタを切り替えたら表を作り直し、前のマスタの状態を持ち越さない（未保存の編集は離脱の確認で守る）
        <EditGrid
          key={master.key}
          changesetId={c.id}
          masterKey={master.key}
          editable={c.can.update}
          validationErrors={validation.data?.errors}
          jump={jump}
          onJumpHandled={clearJump}
        />
      ) : (
        <p role="alert">指定されたマスタはありません。上のタブから選んでください。</p>
      )}
    </>
  )
}

// 列定義やデータの参照が描画のたびに変わると、表の計算がやり直しになるため、機能の定義はモジュールに1つだけ置く
const features = tableFeatures({})
const helper = createColumnHelper<typeof features, DisplayRow>()

const STATUS_TEXT: Record<RowStatus, string> = {
  unchanged: '',
  created: '追加',
  updated: '変更',
  deleted: '削除',
}

/** 表のセルから呼ぶ操作。setDrafts の関数形だけを使うので、参照は変わらない */
type Actions = {
  changeCell: (row: DisplayRow, columnKey: string, value: CellValue) => void
  deleteRow: (row: DisplayRow) => void
  restoreRow: (row: DisplayRow) => void
}

function EditGrid({
  changesetId,
  masterKey,
  editable,
  validationErrors,
  jump,
  onJumpHandled,
}: {
  changesetId: number
  masterKey: string
  editable: boolean
  validationErrors: ValidationError[] | undefined
  jump: JumpTarget | null
  onJumpHandled: () => void
}) {
  const schema = useMasterSchema(masterKey)
  const rows = useChangesetRecords(changesetId, masterKey)
  const refs = useChangesetRefs(changesetId, schema.data)
  const save = useSaveChangesetRecords(changesetId, masterKey)
  const [drafts, setDrafts] = useState<Drafts>({})

  const dirtyCount = Object.keys(drafts).length
  useLeaveWarning(dirtyCount > 0)

  const actions = useMemo<Actions>(
    () => ({
      changeCell: (row, columnKey, value) =>
        setDrafts((prev) => ({ ...prev, [row.record_key]: { ...row.data, [columnKey]: value } })),
      deleteRow: (row) =>
        setDrafts((prev) => {
          // 画面で追加しただけの行は、サーバーに送るものがないので編集ごと消す
          if (row.original === null && row.item_id === null) {
            return withoutKey(prev, row.record_key)
          }
          return { ...prev, [row.record_key]: null }
        }),
      restoreRow: (row) =>
        setDrafts((prev) =>
          row.dirty
            ? withoutKey(prev, row.record_key)
            : // 保存済みの削除を取り消すときは、本番の値で上書きする（サーバーが本番と同じと判断して記録を消す）
              { ...prev, [row.record_key]: row.original ?? row.data },
        ),
    }),
    [],
  )

  const columns = useMemo(
    () => buildColumns(schema.data ?? [], refs.labels, refs.options, editable, actions),
    [schema.data, refs.labels, refs.options, editable, actions],
  )
  const data = useMemo(
    () => mergeCrossErrors(applyDrafts(rows.data ?? [], drafts, schema.data ?? []), validationErrors ?? [], masterKey),
    [rows.data, drafts, schema.data, validationErrors, masterKey],
  )
  const table = useTable({ features, columns, data, getRowId: (row) => row.record_key })

  const ready = !schema.isPending && !rows.isPending && !refs.isPending
  useEffect(() => {
    if (!jump || jump.master !== masterKey || !ready) {
      return
    }
    const tr = document.getElementById(rowDomId(jump.recordKey))
    if (tr) {
      tr.scrollIntoView({ block: 'center' })
      // 編集できるときは、エラーの列の入力欄にフォーカスを移す（キーボードだけでも続けて直せるように）
      const field = jump.column
        ? tr.querySelector<HTMLElement>(`[data-column="${jump.column}"] input, [data-column="${jump.column}"] select`)
        : null
      field?.focus({ preventScroll: true })
      // 同じ行を続けて押しても強調し直せるよう、いったん外してから付け直す
      tr.classList.remove('row-focus')
      void tr.offsetWidth
      tr.classList.add('row-focus')
    }
    onJumpHandled()
  }, [jump, masterKey, ready, onJumpHandled])

  if (schema.isError || rows.isError) {
    return <p role="alert">データを取得できませんでした。</p>
  }
  if (schema.isPending || rows.isPending || refs.isPending) {
    return <p>読み込み中…</p>
  }

  const counts = countByStatus(data)
  const handleSave = () => save.mutate(buildPayload(drafts), { onSuccess: () => setDrafts({}) })
  const handleDiscard = () => {
    if (window.confirm('保存していない変更をすべて破棄します。よろしいですか？')) {
      setDrafts({})
      save.reset()
    }
  }

  return (
    <>
      <div className="edit-toolbar">
        <span className="sub">
          {data.length} 件／変更 {counts.changed} 件／エラー {counts.errors} 件
        </span>
        {editable && (
          <>
            <span className={dirtyCount > 0 ? 'unsaved' : 'sub'} aria-live="polite">
              {dirtyCount > 0 ? `未保存の変更が ${dirtyCount} 件あります` : '未保存の変更はありません'}
            </span>
            <button type="button" onClick={handleSave} disabled={dirtyCount === 0 || save.isPending}>
              {save.isPending ? '保存中…' : '保存する'}
            </button>
            <button type="button" className="secondary" onClick={handleDiscard} disabled={dirtyCount === 0 || save.isPending}>
              変更を破棄
            </button>
          </>
        )}
      </div>
      {save.isError && (
        <p className="error" role="alert">
          {saveErrorMessage(save.error)}
        </p>
      )}
      {editable && counts.errors > 0 && (
        <p className="sub">エラーがあっても保存できます。レビュー依頼の前にすべて直してください。</p>
      )}

      {editable && (
        <AddRowForm
          existingKeys={data.map((row) => row.record_key)}
          onAdd={(recordKey) => setDrafts((prev) => ({ ...prev, [recordKey]: emptyRecord(schema.data) }))}
        />
      )}

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
              <tr
                key={row.id}
                id={rowDomId(row.original.record_key)}
                className={`row-${row.original.status}${row.original.dirty ? ' row-dirty' : ''}`}
              >
                {row.getAllCells().map((cell) => {
                  const column = schema.data.find((c) => c.key === cell.column.id)
                  // 行全体のエラー（参照されている行の削除など）は「状態」の列に出す
                  const error =
                    cell.column.id === 'status'
                      ? row.original.errors[ROW_ERROR_KEY]
                      : column
                        ? row.original.errors[column.key]
                        : undefined
                  const classes = [
                    column && isNumeric(column) ? 'num' : '',
                    column && isCellChanged(row.original, column.key) ? 'cell-changed' : '',
                    error ? 'cell-error' : '',
                  ].filter(Boolean)
                  return (
                    <td
                      key={cell.id}
                      className={classes.join(' ') || undefined}
                      title={error}
                      data-column={cell.column.id}
                    >
                      <table.FlexRender cell={cell} />
                    </td>
                  )
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {data.length === 0 && <p className="sub">このマスタにはまだデータがありません。</p>}
    </>
  )
}

function buildColumns(
  columns: MasterColumn[],
  refLabels: RefLabels,
  refOptions: Record<string, RefOption[]>,
  editable: boolean,
  actions: Actions,
) {
  return helper.columns([
    // 色だけに頼らず、行の状態を文字でも示す。保存前の行には「*」を付ける
    helper.accessor('status', {
      header: '状態',
      cell: (info) => `${STATUS_TEXT[info.getValue()]}${info.row.original.dirty ? ' *' : ''}`,
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
            {column.is_required && <span aria-label="必須"> *</span>}
            <div className="sub">{column.key}</div>
          </>
        ),
        cell: (info) => {
          const row = info.row.original
          if (!editable || row.status === 'deleted') {
            return formatCell(info.getValue(), column, refLabels)
          }
          return (
            <CellInput
              row={row}
              column={column}
              value={info.getValue()}
              options={column.ref_master_key ? (refOptions[column.ref_master_key] ?? []) : []}
              onChange={(value) => actions.changeCell(row, column.key, value)}
            />
          )
        },
      }),
    ),
    ...(editable
      ? [
          helper.display({
            id: 'actions',
            header: '操作',
            cell: (info) => {
              const row = info.row.original
              return row.status === 'deleted' ? (
                <button type="button" className="secondary small" onClick={() => actions.restoreRow(row)}>
                  元に戻す
                </button>
              ) : (
                <button type="button" className="danger small" onClick={() => actions.deleteRow(row)}>
                  削除
                </button>
              )
            },
          }),
        ]
      : []),
  ])
}

/** 列の型に合わせた入力欄 */
function CellInput({
  row,
  column,
  value,
  options,
  onChange,
}: {
  row: DisplayRow
  column: MasterColumn
  value: CellValue
  options: RefOption[]
  onChange: (value: CellValue) => void
}) {
  const label = `${row.record_key} の ${column.name}`
  const invalid = row.errors[column.key] ? true : undefined

  switch (column.type) {
    case 'bool':
      return <input type="checkbox" aria-label={label} checked={value === true} onChange={(e) => onChange(e.target.checked)} />
    case 'enum':
    case 'ref': {
      const choices =
        column.type === 'enum' ? (column.enum_values ?? []).map((v) => ({ value: v, label: v })) : options
      // 参照切れなど、選択肢にない値も消さずに表示する（エラーで気づけるように）
      const missing = value !== null && value !== '' && !choices.some((o) => o.value === value)
      return (
        <select
          aria-label={label}
          aria-invalid={invalid}
          value={value === null ? '' : String(value)}
          onChange={(e) => onChange(e.target.value === '' ? null : e.target.value)}
        >
          <option value="">（なし）</option>
          {missing && <option value={String(value)}>{String(value)}（見つかりません）</option>}
          {choices.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      )
    }
    default:
      return (
        <input
          className="cell-input"
          aria-label={label}
          aria-invalid={invalid}
          inputMode={column.type === 'int' ? 'numeric' : column.type === 'float' ? 'decimal' : undefined}
          value={value === null ? '' : String(value)}
          onChange={(e) => onChange(parseCellInput(column, e.target.value))}
        />
      )
  }
}

function AddRowForm({ existingKeys, onAdd }: { existingKeys: string[]; onAdd: (recordKey: string) => void }) {
  const [recordKey, setRecordKey] = useState('')
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const key = recordKey.trim()
    const message = validateNewKey(key, existingKeys)
    setError(message)
    if (message === null) {
      onAdd(key)
      setRecordKey('')
    }
  }

  return (
    <form className="add-row" onSubmit={handleSubmit}>
      <label>
        新しい行の record_key
        <input
          className="mono"
          value={recordKey}
          onChange={(e) => setRecordKey(e.target.value)}
          placeholder="例：en_100"
          aria-invalid={error ? true : undefined}
        />
      </label>
      <button type="submit" className="secondary">
        行を追加
      </button>
      {error && (
        <span className="error" role="alert">
          {error}
        </span>
      )}
    </form>
  )
}

/**
 * 保存していない編集があるとき、ページを離れる前に確認する。
 * アプリ内の移動（タブの切り替えを含む）は React Router の useBlocker、再読み込みやタブを閉じる操作は beforeunload で止める
 */
function useLeaveWarning(dirty: boolean) {
  const blocker = useBlocker(
    useCallback(
      ({ currentLocation, nextLocation }) =>
        dirty &&
        (currentLocation.pathname !== nextLocation.pathname || currentLocation.search !== nextLocation.search),
      [dirty],
    ),
  )

  useEffect(() => {
    if (blocker.state === 'blocked') {
      if (window.confirm('保存していない変更があります。破棄して移動しますか？')) {
        blocker.proceed()
      } else {
        blocker.reset()
      }
    }
  }, [blocker])

  useEffect(() => {
    if (!dirty) {
      return
    }
    const handler = (e: BeforeUnloadEvent) => e.preventDefault()
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [dirty])
}

function withoutKey(drafts: Drafts, recordKey: string): Drafts {
  const next = { ...drafts }
  delete next[recordKey]
  return next
}

function saveErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    switch (error.status) {
      case 403:
        return '保存する権限がありません。'
      case 409:
        return '下書きではなくなったため、保存できませんでした。画面を再読み込みしてください。'
      case 422:
        return `保存できない値があります。${firstMessage(error.body)}`
    }
  }
  return '保存できませんでした。時間をおいてもう一度お試しください。'
}

function firstMessage(body: unknown): string {
  const errors = (body as { errors?: Record<string, string[]> } | null)?.errors
  return errors ? (Object.values(errors)[0]?.[0] ?? '') : ''
}

function rowDomId(recordKey: string): string {
  return `row-${recordKey}`
}

function isNumeric(column: MasterColumn): boolean {
  return column.type === 'int' || column.type === 'float'
}
