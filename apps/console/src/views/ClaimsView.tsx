import type { ClaimableGroup, Claimable, ClaimPage, ClaimsOutstanding, Patient } from '@dialysis/api-client'
import { useConfirm } from '@dialysis/ui'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { ClaimStatus } from '../components/Chips'
import { money } from '../components/format'
import { PatientPicker } from '../components/PatientPicker'

/**
 * Claims against a payer benefit.
 *
 * The totals at the top are the server's, summed in bcmath off the claims
 * table: nothing on this screen adds money up. "Outstanding" is measured
 * against what the payer approved, not what was asked for -- the gap between
 * claimed and approved is a denial to follow up, not a debt to chase.
 */

const STATUSES = [
  'draft', 'ready', 'submitted', 'acknowledged', 'in_process', 'returned',
  'resubmitted', 'approved', 'partially_paid', 'paid', 'denied', 'void',
]

export function ClaimsView({ onOpen }: { onOpen: (claimNo: string) => void }) {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState<ClaimPage | null>(null)
  const [totals, setTotals] = useState<ClaimsOutstanding | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async (term: string, only: string, at = 1) => {
    setProblem(null)

    try {
      const [list, owed] = await Promise.all([
        api.claims({ q: term || undefined, status: only || undefined, page: at }),
        api.claimsOutstanding(),
      ])
      setPage(list)
      setTotals(owed)
    } catch (error) {
      setProblem(explain(error))
    }
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(search, status), 250)

    return () => window.clearTimeout(timer)
  }, [search, status, load])

  const rows = page?.data ?? []
  const meta = page?.meta

  return (
    <>
      <div className="pagehead">
        <h1>Claims</h1>
      </div>
      <p className="subtle">
        What the unit has asked payers for, and what they decided. Rates and allotments come from the
        benefit program in force on each treatment's date — never from a number typed here.
      </p>

      <div className="stats">
        <div className="stat">
          <span className="label">Claimed</span>
          <span className="value">{money(totals?.claimed)}</span>
        </div>
        <div className="stat">
          <span className="label">Approved</span>
          <span className="value">{money(totals?.approved)}</span>
        </div>
        <div className="stat">
          <span className="label">Received</span>
          <span className="value">{money(totals?.paid)}</span>
        </div>
        <div className="stat">
          <span className="label">Owed by payers</span>
          <span className={`value ${totals !== null && totals.outstanding !== '0.00' ? 'bad' : ''}`}>
            {money(totals?.outstanding)}
          </span>
        </div>
      </div>

      {creating ? (
        <NewClaimPanel
          onCreated={(claimNo) => {
            setCreating(false)
            onOpen(claimNo)
          }}
          onCancel={() => setCreating(false)}
        />
      ) : null}

      <div className="panel">
        <header>
          <div className="field">
            <label htmlFor="cl-search">Search</label>
            <input
              id="cl-search"
              value={search}
              placeholder="Claim no, name or MRN"
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="cl-status">Status</label>
            <select id="cl-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">All</option>
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {value.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
          </div>
          <span className="grow" />
          {creating ? null : (
            <button type="button" className="btn-primary" onClick={() => setCreating(true)}>
              New claim
            </button>
          )}
        </header>

        {problem === null ? null : (
          <div className="panelbody">
            <p className="problem">{problem}</p>
          </div>
        )}

        {rows.length === 0 ? (
          <p className="empty">{page === null ? 'Loading…' : 'No claims match that.'}</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Claim</th>
                  <th>Status</th>
                  <th>Patient</th>
                  <th>Service</th>
                  <th className="num">Sessions</th>
                  <th className="num">Claimed</th>
                  <th className="num">Approved</th>
                  <th className="num">Received</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((claim) => (
                  <tr key={claim.claim_no} className="clickable" onClick={() => onOpen(claim.claim_no)}>
                    <td>
                      <strong>{claim.claim_no}</strong>
                    </td>
                    <td>
                      <ClaimStatus status={claim.status} />
                    </td>
                    <td>
                      {claim.patient.full_name}
                      <div className="muted small">{claim.patient.mrn}</div>
                    </td>
                    <td className="muted">
                      {claim.service_from === claim.service_to
                        ? claim.service_from
                        : `${claim.service_from} – ${claim.service_to}`}
                    </td>
                    <td className="num">{claim.session_count}</td>
                    <td className="num">{money(claim.amount_claimed)}</td>
                    <td className="num">{money(claim.amount_approved)}</td>
                    <td className="num">{money(claim.amount_paid)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta === undefined || meta.last_page <= 1 ? null : (
          <div className="panelbody pager">
            <button
              type="button"
              className="btn-quiet"
              disabled={meta.current_page <= 1}
              onClick={() => void load(search, status, meta.current_page - 1)}
            >
              Previous
            </button>
            <span className="muted">
              Page {meta.current_page} of {meta.last_page} · {meta.total} claims
            </span>
            <button
              type="button"
              className="btn-quiet"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => void load(search, status, meta.current_page + 1)}
            >
              Next
            </button>
          </div>
        )}
      </div>
    </>
  )
}

/**
 * Build a claim from a patient's unclaimed, signed sessions.
 *
 * Sessions arrive grouped by benefit program and period, and a claim is built
 * from one group at a time: the server refuses a claim that crosses either,
 * because it would be priced, counted and numbered against the wrong one.
 * Nothing here prices anything -- the claim comes back from the server with
 * its amount, and it starts as a draft that can still be voided.
 */
function NewClaimPanel({
  onCreated,
  onCancel,
}: {
  onCreated: (claimNo: string) => void
  onCancel: () => void
}) {
  const confirmAction = useConfirm()

  const [patient, setPatient] = useState<Patient | null>(null)
  const [claimable, setClaimable] = useState<Claimable | null>(null)
  const [groupIndex, setGroupIndex] = useState(0)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    setClaimable(null)
    setProblem(null)

    if (patient === null) return

    let current = true

    api
      .claimable(patient.public_id)
      .then((found) => {
        if (!current) return
        setClaimable(found)
        setGroupIndex(0)
        setSelected(new Set(found.groups[0]?.sessions.map((s) => s.public_id) ?? []))
      })
      .catch((error: unknown) => {
        if (current) setProblem(explain(error))
      })

    return () => {
      current = false
    }
  }, [patient])

  const group: ClaimableGroup | undefined = claimable?.groups[groupIndex]

  function chooseGroup(index: number) {
    setGroupIndex(index)
    setSelected(new Set(claimable?.groups[index]?.sessions.map((s) => s.public_id) ?? []))
  }

  function toggle(publicId: string) {
    setSelected((current) => {
      const next = new Set(current)

      if (next.has(publicId)) next.delete(publicId)
      else next.add(publicId)

      return next
    })
  }

  async function generate() {
    if (patient === null || group === undefined || selected.size === 0) return

    const answer = await confirmAction({
      title: `Generate a claim for ${selected.size} session${selected.size === 1 ? '' : 's'}?`,
      body:
        `${patient.full_name}, under ${group.program.code}. The server prices it from that program and ` +
        'numbers each session within the period. It starts as a draft, and a draft can still be voided.',
      confirmLabel: 'Generate claim',
    })

    if (!answer.confirmed) return

    setBusy(true)
    setProblem(null)

    try {
      const claim = await api.generateClaim(
        patient.public_id,
        group.sessions.filter((s) => selected.has(s.public_id)).map((s) => s.public_id),
      )
      onCreated(claim.claim_no)
    } catch (error) {
      // An allotment overrun, an unsigned record, a session claimed a moment
      // ago by someone else: the server's sentence names which.
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  const overAllotment = group !== undefined && selected.size > group.utilisation.sessions_remaining

  return (
    <div className="panel">
      <header>
        <h2>New claim</h2>
      </header>
      <div className="panelbody">
        {problem === null ? null : <p className="problem">{problem}</p>}

        <div className="fields">
          <div className="field">
            <label htmlFor="nc-patient">Patient</label>
            <PatientPicker id="nc-patient" chosen={patient} onChoose={setPatient} />
          </div>
        </div>

        {patient === null ? null : claimable === null ? (
          problem === null ? <p className="empty">Looking for unclaimed sessions…</p> : null
        ) : (
          <>
            {claimable.groups.length === 0 ? (
              <p className="empty">
                Nothing to claim. Only completed, signed, billable sessions not already on a claim appear
                here.
              </p>
            ) : null}

            {claimable.groups.length > 1 ? (
              <div className="grouptabs" role="tablist" aria-label="Benefit period">
                {claimable.groups.map((each, index) => (
                  <button
                    key={`${each.program.code}-${each.period_start}`}
                    type="button"
                    role="tab"
                    aria-selected={index === groupIndex}
                    className={index === groupIndex ? 'btn-primary' : 'btn-quiet'}
                    onClick={() => chooseGroup(index)}
                  >
                    {each.program.code} · {each.period_start.slice(0, 4)}
                    {each.program.period_kind === 'month' ? `-${each.period_start.slice(5, 7)}` : ''} ·{' '}
                    {each.sessions.length}
                  </button>
                ))}
              </div>
            ) : null}

            {group === undefined ? null : (
              <>
                {claimable.groups.length > 1 ? (
                  <p className="notice">
                    These sessions fall in {claimable.groups.length} benefit periods or programs. A claim covers
                    one — each has its own allotment and numbering — so generate one per tab.
                  </p>
                ) : null}

                <div className="fields programfacts">
                  <div className="field">
                    <label>Program</label>
                    <span>
                      {group.program.name} <span className="muted">({group.program.code})</span>
                    </span>
                  </div>
                  <div className="field">
                    <label>Rate per session</label>
                    <span>{money(group.program.case_rate === null ? null : String(group.program.case_rate))}</span>
                  </div>
                  <div className="field">
                    <label>Benefit period</label>
                    <span>
                      {group.period_start} – {group.period_end}
                    </span>
                  </div>
                  <div className="field">
                    <label>Allotment</label>
                    <span>
                      {group.utilisation.sessions_claimed} of {group.utilisation.sessions_allotted} used ·{' '}
                      <strong>{group.utilisation.sessions_remaining} left</strong>
                    </span>
                  </div>
                  <div className="field">
                    <label>Source</label>
                    <span className="muted">{group.program.circular_ref ?? 'No circular recorded'}</span>
                  </div>
                </div>

                <div className="tablewrap" style={{ marginTop: 12 }}>
                  <table>
                    <thead>
                      <tr>
                        <th className="checkcell">
                          <input
                            type="checkbox"
                            aria-label="Select all sessions in this period"
                            checked={selected.size === group.sessions.length}
                            onChange={(event) =>
                              setSelected(
                                new Set(event.target.checked ? group.sessions.map((s) => s.public_id) : []),
                              )
                            }
                          />
                        </th>
                        <th>Treatment date</th>
                        <th>Modality</th>
                      </tr>
                    </thead>
                    <tbody>
                      {group.sessions.map((session) => (
                        <tr key={session.public_id}>
                          <td className="checkcell">
                            <input
                              type="checkbox"
                              aria-label={`Claim the session on ${session.session_date}`}
                              checked={selected.has(session.public_id)}
                              onChange={() => toggle(session.public_id)}
                            />
                          </td>
                          <td>{session.session_date}</td>
                          <td className="muted">{session.modality.toUpperCase()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {overAllotment ? (
                  <p className="notice" style={{ marginTop: 12 }}>
                    {selected.size} selected, but only {group.utilisation.sessions_remaining} remain in this
                    period. The server will refuse the claim; select fewer.
                  </p>
                ) : null}
              </>
            )}

            {claimable.unclaimable.length === 0 ? null : (
              <div className="unclaimable">
                <h3>Cannot be claimed</h3>
                <ul>
                  {claimable.unclaimable.map((session) => (
                    <li key={session.public_id}>
                      <strong>{session.session_date}</strong> <span className="muted">{session.reason}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        )}

        <div className="actions">
          <button
            type="button"
            className="btn-primary"
            disabled={busy || group === undefined || selected.size === 0}
            onClick={() => void generate()}
          >
            {busy
              ? 'Generating…'
              : group === undefined
                ? 'Generate claim'
                : `Generate claim for ${selected.size} session${selected.size === 1 ? '' : 's'}`}
          </button>
          <button type="button" className="btn-quiet" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  )
}
