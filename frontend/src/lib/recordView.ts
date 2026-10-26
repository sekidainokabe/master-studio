import type { CellValue, MasterColumn, MasterRecord, RefLabels } from './masters'

/** 表のセルに表示する文字列。参照列は「ab_001 体当たり」のように参照先の名前も並べる */
export function formatCell(value: CellValue, column: MasterColumn, refLabels: RefLabels): string {
  if (value === null || value === '') {
    return ''
  }
  if (column.type === 'bool') {
    return value ? 'はい' : 'いいえ'
  }
  if (column.type === 'ref' && column.ref_master_key) {
    const label = refLabels[column.ref_master_key]?.get(String(value))
    // 参照先が見つからないときも key だけは表示する（参照切れに気づけるように）
    return label && label !== value ? `${value} ${label}` : String(value)
  }
  return String(value)
}

export type RecordFilter = {
  /** record_key と各セルの表示文字列に対する部分一致（大文字・小文字は区別しない） */
  keyword: string
  /** 列挙型の列ごとの絞り込み。列の key → 選んだ値 */
  enumValues: Record<string, string>
}

export function filterRecords(
  records: MasterRecord[],
  columns: MasterColumn[],
  refLabels: RefLabels,
  filter: RecordFilter,
): MasterRecord[] {
  const keyword = filter.keyword.trim().toLowerCase()
  const enumEntries = Object.entries(filter.enumValues).filter(([, value]) => value !== '')

  return records.filter((record) => {
    const matchesEnums = enumEntries.every(([key, value]) => record.data[key] === value)
    if (!matchesEnums) {
      return false
    }
    if (keyword === '') {
      return true
    }
    const texts = [record.record_key, ...columns.map((c) => formatCell(record.data[c.key] ?? null, c, refLabels))]
    return texts.some((text) => text.toLowerCase().includes(keyword))
  })
}
