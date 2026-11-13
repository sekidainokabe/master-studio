import { describe, expect, it } from 'vitest'
import { formatBytes, releaseExportUrl } from './releases'

describe('formatBytes', () => {
  it('1 KB 未満はバイトで出す', () => {
    expect(formatBytes(0)).toBe('0 B')
    expect(formatBytes(1023)).toBe('1023 B')
  })

  it('KB・MB は小数1桁で出す', () => {
    expect(formatBytes(63343)).toBe('61.9 KB')
    expect(formatBytes(1.5 * 1024 * 1024)).toBe('1.5 MB')
  })
})

describe('releaseExportUrl', () => {
  it('版の番号で URL を作る', () => {
    expect(releaseExportUrl(2)).toBe('/api/releases/2/export')
  })
})
