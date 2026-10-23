import { Link, Outlet, useNavigate } from 'react-router'
import { ROLE_LABELS, useLogout, useMe } from '../lib/auth'

export function AppLayout() {
  const navigate = useNavigate()
  const { data: user } = useMe()
  const logout = useLogout()

  const handleLogout = () => {
    logout.mutate(undefined, { onSuccess: () => navigate('/login', { replace: true }) })
  }

  return (
    <>
      <header className="app-header">
        <Link to="/" className="app-title">master-studio</Link>
        {user && (
          <div className="app-user">
            <span>
              {user.name}（{ROLE_LABELS[user.role]}）
            </span>
            <button type="button" onClick={handleLogout} disabled={logout.isPending}>
              ログアウト
            </button>
          </div>
        )}
      </header>
      <main className="app-main">
        <Outlet />
      </main>
    </>
  )
}
