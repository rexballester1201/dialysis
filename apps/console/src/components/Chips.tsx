/**
 * Small labels that carry state.
 *
 * Every one of these renders its text as well as its colour. Cohort especially:
 * seating a patient by a colour somebody cannot distinguish is the failure mode
 * these exist to avoid, so the word is never optional.
 */

const COHORT_LABEL: Record<string, string> = {
  clean: 'Clean',
  hbv: 'HBV',
  hcv: 'HCV',
}

export function Cohort({ value }: { value: string | null }) {
  if (value === null) return <span className="muted">—</span>

  return <span className={`chip chip-${value}`}>{COHORT_LABEL[value] ?? value}</span>
}

const STATUS: Record<string, { label: string; tone: string }> = {
  scheduled: { label: 'Not arrived', tone: 'quiet' },
  checked_in: { label: 'Checked in', tone: 'quiet' },
  in_progress: { label: 'Running', tone: 'ok' },
  completed: { label: 'Off', tone: 'quiet' },
  aborted: { label: 'Aborted', tone: 'danger' },
  missed: { label: 'Missed', tone: 'warn' },
  cancelled: { label: 'Cancelled', tone: 'quiet' },
  refused: { label: 'Refused', tone: 'warn' },
}

export function StatusChip({ status }: { status: string }) {
  const known = STATUS[status]

  return (
    <span className={`chip chip-${known?.tone ?? 'quiet'}`}>{known?.label ?? status}</span>
  )
}

const PATIENT_STATUS: Record<string, string> = {
  active: 'ok',
  transferred: 'quiet',
  deceased: 'quiet',
  transplanted: 'ok',
  recovered: 'ok',
  lost_to_followup: 'warn',
}

export function PatientStatus({ status }: { status: string }) {
  return (
    <span className={`chip chip-${PATIENT_STATUS[status] ?? 'quiet'}`}>
      {status.replace(/_/g, ' ')}
    </span>
  )
}

/**
 * Where a claim stands. The tone says whether anyone needs to act: amber is
 * waiting on the unit or short-paid, red is lost money, green is money in.
 */
const CLAIM_STATUS: Record<string, { label: string; tone: string }> = {
  draft: { label: 'Draft', tone: 'quiet' },
  ready: { label: 'Ready to submit', tone: 'quiet' },
  submitted: { label: 'Submitted', tone: 'quiet' },
  acknowledged: { label: 'Acknowledged', tone: 'quiet' },
  in_process: { label: 'In process', tone: 'quiet' },
  resubmitted: { label: 'Resubmitted', tone: 'quiet' },
  returned: { label: 'Returned', tone: 'warn' },
  approved: { label: 'Approved, unpaid', tone: 'warn' },
  partially_paid: { label: 'Part-paid', tone: 'warn' },
  paid: { label: 'Paid', tone: 'ok' },
  denied: { label: 'Denied', tone: 'danger' },
  void: { label: 'Void', tone: 'quiet' },
}

export function ClaimStatus({ status }: { status: string }) {
  const known = CLAIM_STATUS[status]

  return <span className={`chip chip-${known?.tone ?? 'quiet'}`}>{known?.label ?? status}</span>
}

export function claimStatusLabel(status: string): string {
  return CLAIM_STATUS[status]?.label ?? status
}

const INVOICE_STATUS: Record<string, { label: string; tone: string }> = {
  draft: { label: 'Draft', tone: 'quiet' },
  issued: { label: 'Issued', tone: 'warn' },
  partially_paid: { label: 'Part-paid', tone: 'warn' },
  paid: { label: 'Paid', tone: 'ok' },
  void: { label: 'Void', tone: 'quiet' },
  written_off: { label: 'Written off', tone: 'quiet' },
}

export function InvoiceStatus({ status }: { status: string }) {
  const known = INVOICE_STATUS[status]

  return <span className={`chip chip-${known?.tone ?? 'quiet'}`}>{known?.label ?? status}</span>
}

const SEVERITY: Record<string, string> = {
  minor: 'quiet',
  moderate: 'warn',
  severe: 'danger',
  life_threatening: 'danger',
}

export function Severity({ value }: { value: string }) {
  return <span className={`chip chip-${SEVERITY[value] ?? 'quiet'}`}>{value.replace(/_/g, ' ')}</span>
}
