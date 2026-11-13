import { useQuery } from '@tanstack/react-query'
import { ApiError, apiFetch } from './api'
import type { Paginated } from './changesets'

type UserRef = { id: number; name: string }

export type Release = {
  version: number
  /** シードで作った最初のリリースは、変更セットを通していないので null */
  changeset: { id: number; title: string } | null
  released_by: UserRef | null
  /** 出力 JSON の大きさ（バイト）。JSON がなければ null */
  content_bytes: number | null
  created_at: string
}

export const releaseKeys = {
  all: ['releases'] as const,
  list: (page: number) => ['releases', 'list', page] as const,
  content: (version: number) => ['releases', 'content', version] as const,
}

export function useReleases(page: number) {
  return useQuery({
    queryKey: releaseKeys.list(page),
    queryFn: () => apiFetch<Paginated<Release>>(`/api/releases${page > 1 ? `?page=${page}` : ''}`),
    placeholderData: (previous) => previous,
  })
}

export function releaseExportUrl(version: number): string {
  return `/api/releases/${version}/export`
}

/**
 * プレビュー用に、出力 JSON をそのままの文字列で取る（整形や列の並びを、ダウンロードされるものと同じにするため）。
 * 大きいので、開いたときだけ取りに行く。リリースは作成後に変わらないため、取り直さない
 */
export function useReleaseContent(version: number, enabled: boolean) {
  return useQuery({
    queryKey: releaseKeys.content(version),
    queryFn: async () => {
      const res = await fetch(releaseExportUrl(version), { credentials: 'same-origin' })
      if (!res.ok) {
        throw new ApiError(res.status, null)
      }
      return res.text()
    },
    enabled,
    staleTime: Infinity,
  })
}

/** 「63.3 KB」のような、人が読みやすい大きさ */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}
