import type { ClaimDetail as Claim, RemittanceInput } from '@dialysis/api-client'
import { useConfirm, type ConfirmRequest } from '@dialysis/ui'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { ClaimStatus, claimStatusLabel } from '../components/Chips'
import { isAmount, money, stamp } from '../components/format'

/**
 * One claim, and what may happen to it next.
 *
 * The buttons are the claim's `next_statuses`, straight from the ledger's
 * transition table -- this screen keeps no copy of the rules. Approved,
 * part-paid and paid never appear as buttons: they are what the payer decided,
 * and they come from keying the remittance advice, where the amounts decide.
 */

interface Step {
  label: string
  tone?: 'danger'
  request: (claim: Claim) => ConfirmRequest
}

const DESTINATION = 'the claim’s history, with your name and the time'

const STEPS: Record<string, Step> = {
  ready: {
    label: 'Mark ready to submit',
    request: () => ({
      title: 'Mark this claim ready to submit?',
      body: 'A checkpoint before it goes to the payer. It can still go back to draft, or be voided.',
      confirmLabel: 'Mark ready',
    }),
  },
  draft: {
    label: 'Back to draft',
    request: () => ({
      title: 'Take this claim back to draft?',
      body: 'Nothing has gone to the payer yet, so nothing changes outside this system.',
      confirmLabel: 'Back to draft',
    }),
  },
  submitted: {
    label: 'Record submission to payer',
    request: (claim) => ({
      title: `Record that ${claim.claim_no} has gone to the payer?`,
      body:
        'This cannot be undone. From here the claim moves only with the payer’s decisions, and it can no ' +
        'longer be voided — its sessions stay on it, so they can never be billed a second time.',
      confirmLabel: 'Record submission',
    }),
  },
  acknowledged: {
    label: 'Payer acknowledged receipt',
    request: () => ({
      title: 'Record the payer’s acknowledgement?',
      confirmLabel: 'Record acknowledgement',
    }),
  },
  in_process: {
    label: 'Payer is processing it',
    request: () => ({ title: 'Record that the payer is processing this claim?', confirmLabel: 'Record' }),
  },
  returned: {
    label: 'Payer returned it',
    request: () => ({
      title: 'Record that the payer returned this claim?',
      body: 'A returned claim is refiled as it stands, once whatever the payer asked for is fixed.',
      confirmLabel: 'Record return',
      reason: { label: 'What did the payer say needs fixing?', minLength: 1, destination: DESTINATION },
    }),
  },
  resubmitted: {
    label: 'Record resubmission',
    request: () => ({
      title: 'Record that the claim went back to the payer?',
      body: 'It goes back as it stands — the same sessions and amount.',
      confirmLabel: 'Record resubmission',
    }),
  },
  denied: {
    label: 'Payer denied it',
    tone: 'danger',
    request: () => ({
      title: 'Record a denial with no remittance advice?',
      body:
        'Use this for a denial notice. If the payer sent a remittance advice, key that instead so the ' +
        'amounts and the denial code are kept. Appeals are not recorded in this system yet.',
      confirmLabel: 'Record denial',
      tone: 'danger',
      reason: { label: 'The payer’s reason for the denial', minLength: 1, destination: DESTINATION },
    }),
  },
  void: {
    label: 'Void claim',
    tone: 'danger',
    request: (claim) => ({
      title: `Void ${claim.claim_no}?`,
      body:
        `Its ${claim.sessions.length} session${claim.sessions.length === 1 ? '' : 's'} go back to ` +
        'being claimable and the allotment is returned. The claim, its history and an audit record of what ' +
        'it covered are kept. A void claim is never reopened.',
      confirmLabel: 'Void claim',
      tone: 'danger',
      reason: { label: 'Why is this claim being voided?', minLength: 10, destination: DESTINATION },
    }),
  },
}

