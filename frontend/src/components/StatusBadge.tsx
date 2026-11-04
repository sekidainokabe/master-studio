import { STATUS_LABELS, type ChangesetStatus } from '../lib/changesets'

export function StatusBadge({ status }: { status: ChangesetStatus }) {
  return <span className={`badge badge-${status}`}>{STATUS_LABELS[status]}</span>
}
