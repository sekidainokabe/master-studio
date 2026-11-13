import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { formatDateTime } from '../lib/format'
import { formatBytes, releaseExportUrl, useReleaseContent, useReleases, type Release } from '../lib/releases'

/** リリースの一覧。承認のたびに1つ増え、その時点の本番データを JSON で取り出せる */
export function ReleasesPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Math.max(1, Number(searchParams.get('page')) || 1)
  const releases = useReleases(page)
  const meta = releases.data?.meta

  const goTo = (next: number) => setSearchParams(next > 1 ? { page: String(next) } : {})

  return (
    <>
      <h1>リリース</h1>
      <p>
        変更セットが承認されるたびに、新しい版ができます。ゲームが読み込む想定の JSON（その時点の本番データすべて）を取り出せます。
      </p>

      {releases.isPending && <p>読み込み中…</p>}
      {releases.isError && <p role="alert">リリースの一覧を取得できませんでした。</p>}
      {releases.isSuccess && (
        <ul className="release-list">
          {releases.data.data.map((release) => (
            <ReleaseItem key={release.version} release={release} />
          ))}
        </ul>
      )}

      {meta && meta.last_page > 1 && (
        <nav className="pagination" aria-label="ページ送り">
          <button type="button" disabled={page <= 1} onClick={() => goTo(page - 1)}>
            前へ
          </button>
          <span>
            {meta.current_page} / {meta.last_page} ページ
          </span>
          <button type="button" disabled={page >= meta.last_page} onClick={() => goTo(page + 1)}>
            次へ
          </button>
        </nav>
      )}
    </>
  )
}

function ReleaseItem({ release: r }: { release: Release }) {
  const [open, setOpen] = useState(false)

  return (
    <li className="release-item">
      <div className="release-head">
        <strong>v{r.version}</strong>
        <span className="nowrap">{formatDateTime(r.created_at)}</span>
        <span>{r.released_by?.name ?? '不明'} が反映</span>
        <span>
          {r.changeset ? (
            <Link to={`/changesets/${r.changeset.id}`}>
              #{r.changeset.id} {r.changeset.title}
            </Link>
          ) : (
            <span className="sub">初期データ（変更セットなし）</span>
          )}
        </span>
      </div>

      {r.content_bytes === null ? (
        <p className="sub">このリリースには JSON がありません。</p>
      ) : (
        <>
          <div className="release-actions">
            {/* 同じオリジンなのでクッキーで認証され、Content-Disposition によりファイルとして保存される */}
            <a href={releaseExportUrl(r.version)} download className="button-link secondary">
              JSON をダウンロード（{formatBytes(r.content_bytes)}）
            </a>
          </div>
          {/* <details> の開閉はブラウザに任せ、開いたときだけ中身を取りに行く */}
          <details onToggle={(e) => setOpen(e.currentTarget.open)}>
            <summary>中身を見る</summary>
            <ReleasePreview version={r.version} enabled={open} />
          </details>
        </>
      )}
    </li>
  )
}

function ReleasePreview({ version, enabled }: { version: number; enabled: boolean }) {
  const content = useReleaseContent(version, enabled)

  if (content.isError) {
    return <p role="alert">JSON を取得できませんでした。</p>
  }
  if (content.isPending) {
    return <p className="sub">読み込み中…</p>
  }
  return <pre className="json-preview">{content.data}</pre>
}