export function ClaimDetail({
  claimNo,
  onBack,
  onOpenSession,
  onOpenPatient,
}: {
  claimNo: string
  onBack: () => void
  onOpenSession: (publicId: string) => void
  onOpenPatient: (publicId: string) => void
}) {
  const confirmAction = useConfirm()

  const [claim, setClaim] = useState<Claim | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setProblem(null)

    try {
      setClaim(await api.claim(claimNo))
    } catch (error) {
      setProblem(explain(error))
    }
  }, [claimNo])

  useEffect(() => {
    void load()
  }, [load])

  async function move(status: string) {
    if (claim === null) return

    const step = STEPS[status]
    const answer = await confirmAction(
      step?.request(claim) ?? { title: `Move this claim to ${status}?`, confirmLabel: 'Move' },
    )

    if (!answer.confirmed) return

    setBusy(true)
    setProblem(null)
    setDone(null)

    try {
      const updated = await api.transitionClaim(claim.claim_no, status, answer.reason ?? undefined)
      setClaim(updated)
      setDone(`Now ${claimStatusLabel(updated.status).toLowerCase()}.`)
    } catch (error) {
      // Someone else moved it first, or the step is not allowed from here: the
      // server says which, and the screen reloads to show where it really is.
      setProblem(explain(error))
      void api.claim(claim.claim_no).then(setClaim).catch(() => undefined)
    } finally {
      setBusy(false)
    }
  }

  if (claim === null) {
    return (
      <>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← Claims
        </button>
        <div className="panel" style={{ marginTop: 14 }}>
          {problem === null ? (
            <p className="empty">Loading…</p>
          ) : (
            <div className="panelbody">
              <p className="problem">{problem}</p>
            </div>
          )}
        </div>
      </>
    )
  }

  const currency = claim.program?.currency ?? 'PHP'

  return (
    <>
      <div className="actions" style={{ marginTop: 0, marginBottom: 12 }}>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← Claims
        </button>
      </div>

      <div className="pagehead">
        <h1>{claim.claim_no}</h1>
        <ClaimStatus status={claim.status} />
      </div>
      <p className="subtle">
        {claim.patient === null ? (
          '—'
        ) : (
          <button type="button" className="btn-link inline" onClick={() => onOpenPatient(claim.patient!.public_id)}>
            {claim.patient.full_name}
          </button>
        )}{' '}
        · {claim.patient?.mrn ?? '—'} · {claim.program?.name ?? 'No program'} · service{' '}
        {claim.service_from === claim.service_to ? claim.service_from : `${claim.service_from} – ${claim.service_to}`}
      </p>

      {problem === null ? null : <p className="problem">{problem}</p>}
      {done === null ? null : <p className="good">{done}</p>}

      <div className="stats">
        <div className="stat">
          <span className="label">Claimed</span>
          <span className="value">{money(claim.amount_claimed, currency)}</span>
        </div>
        <div className="stat">
          <span className="label">Approved</span>
          <span className="value">{money(claim.amount_approved, currency)}</span>
        </div>
        <div className="stat">
          <span className="label">Received</span>
          <span className="value">{money(claim.amount_paid, currency)}</span>
        </div>
        <div className="stat">
          <span className="label">Still owed</span>
          <span
            className={`value ${claim.amount_outstanding !== null && claim.amount_outstanding !== '0.00' ? 'bad' : ''}`}
          >
            {money(claim.amount_outstanding, currency)}
          </span>
        </div>
        <div className="stat">
          <span className="label">Sessions</span>
          <span className="value">{claim.session_count}</span>
        </div>
      </div>

      {claim.status === 'denied' ? (
        <p className="problem">
          Denied{claim.denial_code === null ? '' : ` — code ${claim.denial_code}`}
          {claim.denial_reason === null ? '' : `: ${claim.denial_reason}`}. Appeals are not recorded in this
          system yet; keep the code for the follow-up.
        </p>
      ) : null}

      {claim.status === 'void' ? (
        <p className="notice">
          Void. Its sessions were released to be claimed again; what it covered is kept in the audit log.
        </p>
      ) : null}

      <div className="panel">
        <header>
          <h2>Next step</h2>
        </header>
        <div className="panelbody">
          {claim.next_statuses.length === 0 && !claim.accepts_remittance ? (
            <p className="muted" style={{ margin: 0 }}>
              {claim.status === 'paid'
                ? 'Paid in full. Nothing moves this claim further.'
                : claim.status === 'void'
                  ? 'A void claim is never reopened. Generate a new one if the sessions still need claiming.'
                  : 'Nothing further can be recorded against this claim here.'}
            </p>
          ) : (
            <div className="actions" style={{ marginTop: 0 }}>
              {claim.next_statuses.map((status) => (
                <button
                  key={status}
                  type="button"
                  className={STEPS[status]?.tone === 'danger' ? 'btn-danger' : status === 'submitted' ? 'btn-primary' : 'btn-quiet'}
                  disabled={busy}
                  onClick={() => void move(status)}
                >
                  {STEPS[status]?.label ?? status}
                </button>
              ))}
              {claim.accepts_remittance ? (
                <span className="muted">
                  {claim.next_statuses.length === 0
                    ? 'The payer has decided. The rest arrives with its next remittance advice — key that below.'
                    : 'Or, when the payer’s advice arrives, key it below.'}
                </span>
              ) : null}
            </div>
          )}

          <dl className="facts">
            <div>
              <dt>Submitted</dt>
              <dd>{stamp(claim.submitted_at)}</dd>
            </div>
            <div>
              <dt>Acknowledged</dt>
              <dd>{stamp(claim.acknowledged_at)}</dd>
            </div>
            <div>
              <dt>Paid in full</dt>
              <dd>{stamp(claim.paid_at)}</dd>
            </div>
            <div>
              <dt>Payer reference</dt>
              <dd>{claim.external_ref ?? '—'}</dd>
            </div>
            {claim.utilisation === null ? null : (
              <div>
                <dt>Allotment {claim.utilisation.period_start.slice(0, 4)}</dt>
                <dd>
                  {claim.utilisation.sessions_claimed} of {claim.utilisation.sessions_allotted} used,{' '}
                  {claim.utilisation.sessions_remaining} left
                </dd>
              </div>
            )}
          </dl>
        </div>
      </div>

      {claim.accepts_remittance ? (
        <RemittancePanel
          // Remounted whenever the money moves, so the form starts from the
          // totals the server now holds rather than from what was last typed.
          key={`${claim.status}-${claim.amount_approved ?? ''}-${claim.amount_paid ?? ''}`}
          claim={claim}
          currency={currency}
          onAttempt={() => setDone(null)}
          onRecorded={(updated) => {
            setClaim(updated)
            setProblem(null)
            setDone(`Remittance recorded. The claim is now ${claimStatusLabel(updated.status).toLowerCase()}.`)
          }}
        />
      ) : null}

      <div className="panel">
        <header>
          <h2>Sessions on this claim</h2>
        </header>
        {claim.sessions.length === 0 ? (
          <p className="empty">
            {claim.status === 'void' ? 'Released when the claim was voided.' : 'No sessions.'}
          </p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th className="num">Benefit no.</th>
                  <th>Treatment date</th>
                  <th>Modality</th>
                  <th className="num">Amount</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {claim.sessions.map((session) => (
                  <tr key={session.public_id}>
                    <td className="num">
                      {session.benefit_seq_no === null
                        ? '—'
                        : `${session.benefit_seq_no}${claim.utilisation === null ? '' : ` of ${claim.utilisation.sessions_allotted}`}`}
                    </td>
                    <td>{session.session_date}</td>
                    <td className="muted">{session.modality.toUpperCase()}</td>
                    <td className="num">{money(session.amount, currency)}</td>
                    <td>
                      <button type="button" className="btn-link" onClick={() => onOpenSession(session.public_id)}>
                        Open record
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="panel">
        <header>
          <h2>History</h2>
        </header>
        <div className="tablewrap">
          <table>
            <thead>
              <tr>
                <th>When</th>
                <th>Status</th>
                <th>By</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody>
              {claim.history.map((entry, index) => (
                <tr key={`${entry.changed_at}-${index}`}>
                  <td className="muted">{stamp(entry.changed_at)}</td>
                  <td>
                    <ClaimStatus status={entry.status} />
                  </td>
                  <td className="muted">{entry.changed_by ?? '—'}</td>
                  <td className="wrapcell">{entry.remarks ?? ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </>
  )
}

/**
 * Key the payer's remittance advice.
 *
 * Totals to date, not this payment alone: a follow-up advice restates what
 * has been approved and received in all. The server refuses a "paid to date"
 * lower than what it already holds, which is the usual sign of keying one
 * voucher on its own. The resulting status is the server's to decide.
 */
function RemittancePanel({
  claim,
  currency,
  onAttempt,
  onRecorded,
}: {
  claim: Claim
  currency: string
  /** Called as a submission starts, so an earlier success message is not left above a refusal. */
  onAttempt: () => void
  onRecorded: (claim: Claim) => void
}) {
  const confirmAction = useConfirm()

  const [form, setForm] = useState({
    amount_approved: claim.amount_approved ?? '',
    amount_paid: claim.amount_paid ?? '',
    denial_code: '',
    denial_reason: '',
    external_ref: claim.external_ref ?? '',
  })
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const set = (key: keyof typeof form, value: string) => setForm((current) => ({ ...current, [key]: value }))

  const amountsValid = isAmount(form.amount_approved) && isAmount(form.amount_paid)
  const zeroApproval = /^0+(\.0{1,2})?$/.test(form.amount_approved.trim())

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    onAttempt()

    if (!amountsValid) {
      setProblem('Enter both amounts as numbers, with up to two decimals — for example 6350.00.')

      return
    }

    const answer = await confirmAction({
      title: 'Record this remittance?',
      body:
        `Approved ${money(form.amount_approved.trim(), currency)}, received ${money(form.amount_paid.trim(), currency)} ` +
        `to date, against ${money(claim.amount_claimed, currency)} claimed. The claim’s status will follow from these amounts.`,
      confirmLabel: 'Record remittance',
    })

    if (!answer.confirmed) return

    const advice: RemittanceInput = {
      amount_approved: form.amount_approved.trim(),
      amount_paid: form.amount_paid.trim(),
    }

    // Empty means not given -- never an empty string the server would store.
    if (form.denial_code.trim() !== '') advice.denial_code = form.denial_code.trim()
    if (form.denial_reason.trim() !== '') advice.denial_reason = form.denial_reason.trim()
    if (form.external_ref.trim() !== '') advice.external_ref = form.external_ref.trim()

    setBusy(true)
    setProblem(null)

    try {
      onRecorded(await api.recordRemittance(claim.claim_no, advice))
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="panel">
      <header>
        <h2>Remittance advice</h2>
      </header>
      <form className="panelbody" onSubmit={(event) => void submit(event)}>
        {problem === null ? null : <p className="problem">{problem}</p>}

        <p className="muted" style={{ marginTop: 0 }}>
          Key the totals to date from the payer’s advice, not this payment alone. Approved at zero is a
          denial and needs the payer’s denial code.
        </p>

        <div className="fields">
          <div className="field">
            <label htmlFor="rm-approved">Approved (total)</label>
            <input
              id="rm-approved"
              inputMode="decimal"
              value={form.amount_approved}
              placeholder={claim.amount_claimed}
              onChange={(event) => set('amount_approved', event.target.value)}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="rm-paid">Received to date (total)</label>
            <input
              id="rm-paid"
              inputMode="decimal"
              value={form.amount_paid}
              onChange={(event) => set('amount_paid', event.target.value)}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="rm-ref">Payer’s reference</label>
            <input
              id="rm-ref"
              value={form.external_ref}
              maxLength={80}
              onChange={(event) => set('external_ref', event.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="rm-code">Denial code</label>
            <input
              id="rm-code"
              value={form.denial_code}
              maxLength={40}
              required={zeroApproval}
              onChange={(event) => set('denial_code', event.target.value)}
            />
          </div>
        </div>

        <div className="fields" style={{ marginTop: 12 }}>
          <div className="field">
            <label htmlFor="rm-reason">Denial or short-payment reason</label>
            <input
              id="rm-reason"
              value={form.denial_reason}
              maxLength={255}
              onChange={(event) => set('denial_reason', event.target.value)}
            />
          </div>
        </div>

        <div className="actions">
          <button type="submit" className="btn-primary" disabled={busy}>
            {busy ? 'Recording…' : 'Record remittance'}
          </button>
          <span className="muted">Claimed: {money(claim.amount_claimed, currency)}</span>
        </div>
      </form>
    </div>
  )
}
