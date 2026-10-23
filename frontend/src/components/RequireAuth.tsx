import { Navigate, Outlet, useLocation } from 'react-router'
import { useMe } from '../lib/auth'

/** 未ログインなら /login へ移動させ、ログイン後に元のページへ戻れるよう移動元を渡す */
export function RequireAuth() {
  const location = useLocation()
  const { data: user, isPending, isError } = useMe()

  if (isPending) {
    return <p className="status">読み込み中…</p>
  }
  if (isError) {
    return <p className="status" role="alert">サーバーに接続できませんでした。</p>
  }
  if (user === null) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }
  return <Outlet />
}
