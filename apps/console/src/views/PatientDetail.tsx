import type {
  DryWeights,
  Patient,
  Prescriptions,
  SerologyResult,
  StandingSchedule,
} from '@dialysis/api-client'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { Cohort, PatientStatus } from '../components/Chips'
import { LabsPanel } from './LabsPanel'

/**
 * One patient's chart.
 *
 * Almost everything here is a history rather than a field, and that shape is the
 * point. Dry weight, serology, prescription and standing pattern are all
 * effective-dated: writing a new one closes the last, and past sessions keep the
 * values that were in force on the day they ran. A screen that let you edit
 * "the" dry weight would quietly rewrite every fluid figure behind it.
 */

/** weekday_mask bit 0 = Monday. 63 = Mon–Sat, 21 = Mon/Wed/Fri. */
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

function weekdays(mask: number): string {
  const days = DAYS.filter((_, index) => (mask & (1 << index)) !== 0)

  return days.length === 0 ? '—' : days.join('/')
}

export function PatientDetail({
  publicId,
  onBack,
  backLabel = 'Patients',
}: {
  publicId: string
  onBack: () => void
  /** Where the back button goes -- the registry, or the claim or invoice this was opened from. */
  backLabel?: string
}) {
  const [patient, setPatient] = useState<Patient | null>(null)
  const [serology, setSerology] = useState<SerologyResult[]>([])
  const [weights, setWeights] = useState<DryWeights | null>(null)
  const [scripts, setScripts] = useState<Prescriptions | null>(null)
  const [schedule, setSchedule] = useState<StandingSchedule | null>(null)
  const [problem, setProblem] = useState<string | null>(null)

  const load = useCallback(async () => {
    setProblem(null)

    try {
      // Independent reads; one failing should not blank the whole chart, so
      // each is settled on its own and whatever loaded still renders.
      const [p, s, w, r, sch] = await Promise.allSettled([
        api.patient(publicId),
        api.serology(publicId),
        api.dryWeights(publicId),
        api.prescriptions(publicId),
        api.standingSchedule(publicId),
      ])

      if (p.status === 'fulfilled') setPatient(p.value)
      else setProblem(explain(p.reason))

      if (s.status === 'fulfilled') setSerology(s.value)
      if (w.status === 'fulfilled') setWeights(w.value)
      if (r.status === 'fulfilled') setScripts(r.value)
      if (sch.status === 'fulfilled') setSchedule(sch.value)
    } catch (error) {
      setProblem(explain(error))
    }
  }, [publicId])

  useEffect(() => {
    void load()
  }, [load])

  if (patient === null) {
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

  const current = scripts?.current as Record<string, unknown> | null | undefined

  return (
    <>
      <div className="actions" style={{ marginTop: 0, marginBottom: 12 }}>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← {backLabel}
        </button>
      </div>

      <div className="pagehead">
        <h1>{patient.full_name}</h1>
        <Cohort value={patient.cohort} />
        <PatientStatus status={patient.status} />
      </div>
      <p className="subtle">
        {patient.mrn} · born {patient.birth_date} · {patient.sex}
        {patient.first_dialysis_date === null ? '' : ` · first dialysed ${patient.first_dialysis_date}`}
      </p>

      {problem === null ? null : <p className="problem">{problem}</p>}

      <div className="stats">
        <div className="stat">
          <span className="label">Dry weight</span>
          <span className="value">{weights?.current_kg === null || weights === null ? '—' : `${weights.current_kg} kg`}</span>
        </div>
        <div className="stat">
          <span className="label">Modality</span>
          <span className="value">{String(current?.modality ?? '—')}</span>
        </div>
        <div className="stat">
          <span className="label">Duration</span>
          <span className="value">
            {current?.duration_min === undefined || current?.duration_min === null
              ? '—'
              : `${String(current.duration_min)} min`}
          </span>
        </div>
        <div className="stat">
          <span className="label">Per week</span>
          <span className="value">{String(current?.sessions_per_week ?? '—')}</span>
        </div>
      </div>

      {/* ---- cohort provenance ------------------------------------------- */}
      <div className="panel">
        <header>
          <h2>Serology</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            The cohort is derived from these, not set by hand.
          </span>
        </header>
        {serology.length === 0 ? (
          <p className="empty">
            No results on file. The patient is treated as clean until one says otherwise.
          </p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Marker</th>
                  <th>Result</th>
                  <th>Specimen</th>
                  <th>Resulted</th>
                  <th>Lab</th>
                </tr>
              </thead>
              <tbody>
                {serology.map((row, index) => (
                  <tr key={`${row.marker}-${index}`}>
                    <td>{row.marker.replace(/_/g, ' ').toUpperCase()}</td>
                    <td>
                      <span className={row.result === 'reactive' ? 'chip chip-danger' : 'chip chip-ok'}>
                        {row.result}
                      </span>
                    </td>
                    <td className="muted">{row.specimen_date}</td>
                    <td className="muted">{row.resulted_on ?? '—'}</td>
                    <td className="muted">{row.lab_name ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ---- dry weight -------------------------------------------------- */}
      <DryWeightPanel publicId={publicId} weights={weights} onChanged={() => void load()} />

      {/* ---- bloods ------------------------------------------------------ */}
      <LabsPanel publicId={publicId} />

      {/* ---- prescription ------------------------------------------------ */}
      <div className="panel">
        <header>
          <h2>Prescription</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            Exactly one is active at a time.
          </span>
        </header>
        {scripts === null || scripts.versions.length === 0 ? (
          <p className="empty">No prescription on file.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th className="num">v</th>
                  <th>Modality</th>
                  <th className="num">Duration</th>
                  <th className="num">Per week</th>
                  <th className="num">Blood flow</th>
                  <th>Anticoagulant</th>
                  <th>From</th>
                  <th>To</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                {scripts.versions.map((version) => (
                  <tr key={version.version}>
                    <td className="num">
                      <strong>{version.version}</strong>
                    </td>
                    <td>{version.modality}</td>
                    <td className="num">{version.duration_min ?? '—'}</td>
                    <td className="num">{version.sessions_per_week ?? '—'}</td>
                    <td className="num">{version.blood_flow_ml_min ?? '—'}</td>
                    <td className="muted">{version.anticoagulant ?? '—'}</td>
                    <td className="muted">{version.effective_from}</td>
                    <td className="muted">
                      {version.effective_to ?? <span className="chip chip-ok">current</span>}
                    </td>
                    <td className="wrapcell muted">{version.change_reason ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ---- standing pattern -------------------------------------------- */}
      <div className="panel">
        <header>
          <h2>Standing pattern</h2>
        </header>
        {schedule === null || schedule.history.length === 0 ? (
          <p className="empty">
            No standing pattern. This patient will not appear when a board is generated.
          </p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Days</th>
                  <th>Shift</th>
                  <th>Chair</th>
                  <th>From</th>
                  <th>To</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                {schedule.history.map((row, index) => (
                  <tr key={`${row.effective_from}-${index}`}>
                    <td>
                      <strong>{weekdays(row.weekday_mask)}</strong>
                    </td>
                    <td>{row.shift_code ?? '—'}</td>
                    <td>{row.station_code ?? '—'}</td>
                    <td className="muted">{row.effective_from}</td>
                    <td className="muted">
                      {row.effective_to ?? <span className="chip chip-ok">current</span>}
                    </td>
                    <td className="wrapcell muted">{row.notes ?? '—'}</td>
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

function DryWeightPanel({
  publicId,
  weights,
  onChanged,
}: {
  publicId: string
  weights: DryWeights | null
  onChanged: () => void
}) {
  const [open, setOpen] = useState(false)
  const [weight, setWeight] = useState('')
  const [from, setFrom] = useState('')
  const [reason, setReason] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)
    setBusy(true)

    try {
      await api.setDryWeight(publicId, weight, from, reason === '' ? undefined : reason)
      setOpen(false)
      setWeight('')
      setReason('')
      onChanged()
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="panel">
      <header>
        <h2>Dry weight</h2>
        <span className="grow" />
        {open ? null : (
          <button type="button" className="btn-quiet" onClick={() => setOpen(true)}>
            Set a new dry weight
          </button>
        )}
      </header>

      {open ? (
        <form className="panelbody" onSubmit={(event) => void submit(event)}>
          {problem === null ? null : <p className="problem">{problem}</p>}
          <div className="fields">
            <div className="field">
              <label htmlFor="dw-kg">Weight (kg)</label>
              <input id="dw-kg" inputMode="decimal" value={weight} onChange={(e) => setWeight(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="dw-from">Effective from</label>
              <input id="dw-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="dw-reason">Reason</label>
              <input id="dw-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Why it changed" />
            </div>
          </div>
          <div className="actions">
            <button type="submit" className="btn-primary" disabled={busy}>
              {busy ? 'Saving…' : 'Set dry weight'}
            </button>
            <button type="button" className="btn-quiet" onClick={() => setOpen(false)}>
              Cancel
            </button>
            <span className="muted">
              This starts a new effective-dated entry. Past sessions keep the weight they ran against.
            </span>
          </div>
        </form>
      ) : null}

      {weights === null || weights.history.length === 0 ? (
        <p className="empty">No dry weight recorded. Check-in cannot compute a UF goal without one.</p>
      ) : (
        <div className="tablewrap">
          <table>
            <thead>
              <tr>
                <th className="num">Weight</th>
                <th>Effective from</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              {weights.history.map((row, index) => (
                <tr key={`${row.effective_from}-${index}`}>
                  <td className="num">
                    <strong>{row.weight_kg} kg</strong>
                  </td>
                  <td className="muted">{row.effective_from}</td>
                  <td className="wrapcell muted">{row.reason ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
