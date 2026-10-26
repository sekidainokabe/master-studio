const dateTimeFormat = new Intl.DateTimeFormat('ja-JP', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
})

/** API の ISO 8601 形式の日時を「2026/10/26 16:30」の形にする（ブラウザのタイムゾーンで表示） */
export function formatDateTime(iso: string | null): string {
  return iso ? dateTimeFormat.format(new Date(iso)) : ''
}
