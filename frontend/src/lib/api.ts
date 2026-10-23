/**
 * Laravel API を呼び出すための小さな fetch ラッパー。
 *
 * CSRF 対策の流れ（Laravel Sanctum の SPA 認証）：
 * 1. GET /sanctum/csrf-cookie で、サーバーが XSRF-TOKEN クッキーを発行する
 * 2. 状態を変えるリクエスト（POST など）のたびに、そのクッキーの値を X-XSRF-TOKEN ヘッダーに載せる
 * 3. サーバーはヘッダーの値とセッション内のトークンを照合する
 * 他サイトの JavaScript はこのサイトのクッキーを読めないため、正しいヘッダーを付けられない。
 */

export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, body: unknown) {
    super(`API エラー（HTTP ${status}）`)
    this.status = status
    this.body = body
  }

  /** Laravel のバリデーションエラー（422）の、指定した項目の最初のメッセージ */
  fieldError(field: string): string | undefined {
    const errors = (this.body as { errors?: Record<string, string[]> } | null)?.errors
    return errors?.[field]?.[0]
  }
}

const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS']

function readCookie(name: string): string | undefined {
  const entry = document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))
  return entry ? decodeURIComponent(entry.slice(name.length + 1)) : undefined
}

export async function fetchCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
}

type ApiOptions = {
  method?: string
  body?: unknown
}

export async function apiFetch<T>(path: string, { method = 'GET', body }: ApiOptions = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }
  if (!SAFE_METHODS.includes(method)) {
    const token = readCookie('XSRF-TOKEN')
    if (token) {
      headers['X-XSRF-TOKEN'] = token
    }
  }

  const res = await fetch(path, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    credentials: 'same-origin',
  })

  if (res.status === 204) {
    return undefined as T
  }

  const data: unknown = await res.json().catch(() => null)
  if (!res.ok) {
    throw new ApiError(res.status, data)
  }
  return data as T
}
