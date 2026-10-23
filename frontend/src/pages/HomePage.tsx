import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../lib/api'

type HealthResponse = {
  status: string
  database: string
}

export function HomePage() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: () => apiFetch<HealthResponse>('/api/health'),
  })

  return (
    <>
      <h1>ホーム</h1>
      <h2>API ヘルスチェック</h2>
      {health.isPending && <p>確認中…</p>}
      {health.isSuccess && (
        <dl>
          <dt>status</dt>
          <dd>{health.data.status}</dd>
          <dt>database</dt>
          <dd>{health.data.database}</dd>
        </dl>
      )}
      {health.isError && <p role="alert">接続できませんでした：{health.error.message}</p>}
    </>
  )
}
