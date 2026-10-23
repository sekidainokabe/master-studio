import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchMe, login } from './auth'

const fetchMock = vi.fn<typeof fetch>()

const user = { id: 1, name: '編集 太郎', email: 'editor@example.com', role: 'editor' }

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

describe('auth', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
  })

  it('fetchMe はログイン中のユーザーを返す', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: user }))

    await expect(fetchMe()).resolves.toEqual(user)
  })

  it('fetchMe は未ログイン（401）なら null を返す', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))

    await expect(fetchMe()).resolves.toBeNull()
  })

  it('fetchMe は 401 以外のエラーをそのまま投げる', async () => {
    fetchMock.mockResolvedValue(jsonResponse(500, { message: 'Server Error' }))

    await expect(fetchMe()).rejects.toMatchObject({ status: 500 })
  })

  it('login は先に CSRF クッキーを取得してからログインする', async () => {
    fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: user }))

    await expect(login({ email: 'editor@example.com', password: 'password' })).resolves.toEqual(user)

    expect(fetchMock.mock.calls.map(([path]) => path)).toEqual(['/sanctum/csrf-cookie', '/api/login'])
  })
})
