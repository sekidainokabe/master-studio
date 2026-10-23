import { useState, type FormEvent } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { ApiError } from '../lib/api'
import { useLogin, useMe } from '../lib/auth'

function errorMessage(error: Error): string {
  if (error instanceof ApiError) {
    if (error.status === 422) {
      return error.fieldError('email') ?? error.fieldError('password') ?? '入力内容を確認してください。'
    }
    if (error.status === 429) {
      return 'ログインの試行回数が多すぎます。1分ほど待ってからもう一度お試しください。'
    }
  }
  return 'ログインできませんでした。時間をおいてもう一度お試しください。'
}

export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { data: user } = useMe()
  const login = useLogin()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  // ログイン後は、ログイン画面へ飛ばされる前に開こうとしていたページへ戻す
  const from = (location.state as { from?: string } | null)?.from ?? '/'

  if (user) {
    return <Navigate to={from} replace />
  }

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    login.mutate({ email, password }, { onSuccess: () => navigate(from, { replace: true }) })
  }

  return (
    <main className="login">
      <h1>master-studio</h1>
      <form onSubmit={handleSubmit} className="login-form">
        <label>
          メールアドレス
          <input
            type="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </label>
        <label>
          パスワード
          <input
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        {login.isError && (
          <p className="error" role="alert">
            {errorMessage(login.error)}
          </p>
        )}
        <button type="submit" disabled={login.isPending}>
          {login.isPending ? 'ログイン中…' : 'ログイン'}
        </button>
      </form>
    </main>
  )
}
