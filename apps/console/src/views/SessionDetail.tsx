import type { SessionEvent, SessionVital, TreatmentSession } from '@dialysis/api-client'
import { KTV_TARGET, URR_TARGET_PCT } from '@dialysis/domain'
import { useConfirm } from '@dialysis/ui'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { Severity, StatusChip } from '../components/Chips'

/**
 * One treatment record, and the two signatures that close it.
 *
 * The flow sheet is read-only here: charting happens at the chair, and a desk
 * that could edit an observation after the fact is exactly the hole the
 * amendment path exists to close. What the console adds is the countersignature
 * — the nephrologist's half — and the amendment itself once it is locked.
 */

function clock(value: string | null): string {
  if (value === null) return '—'

  const date = new Date(value)

  return Number.isNaN(date.getTime())
    ? '—'
    : `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

/** Adequacy arrives as a decimal string; it must not round-trip through a float. */
function belowTarget(value: string | number | null, target: number): boolean {
  if (value === null) return false

  const parsed = Number(value)

  return Number.isFinite(parsed) && parsed < target
}

export function SessionDetail({
  publicId,
  onBack,
  backLabel = 'Board',
}: {
  publicId: string
  onBack: () => void
  /** Where the back button goes -- the board, or the claim or invoice this was opened from. */
  backLabel?: string
}) {
  const confirmAction = useConfirm()

  const [session, setSession] = useState<TreatmentSession | null>(null)
  const [vitals, setVitals] = useState<SessionVital[]>([])
  const [events, setEvents] = useState<SessionEvent[]>([])
  const [problem, setProblem] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setProblem(null)

    const [s, v, e] = await Promise.allSettled([
      api.session(publicId),
      api.vitals(publicId),
      api.events(publicId),
    ])

    if (s.status === 'fulfilled') setSession(s.value)
    else setProblem(explain(s.reason))

    if (v.status === 'fulfilled') setVitals(v.value)
    if (e.status === 'fulfilled') setEvents(e.value)
  }, [publicId])

  useEffect(() => {
    void load()
  }, [load])

  async function sign(as: 'nurse' | 'physician') {
    const answer = await confirmAction({
      title: as === 'nurse' ? 'Sign as the delivering nurse?' : 'Countersign this record?',
      body:
        as === 'nurse'
          ? 'You are attesting that the treatment happened as recorded.'
          : 'Once both signatures are in, the record locks and corrections go through the amendment path.',
      confirmLabel: as === 'nurse' ? 'Sign' : 'Countersign',
    })

    if (!answer.confirmed) return

    setBusy(true)
    setProblem(null)
    setDone(null)

    try {
      const updated = as === 'nurse' ? await api.signAsNurse(publicId) : await api.signAsPhysician(publicId)
      setSession(updated)
      setDone(updated.is_locked ? 'Both signatures in. The record is locked.' : 'Signed.')
    } catch (error) {
      // A refusal here is the system working: an incomplete record cannot be
      // attested to, and the message names the fields that are missing.
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  if (session === null) {
    return (
      <>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← {backLabel}
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

  return (
    <>
      <div className="actions" style={{ marginTop: 0, marginBottom: 12 }}>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← {backLabel}
        </button>
      </div>

      <div className="pagehead">
        <h1>{session.patient.full_name ?? 'Session'}</h1>
        <StatusChip status={session.status} />
        {session.is_locked ? <span className="chip chip-danger">Locked</span> : null}
      </div>
      <p className="subtle">
        {session.patient.mrn ?? '—'} · {session.session_date} · {session.modality}
      </p>

      {problem === null ? null : <p className="problem">{problem}</p>}
      {done === null ? null : <p className="good">{done}</p>}

      <div className="stats">
        <div className="stat">
          <span className="label">On / off</span>
          <span className="value">
            {clock(session.started_at)} – {clock(session.ended_at)}
          </span>
        </div>
        <div className="stat">
          <span className="label">Duration</span>
          <span className="value">
            {session.actual_duration_min === null ? '—' : `${session.actual_duration_min} min`}
          </span>
        </div>
        <div className="stat">
          <span className="label">Pre / post</span>
          <span className="value">
            {session.pre_weight_kg ?? '—'} / {session.post_weight_kg ?? '—'}
          </span>
        </div>
        <div className="stat">
          <span className="label">IDWG</span>
          <span className="value">{session.idwg_kg === null ? '—' : `${session.idwg_kg} kg`}</span>
        </div>
        <div className="stat">
          <span className="label">Net UF</span>
          <span className="value">{session.net_uf_ml === null ? '—' : `${session.net_uf_ml} ml`}</span>
        </div>
        <div className="stat">
          <span className="label">Kt/V</span>
          <span className={`value ${belowTarget(session.ktv, KTV_TARGET) ? 'bad' : ''}`}>
            {session.ktv ?? '—'}
          </span>
        </div>
        <div className="stat">
          <span className="label">URR</span>
          <span className={`value ${belowTarget(session.urr_pct, URR_TARGET_PCT) ? 'bad' : ''}`}>
            {session.urr_pct === null ? '—' : `${session.urr_pct}%`}
          </span>
        </div>
      </div>

      {belowTarget(session.ktv, KTV_TARGET) || belowTarget(session.urr_pct, URR_TARGET_PCT) ? (
        <p className="notice">
          Adequacy is below target for this session (Kt/V {KTV_TARGET}, URR {URR_TARGET_PCT}%). One
          session is not a trend — worth a prescription review if it repeats.
        </p>
      ) : null}

      <div className="panel">
        <header>
          <h2>Signatures</h2>
        </header>
        <div className="panelbody">
          <div className="fields">
            <div className="field">
              <label>Nurse</label>
              <span>{session.nurse_signed_at ?? <span className="muted">not signed</span>}</span>
            </div>
            <div className="field">
              <label>Nephrologist</label>
              <span>{session.physician_signed_at ?? <span className="muted">not signed</span>}</span>
            </div>
            <div className="field">
              <label>Locked</label>
              <span>{session.locked_at ?? <span className="muted">open</span>}</span>
            </div>
          </div>

          {session.is_locked ? (
            <p className="notice" style={{ marginTop: 14, marginBottom: 0 }}>
              Signed and locked. Only a nephrologist can amend it, with a reason, and the original
              values stay on the record.
            </p>
          ) : (
            <div className="actions">
              <button
                type="button"
                className="btn-quiet"
                disabled={busy || session.nurse_signed_at !== null}
                onClick={() => void sign('nurse')}
              >
                Sign as nurse
              </button>
              <button
                type="button"
                className="btn-primary"
                disabled={busy || session.physician_signed_at !== null}
                onClick={() => void sign('physician')}
              >
                Countersign as nephrologist
              </button>
              <span className="muted">One person cannot supply both.</span>
            </div>
          )}
        </div>
      </div>

      <div className="panel">
        <header>
          <h2>Flow sheet</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            Read-only. Charting happens at the chair.
          </span>
        </header>
        {vitals.length === 0 ? (
          <p className="empty">Nothing charted.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Time</th>
                  <th className="num">Min</th>
                  <th className="num">BP</th>
                  <th className="num">MAP</th>
                  <th className="num">Pulse</th>
                  <th className="num">Temp</th>
                  <th className="num">SpO₂</th>
                  <th>Source</th>
                  <th>Comment</th>
                </tr>
              </thead>
              <tbody>
                {vitals.map((vital, index) => (
                  <tr key={`${vital.recorded_at}-${index}`}>
                    <td>{clock(vital.recorded_at)}</td>
                    <td className="num">{vital.minutes_elapsed ?? '—'}</td>
                    <td className="num">
                      {vital.bp_sys === null && vital.bp_dia === null
                        ? '—'
                        : `${vital.bp_sys ?? ''}/${vital.bp_dia ?? ''}`}
                    </td>
                    <td className="num">{vital.map_mmhg ?? '—'}</td>
                    <td className="num">{vital.pulse ?? '—'}</td>
                    <td className="num">{vital.temp_c ?? '—'}</td>
                    <td className="num">{vital.spo2_pct ?? '—'}</td>
                    <td className="muted">{vital.source}</td>
                    <td className="wrapcell muted">{vital.comment ?? ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="panel">
        <header>
          <h2>Events</h2>
        </header>
        {events.length === 0 ? (
          <p className="empty">No events this run.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Event</th>
                  <th>Severity</th>
                  <th>What happened</th>
                  <th>What was done</th>
                </tr>
              </thead>
              <tbody>
                {events.map((event, index) => (
                  <tr key={`${event.occurred_at}-${index}`}>
                    <td>{clock(event.occurred_at)}</td>
                    <td>{event.event_code.replace(/_/g, ' ')}</td>
                    <td>
                      <Severity value={event.severity} />
                    </td>
                    <td className="wrapcell muted">{event.description ?? '—'}</td>
                    <td className="wrapcell muted">{event.intervention ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </>
  )
}
