import { useEffect, useState } from 'react'

type HealthResponse = {
  status: string
  database: string
}

type HealthState =
  | { kind: 'loading' }
  | { kind: 'success'; data: HealthResponse }
  | { kind: 'error'; message: string }

function App() {
  const [health, setHealth] = useState<HealthState>({ kind: 'loading' })

  useEffect(() => {
    // 画面を離れたときに通信を中断する
    const controller = new AbortController()

    fetch('/api/health', { signal: controller.signal })
      .then(async (res) => {
        const data = (await res.json()) as HealthResponse
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}: ${JSON.stringify(data)}`)
        }
        setHealth({ kind: 'success', data })
      })
      .catch((err: unknown) => {
        if (controller.signal.aborted) return
        setHealth({ kind: 'error', message: err instanceof Error ? err.message : String(err) })
      })

    return () => controller.abort()
  }, [])

  return (
    <main>
      <h1>master-studio</h1>
      <h2>API ヘルスチェック</h2>
      {health.kind === 'loading' && <p>確認中…</p>}
      {health.kind === 'success' && (
        <dl>
          <dt>status</dt>
          <dd>{health.data.status}</dd>
          <dt>database</dt>
          <dd>{health.data.database}</dd>
        </dl>
      )}
      {health.kind === 'error' && <p role="alert">接続できませんでした：{health.message}</p>}
    </main>
  )
}

export default App
