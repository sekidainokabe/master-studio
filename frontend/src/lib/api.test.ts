import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, apiFetch } from './api'

const fetchMock = vi.fn<typeof fetch>()

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function sentHeaders(): Record<string, string> {
  return fetchMock.mock.calls[0][1]?.headers as Record<string, string>
}

describe('apiFetch', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
    // Laravel は XSRF-TOKEN クッキーを URL エンコードして発行する
    document.cookie = 'XSRF-TOKEN=abc%3D%3D'
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
  })

  it('GET では JSON を受け取り、CSRF トークンは付けない', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { status: 'ok' }))

    await expect(apiFetch('/api/health')).resolves.toEqual({ status: 'ok' })

    expect(fetchMock).toHaveBeenCalledWith('/api/health', expect.objectContaining({ method: 'GET', credentials: 'same-origin' }))
    expect(sentHeaders()).toEqual({ Accept: 'application/json' })
  })

  it('POST ではクッキーのトークンをデコードして X-XSRF-TOKEN ヘッダーに載せる', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: {} }))

    await apiFetch('/api/login', { method: 'POST', body: { email: 'a@example.com' } })

    expect(sentHeaders()).toEqual({
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': 'abc==',
    })
    expect(fetchMock.mock.calls[0][1]?.body).toBe('{"email":"a@example.com"}')
  })

  it('204 のときは undefined を返す', async () => {
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }))

    await expect(apiFetch('/api/logout', { method: 'POST' })).resolves.toBeUndefined()
  })

  it('エラーのときは ApiError を投げ、バリデーションメッセージを取り出せる', async () => {
    fetchMock.mockResolvedValue(jsonResponse(422, { message: 'x', errors: { email: ['違います'] } }))

    const error = await apiFetch('/api/login', { method: 'POST', body: {} }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).fieldError('email')).toBe('違います')
    expect((error as ApiError).fieldError('password')).toBeUndefined()
  })
})
