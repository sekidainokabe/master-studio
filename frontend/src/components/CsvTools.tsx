import { useRef, type ChangeEvent } from 'react'
import { exportCsvUrl, importErrorMessages, importSummary, useImportCsv } from '../lib/changesetCsv'

/**
 * 編集画面の CSV の操作。ダウンロード → Excel で編集 → 取り込み、の流れで使う。
 * 未保存の編集があるときは取り込めない（画面の編集と CSV のどちらを優先するかで迷わせないため）
 */
export function CsvTools({
  changesetId,
  masterKey,
  editable,
  dirty,
}: {
  changesetId: number
  masterKey: string
  editable: boolean
  dirty: boolean
}) {
  const importCsv = useImportCsv(changesetId, masterKey)
  const fileInput = useRef<HTMLInputElement>(null)

  const handleFile = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    // 同じファイルを続けて選んでも change が起きるよう、選択を空に戻す
    e.target.value = ''
    if (file) {
      importCsv.mutate(file)
    }
  }

  return (
    <div className="csv-tools">
      {/* 書き出しはクッキーで認証される通常のリンクで、ブラウザのダウンロードに任せる */}
      <a className="button-link secondary" href={exportCsvUrl(changesetId, masterKey)} download>
        CSV をダウンロード
      </a>
      {editable && (
        <>
          <button
            type="button"
            className="secondary"
            onClick={() => fileInput.current?.click()}
            disabled={dirty || importCsv.isPending}
          >
            {importCsv.isPending ? '取り込み中…' : 'CSV を取り込む'}
          </button>
          <input
            ref={fileInput}
            type="file"
            accept=".csv,text/csv"
            hidden
            onChange={handleFile}
            aria-label="取り込む CSV ファイル"
          />
          <span className="sub">
            {dirty
              ? '未保存の変更を保存するか破棄すると、CSV を取り込めます。'
              : '取り込むと、すぐに保存されます。CSV にない行は削除されません。'}
          </span>
        </>
      )}
      <div aria-live="polite" className="csv-result">
        {importCsv.isSuccess && <p>CSV を取り込みました：{importSummary(importCsv.data)}</p>}
        {importCsv.isError && (
          <div className="error" role="alert">
            <p>CSV を取り込めませんでした。何も保存していません。</p>
            <ul>
              {importErrorMessages(importCsv.error).map((message, i) => (
                <li key={i}>{message}</li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  )
}
