import {
  cohortRefusal,
  type AvailableStations,
  type CohortRefusal,
  type DialyzerList,
  type LifecycleResult,
  type Machine,
  type TreatmentSession,
} from '@dialysis/api-client'
import { idwgKg, KTV_TARGET, ktv, URR_TARGET_PCT, urrPct } from '@dialysis/domain'
import { useConfirm } from '@dialysis/ui'
import { useEffect, useState, type ReactNode } from 'react'

import { api, explain, reauthIfExpired } from '../api'
import { token } from '../auth'
import { db, type CachedSession } from '../db'
import { parseServerTime } from '../flowsheet'
import { bootstrap } from '../sync'

/**
 * Check in, start, end.
 *
 * These three steps are the only part of the bedside app that will not work
 * offline, and that is the point of them. Check-in and start are where the
 * system refuses a chair the patient's cohort may not use, a machine that last
 * treated another cohort and was never cleaned, a condemned or borrowed
 * dialyzer, and a day whose water check failed. Each of those refusals is only
 * worth anything before the needle goes in. Queued in the outbox, they would
 * arrive at sync time -- hours after the patient had been dialysed.
 *
 * So these call the server directly and wait for its verdict. Charting, once the
 * treatment is running, goes back to working with no signal at all.
 */

type Outcome = {
  message: string
  overrides: string[]
  warnings: string[]
  adequacy?: { ktv: string | null; urr: string | null }
}

function useOnline(): boolean {
  const [online, setOnline] = useState(navigator.onLine)

  useEffect(() => {
    const up = () => setOnline(true)
    const down = () => setOnline(false)
    window.addEventListener('online', up)
    window.addEventListener('offline', down)

    return () => {
      window.removeEventListener('online', up)
      window.removeEventListener('offline', down)
    }
  }, [])

  return online
}

/**
 * An optional form field, as the API should receive it.
 *
 * Empty becomes undefined -- which JSON drops -- and never null. Delivered
 * settings and samples may already have been charted offline through the outbox;
 * sending null for a field the nurse left blank here would overwrite that value,
 * and a wiped BUN is an adequacy the server can no longer calculate.
 */
function opt(value: string): number | undefined {
  return value.trim() === '' ? undefined : Number(value)
}

function optText(value: string): string | undefined {
  return value.trim() === '' ? undefined : value.trim()
}

function toNumber(value: string | number | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null

  const n = Number(value)

  return Number.isFinite(n) ? n : null
}

/**
 * Bring the local copy in line with what the server just answered.
 *
 * The cache is a read-only projection of the server, so it is written from the
 * server's own response, never from what the form sent. The step's result
 * updates the fields the response carries straight away -- so the screen
 * advances even if the connection drops a moment later -- and a bootstrap then
 * refreshes the chair and machine, which the session resource does not include.
 */
async function reflect(session: CachedSession, result: TreatmentSession): Promise<void> {
  await db.sessions.update(session.publicId, {
    status: result.status,
    startedAt: result.started_at,
    endedAt: result.ended_at,
    lockedAt: result.locked_at,
    preWeightKg: toNumber(result.pre_weight_kg),
    dryWeightKg: toNumber(result.dry_weight_kg),
    plannedDurationMin: result.planned_duration_min,
    plannedUfMl: result.planned_uf_ml,
  })

  const current = token()

  if (current !== null) {
    // A failed refresh is not a failed step: the step already succeeded on the
    // server and the fields that matter are already reflected above.
    await bootstrap(session.sessionDate, current).catch(() => undefined)
  }
}

export function Lifecycle({ session }: { session: CachedSession }) {
  const online = useOnline()
  const [outcome, setOutcome] = useState<Outcome | null>(null)

  if (session.lockedAt !== null) return null

  // Kept here rather than in each step, so what the last step reported survives
  // the move to the next one -- an override recorded at check-in must still be
  // on screen when the start form appears.
  const report = (next: Outcome) => setOutcome(next)

  return (
    <>
      {outcome === null ? null : <OutcomeBanner outcome={outcome} onDismiss={() => setOutcome(null)} />}

      {!online && ['scheduled', 'checked_in', 'in_progress'].includes(session.status) ? (
        <p className="notice">
          No connection. Checking in, starting and ending a treatment need one, so the water, chair,
          machine and dialyzer checks can run before anything happens to the patient. Charting a
          running treatment works offline.
        </p>
      ) : null}

      {session.status === 'scheduled' ? (
        <CheckInStep key="checkin" session={session} online={online} onDone={report} />
      ) : null}

      {session.status === 'checked_in' ? (
        <StartStep key="start" session={session} online={online} onDone={report} />
      ) : null}

      {session.status === 'in_progress' ? (
        <EndStep key="end" session={session} online={online} onDone={report} />
      ) : null}

      {session.status === 'completed' || session.status === 'aborted' ? (
        <div className="section">
          <h3>Treatment ended</h3>
          <div className="form">
            <p className="hint" style={{ margin: 0 }}>
              {session.status === 'aborted'
                ? 'Stopped early and recorded as aborted, so it does not count as a full treatment in the adequacy figures.'
                : 'Completed as prescribed.'}{' '}
              It is signed by the nurse and countersigned by the nephrologist at the console; once
              both signatures are in, the record locks.
            </p>
          </div>
        </div>
      ) : null}
    </>
  )
}

function OutcomeBanner({ outcome, onDismiss }: { outcome: Outcome; onDismiss: () => void }) {
  return (
    <div className="section">
      <div className="form">
        <p className="good" style={{ margin: 0 }}>{outcome.message}</p>

        {outcome.overrides.length === 0 ? null : (
          // Echoed by the server precisely so a screen cannot succeed quietly.
          <div className="override-banner">
            <strong>Infection-control override recorded on the chart.</strong>
            <ul>
              {outcome.overrides.map((breach) => <li key={breach}>{breach}</li>)}
            </ul>
          </div>
        )}

        {outcome.warnings.map((warning) => (
          <p className="notice" key={warning} style={{ margin: 0 }}>{warning}</p>
        ))}

        {outcome.adequacy === undefined ? null : (
          <p className="hint" style={{ margin: 0 }}>
            Calculated by the server from the samples: Kt/V {outcome.adequacy.ktv ?? '—'} · URR{' '}
            {outcome.adequacy.urr === null ? '—' : `${outcome.adequacy.urr}%`}
          </p>
        )}

        <div className="actions">
          <button type="button" className="btn-quiet" onClick={onDismiss}>Dismiss</button>
        </div>
      </div>
    </div>
  )
}

/**
 * The shared shape of check-in and start: submit, and if infection control
 * refuses, show the breach and make proceeding a deliberate second step.
 *
 * The override is deliberately NOT offered in a dialog that opens by itself.
 * Most of the time the right answer to "this chair is not designated for the
 * HBV cohort" is to move the patient, and a dialog that pops up asking for a
 * reason makes overriding the path of least resistance.
 */
function useGuardedSubmit(onSuccess: (result: LifecycleResult) => Promise<void>, advice: string) {
  const confirmAction = useConfirm()
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [refusal, setRefusal] = useState<CohortRefusal | null>(null)

  async function attempt(call: (overrideReason?: string) => Promise<LifecycleResult>, overrideReason?: string) {
    setBusy(true)
    setProblem(null)

    try {
      const result = await call(overrideReason)
      setRefusal(null)
      await onSuccess(result)
    } catch (error) {
      if (reauthIfExpired(error)) return

      const cohort = cohortRefusal(error)

      if (cohort !== null && overrideReason === undefined) {
        setRefusal(cohort)
      } else {
        setProblem(explain(error))
      }
    } finally {
      setBusy(false)
    }
  }

  async function override(call: (overrideReason?: string) => Promise<LifecycleResult>) {
    if (refusal === null) return

    const answer = await confirmAction({
      title: 'Proceed past infection control?',
      body:
        'Only when there is genuinely no compliant chair or machine and the treatment cannot wait. ' +
        'The override and your reason are written onto this patient\'s chart as an ' +
        'INFECTION CONTROL OVERRIDE, where a reviewing clinician will see them.',
      confirmLabel: 'Record override and proceed',
      cancelLabel: 'Go back',
      tone: 'danger',
      // Mirrors cohort_override_reason's min:10 on the server, which still
      // enforces it. The default destination -- the patient's record -- is
      // exactly where this one lands.
      reason: { label: 'Why is this override necessary?', minLength: 10 },
    })

    if (!answer.confirmed || answer.reason === null) return

    await attempt(call, answer.reason)
  }

  const refusalPanel = (call: (overrideReason?: string) => Promise<LifecycleResult>): ReactNode =>
    refusal === null ? null : (
      <div className="refusal">
        <strong>Infection control refused this.</strong>
        <ul>
          {refusal.violations.map((v) => <li key={v}>{v}</li>)}
        </ul>
        <p>{advice}</p>
        <div className="actions">
          <button type="button" className="btn-danger" disabled={busy} onClick={() => void override(call)}>
            Proceed anyway with a reason…
          </button>
        </div>
      </div>
    )

  return { busy, problem, setProblem, attempt, refusalPanel }
}

/* ======================================================================== */
/*  Check in                                                                */
/* ======================================================================== */

function CheckInStep({
  session,
  online,
  onDone,
}: {
  session: CachedSession
  online: boolean
  onDone: (outcome: Outcome) => void
}) {
  const [weight, setWeight] = useState('')
  const [bpSys, setBpSys] = useState('')
  const [bpDia, setBpDia] = useState('')
  const [pulse, setPulse] = useState('')
  const [temp, setTemp] = useState('')
  const [stationId, setStationId] = useState('')
  const [machineId, setMachineId] = useState('')
  const [chairs, setChairs] = useState<AvailableStations | null>(null)
  const [machines, setMachines] = useState<Machine[]>([])

  const guarded = useGuardedSubmit(async (result) => {
    await reflect(session, result)
    onDone({
      message: `Checked in. Dry weight today ${result.dry_weight_kg ?? '—'} kg${
        result.idwg_kg === null ? '' : `, IDWG ${result.idwg_kg} kg`
      }.`,
      overrides: result.cohort_overrides,
      warnings: result.machine_warnings,
    })
  }, 'Choose a chair or machine above that their cohort may use, then check in again. If there is none and the treatment cannot wait, you can proceed with a recorded reason.')

  useEffect(() => {
    if (!online) return

    let cancelled = false

    void (async () => {
      try {
        const [available, list] = await Promise.all([
          session.shiftId === null
            ? Promise.resolve(null)
            : api.availableStations(session.patientPublicId, session.sessionDate, session.shiftId),
          api.machines(),
        ])

        if (cancelled) return

        setChairs(available)
        setMachines(list.machines)

        // Keep the machine the board assigned, if it did.
        const assigned = list.machines.find((m) => m.asset_tag === session.machine)
        if (assigned !== undefined) setMachineId(String(assigned.id))
      } catch (error) {
        if (!cancelled && !reauthIfExpired(error)) guarded.setProblem(explain(error))
      }
    })()

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online, session.publicId])

  const call = (overrideReason?: string) =>
    api.checkIn(session.publicId, {
      pre_weight_kg: weight,
      pre_bp_sys: opt(bpSys),
      pre_bp_dia: opt(bpDia),
      pre_pulse: opt(pulse),
      pre_temp_c: opt(temp),
      station_id: stationId === '' ? undefined : Number(stationId),
      machine_id: machineId === '' ? undefined : Number(machineId),
      cohort_override_reason: overrideReason,
    })

  return (
    <div className="section">
      <h3>Check in</h3>
      <form
        className="form"
        onSubmit={(event) => {
          event.preventDefault()
          void guarded.attempt(call)
        }}
      >
        <div className="fields">
          <div className="field">
            <label htmlFor="ci-weight">Pre-weight (kg) *</label>
            <input id="ci-weight" inputMode="decimal" autoComplete="off" value={weight}
              onChange={(e) => setWeight(e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="ci-sys">BP sys (mmHg)</label>
            <input id="ci-sys" inputMode="numeric" value={bpSys} onChange={(e) => setBpSys(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="ci-dia">BP dia (mmHg)</label>
            <input id="ci-dia" inputMode="numeric" value={bpDia} onChange={(e) => setBpDia(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="ci-pulse">Pulse (/min)</label>
            <input id="ci-pulse" inputMode="numeric" value={pulse} onChange={(e) => setPulse(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="ci-temp">Temp (°C)</label>
            <input id="ci-temp" inputMode="decimal" value={temp} onChange={(e) => setTemp(e.target.value)} />
          </div>
        </div>

        <div className="fields">
          <div className="field">
            <label htmlFor="ci-chair">Chair</label>
            <select id="ci-chair" value={stationId} onChange={(e) => setStationId(e.target.value)}>
              <option value="">Stay at {session.stationCode ?? 'the booked chair'}</option>
              {(chairs?.stations ?? []).map((st) => (
                <option key={st.id} value={st.id}>Move to {st.code}</option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor="ci-machine">Machine</label>
            <select id="ci-machine" value={machineId} onChange={(e) => setMachineId(e.target.value)}>
              <option value="">Choose…</option>
              {machines.map((m) => (
                // A machine that is not in service is shown, disabled, with its
                // status -- the server would refuse it anyway, and a missing
                // machine is more confusing than a greyed-out one.
                //
                // A machine dedicated to another cohort is NOT hidden. The
                // server refuses it, and that refusal carries the override a
                // unit needs when its only compliant machine is down; hiding it
                // here would take that path away.
                <option key={m.id} value={m.id} disabled={m.status !== 'in_service'}>
                  {m.asset_tag}
                  {m.dedicated_cohort === null || m.dedicated_cohort === undefined ? '' : ` · ${m.dedicated_cohort.toUpperCase()} only`}
                  {m.status === 'in_service' ? '' : ` · ${m.status.replace(/_/g, ' ')}`}
                </option>
              ))}
            </select>
          </div>
        </div>

        <p className="hint" style={{ margin: 0 }}>
          The chair list only offers chairs this patient's cohort may use
          {chairs === null ? '' : ` (${chairs.cohort.toUpperCase()})`}. The dry weight is taken from
          the patient record for today and recorded on this session.
        </p>

        {guarded.refusalPanel(call)}
        {guarded.problem === null ? null : <p className="problem">{guarded.problem}</p>}

        <div className="actions">
          <button type="submit" className="btn-primary" disabled={!online || guarded.busy}>
            {guarded.busy ? 'Checking in…' : 'Check in'}
          </button>
        </div>
      </form>
    </div>
  )
}

/* ======================================================================== */
/*  Start                                                                   */
/* ======================================================================== */

function StartStep({
  session,
  online,
  onDone,
}: {
  session: CachedSession
  online: boolean
  onDone: (outcome: Outcome) => void
}) {
  const [ufGoal, setUfGoal] = useState('')
  const [label, setLabel] = useState('')
  const [bloodFlow, setBloodFlow] = useState('')
  const [dialysateFlow, setDialysateFlow] = useState('')
  const [anticoagulant, setAnticoagulant] = useState('')
  const [dialyzers, setDialyzers] = useState<DialyzerList | null>(null)

  const guarded = useGuardedSubmit(async (result) => {
    await reflect(session, result)
    onDone({
      message: 'Treatment started. The clock is running and charting is open.',
      overrides: result.cohort_overrides,
      warnings: result.machine_warnings,
    })
  }, 'The chair and machine were assigned at check-in, and this is checked again because the needle is about to go in. If this is the breach you already accepted at check-in, proceed with a reason; the second note records that it was still in place at the start.')

  useEffect(() => {
    if (!online) return

    let cancelled = false

    void api.dialyzers(session.patientPublicId)
      .then((list) => { if (!cancelled) setDialyzers(list) })
      .catch((error: unknown) => { if (!cancelled && !reauthIfExpired(error)) guarded.setProblem(explain(error)) })

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [online, session.publicId])

  // Shown as information beside the UF goal, never filled into it. The goal is
  // a clinical decision -- IDWG plus expected intake and rinse-back -- and a
  // pre-filled number is a number that gets accepted without being thought about.
  const idwg = session.preWeightKg !== null && session.dryWeightKg !== null
    ? idwgKg(Number(session.preWeightKg), Number(session.dryWeightKg))
    : null

  const call = (overrideReason?: string) =>
    api.startSession(session.publicId, {
      planned_uf_ml: opt(ufGoal),
      dialyzer_label_code: label.trim() === '' ? undefined : label.trim(),
      blood_flow_set_ml_min: opt(bloodFlow),
      dialysate_flow_ml_min: opt(dialysateFlow),
      anticoagulant: optText(anticoagulant),
      cohort_override_reason: overrideReason,
    })

  return (
    <div className="section">
      <h3>Start treatment</h3>
      <form
        className="form"
        onSubmit={(event) => {
          event.preventDefault()
          void guarded.attempt(call)
        }}
      >
        <div className="fields">
          <div className="field">
            <label htmlFor="st-uf">UF goal (ml)</label>
            <input id="st-uf" inputMode="numeric" value={ufGoal} onChange={(e) => setUfGoal(e.target.value)} />
            <span className="hint">
              {idwg === null ? 'No IDWG yet.' : `IDWG today ${idwg.toFixed(1)} kg.`} The goal is yours to set.
            </span>
          </div>
          <div className="field">
            <label htmlFor="st-qb">Blood flow (ml/min)</label>
            <input id="st-qb" inputMode="numeric" value={bloodFlow} onChange={(e) => setBloodFlow(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="st-qd">Dialysate flow (ml/min)</label>
            <input id="st-qd" inputMode="numeric" value={dialysateFlow} onChange={(e) => setDialysateFlow(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="st-ac">Anticoagulant</label>
            <select id="st-ac" value={anticoagulant} onChange={(e) => setAnticoagulant(e.target.value)}>
              <option value="">Not recorded</option>
              <option value="none">None</option>
              <option value="heparin">Heparin</option>
              <option value="lmwh">LMWH</option>
              <option value="citrate">Citrate</option>
              <option value="saline_flush">Saline flush</option>
              <option value="other">Other</option>
            </select>
          </div>
        </div>

        <div className="field">
          <label htmlFor="st-dz">Dialyzer label</label>
          <input
            id="st-dz"
            autoComplete="off"
            autoCapitalize="characters"
            placeholder="Scan or type the label on the unit"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
          />
          <span className="hint">
            Leave empty for a single-use dialyzer. A reused unit is checked against its reuse limit
            and total cell volume{dialyzers === null ? '' : ` (${dialyzers.minimum_tcv_pct}% minimum)`}, and
            refused outright if it belongs to someone else.
          </span>
        </div>

        {dialyzers === null || dialyzers.units.length === 0 ? null : (
          <div className="dz-list">
            {dialyzers.units.map((unit) => (
              <button
                key={unit.label_code}
                type="button"
                className={`dz-option${unit.refusal_reason === null ? '' : ' dz-refused'}${label === unit.label_code ? ' dz-chosen' : ''}`}
                // A refused unit stays visible with the reason it would be
                // refused, rather than disappearing. The server makes the
                // decision either way; this only saves the nurse a scan.
                disabled={unit.refusal_reason !== null}
                onClick={() => setLabel(unit.label_code)}
              >
                <strong>{unit.label_code}</strong>
                <span>
                  use {unit.use_count}{unit.max_reuse_count === null ? '' : ` of ${unit.max_reuse_count}`}
                  {unit.tcv_pct === null ? '' : ` · TCV ${unit.tcv_pct}%`}
                </span>
                {unit.refusal_reason === null ? null : <em>{unit.refusal_reason}</em>}
              </button>
            ))}
          </div>
        )}

        {guarded.refusalPanel(call)}
        {guarded.problem === null ? null : <p className="problem">{guarded.problem}</p>}

        <div className="actions">
          <button type="submit" className="btn-primary" disabled={!online || guarded.busy}>
            {guarded.busy ? 'Starting…' : 'Start treatment'}
          </button>
          <span className="hint">
            Today's water check, the chair, the machine and the dialyzer are all checked before this is accepted.
          </span>
        </div>
      </form>
    </div>
  )
}

/* ======================================================================== */
/*  End                                                                     */
/* ======================================================================== */

const STOP_REASONS: { value: string; label: string }[] = [
  { value: 'hypotension', label: 'Hypotension' },
  { value: 'clotting', label: 'Circuit clotting' },
  { value: 'access_failure', label: 'Access failure' },
  { value: 'machine_fault', label: 'Machine fault' },
  { value: 'patient_request', label: 'Patient request' },
  { value: 'medical_emergency', label: 'Medical emergency' },
  { value: 'power_failure', label: 'Power failure' },
  { value: 'transferred_to_hospital', label: 'Transferred to hospital' },
  { value: 'other', label: 'Other' },
]

/**
 * Preview adequacy at the chair from the samples typed in.
 *
 * This is what the twin maths in packages/domain is for: the nurse sees a number
 * before submitting. The server recomputes it from the same samples and its
 * figure is the one stored -- a Kt/V sent from here is ignored.
 */
function preview(session: CachedSession, preBun: string, postBun: string, postWeight: string, netUf: string):
  { urr?: number; ktv?: number; warning?: string } {
  const pre = toNumber(preBun)
  const post = toNumber(postBun)

  if (pre === null || post === null) return {}

  const out: { urr?: number; ktv?: number; warning?: string } = {}

  try {
    out.urr = urrPct(pre, post)
  } catch (error) {
    return { warning: error instanceof Error ? error.message : undefined }
  }

  const weight = toNumber(postWeight)
  const uf = toNumber(netUf)
  // parseServerTime, never Date.parse: a naive server timestamp read as local
  // time put elapsed treatment time eight hours out (CLAUDE.md MySQL rule 11).
  const started = session.startedAt === null ? NaN : parseServerTime(session.startedAt)
  const hours = (Date.now() - started) / 3_600_000

  if (weight !== null && uf !== null && Number.isFinite(hours) && hours > 0) {
    try {
      const value = ktv({ preBun: pre, postBun: post, hours, ufLitres: uf / 1000, postWeightKg: weight })

      // ktv() takes the log of (R - 0.008t); for an implausible pair that goes
      // non-positive and the result is NaN rather than an exception.
      if (Number.isFinite(value)) out.ktv = value
    } catch (error) {
      out.warning = error instanceof Error ? error.message : undefined
    }
  }

  return out
}

function EndStep({
  session,
  online,
  onDone,
}: {
  session: CachedSession
  online: boolean
  onDone: (outcome: Outcome) => void
}) {
  const confirmAction = useConfirm()
  const [open, setOpen] = useState(false)
  const [postWeight, setPostWeight] = useState('')
  const [netUf, setNetUf] = useState('')
  const [bpSys, setBpSys] = useState('')
  const [bpDia, setBpDia] = useState('')
  const [pulse, setPulse] = useState('')
  const [stoppedEarly, setStoppedEarly] = useState(false)
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [preBun, setPreBun] = useState('')
  const [postBun, setPostBun] = useState('')
  const [condition, setCondition] = useState('stable')
  const [busy, setBusy] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const adequacy = preview(session, preBun, postBun, postWeight, netUf)

  if (!open) {
    return (
      <div className="section">
        <div className="form">
          <div className="actions" style={{ marginTop: 0 }}>
            <button type="button" className="btn-quiet" onClick={() => setOpen(true)}>
              End treatment…
            </button>
            <span className="hint">Records the time off, the post-weight and the outcome.</span>
          </div>
        </div>
      </div>
    )
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)

    if (stoppedEarly && reason === '') {
      setProblem('Say why the treatment was stopped early.')

      return
    }

    const answer = await confirmAction({
      title: stoppedEarly ? 'End early and record as aborted?' : 'End the treatment now?',
      body: stoppedEarly
        ? 'A treatment stopped early is recorded as aborted, not completed, so the monthly adequacy ' +
          'figures reflect what the unit actually delivered. The time off is recorded as now.'
        : 'The time off is recorded as now. The treatment cannot be restarted afterwards.',
      confirmLabel: stoppedEarly ? 'End early' : 'End treatment',
      tone: stoppedEarly ? 'danger' : 'normal',
    })

    if (!answer.confirmed) return

    setBusy(true)

    try {
      const result = await api.endSession(session.publicId, {
        post_weight_kg: postWeight,
        net_uf_ml: opt(netUf),
        post_bp_sys: opt(bpSys),
        post_bp_dia: opt(bpDia),
        post_pulse: opt(pulse),
        pre_bun_mmol: opt(preBun),
        post_bun_mmol: opt(postBun),
        termination_reason: stoppedEarly ? reason : 'completed_as_prescribed',
        termination_notes: optText(notes),
        discharge_condition: condition,
        // Deliberately no ktv or urr_pct: derived values are the server's.
      })

      await reflect(session, result)

      onDone({
        message: result.status === 'aborted'
          ? 'Treatment stopped early and recorded as aborted.'
          : 'Treatment ended.',
        overrides: [],
        warnings: [],
        adequacy: result.ktv === null && result.urr_pct === null
          ? undefined
          : { ktv: result.ktv, urr: result.urr_pct === null ? null : String(result.urr_pct) },
      })
    } catch (error) {
      if (!reauthIfExpired(error)) setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="section">
      <h3>End treatment</h3>
      <form className="form" onSubmit={(event) => void submit(event)}>
        <div className="fields">
          <div className="field">
            <label htmlFor="en-weight">Post-weight (kg) *</label>
            <input id="en-weight" inputMode="decimal" value={postWeight}
              onChange={(e) => setPostWeight(e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="en-uf">Fluid removed (ml)</label>
            <input id="en-uf" inputMode="numeric" value={netUf} onChange={(e) => setNetUf(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="en-sys">BP sys (mmHg)</label>
            <input id="en-sys" inputMode="numeric" value={bpSys} onChange={(e) => setBpSys(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="en-dia">BP dia (mmHg)</label>
            <input id="en-dia" inputMode="numeric" value={bpDia} onChange={(e) => setBpDia(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="en-pulse">Pulse (/min)</label>
            <input id="en-pulse" inputMode="numeric" value={pulse} onChange={(e) => setPulse(e.target.value)} />
          </div>
        </div>

        <div className="radio-row" role="radiogroup" aria-label="Outcome">
          <label>
            <input type="radio" name="outcome" checked={!stoppedEarly} onChange={() => setStoppedEarly(false)} />
            Completed as prescribed
          </label>
          <label>
            <input type="radio" name="outcome" checked={stoppedEarly} onChange={() => setStoppedEarly(true)} />
            Stopped early
          </label>
        </div>

        {stoppedEarly ? (
          <div className="fields">
            <div className="field">
              <label htmlFor="en-reason">Why *</label>
              <select id="en-reason" value={reason} onChange={(e) => setReason(e.target.value)}>
                <option value="">Choose…</option>
                {STOP_REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
              </select>
            </div>
            <div className="field" style={{ gridColumn: 'span 2' }}>
              <label htmlFor="en-notes">Notes</label>
              <input id="en-notes" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
          </div>
        ) : null}

        <div className="fields">
          <div className="field">
            <label htmlFor="en-prebun">Pre-BUN (mmol/L)</label>
            <input id="en-prebun" inputMode="decimal" value={preBun} onChange={(e) => setPreBun(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="en-postbun">Post-BUN (mmol/L)</label>
            <input id="en-postbun" inputMode="decimal" value={postBun} onChange={(e) => setPostBun(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="en-cond">Condition at discharge</label>
            <select id="en-cond" value={condition} onChange={(e) => setCondition(e.target.value)}>
              <option value="stable">Stable</option>
              <option value="improved">Improved</option>
              <option value="unstable">Unstable</option>
              <option value="referred">Referred</option>
            </select>
          </div>
        </div>

        {adequacy.warning === undefined ? null : <p className="notice" style={{ margin: 0 }}>{adequacy.warning}</p>}

        {adequacy.urr === undefined ? null : (
          <p className="hint" style={{ margin: 0 }}>
            Preview at the chair: URR {adequacy.urr}%
            {adequacy.urr < URR_TARGET_PCT ? ` (below the ${URR_TARGET_PCT}% target)` : ''}
            {adequacy.ktv === undefined ? '' : ` · Kt/V ${adequacy.ktv}${adequacy.ktv < KTV_TARGET ? ` (below ${KTV_TARGET})` : ''}`}.
            The server recalculates these from the same samples, and its figures are the ones stored.
          </p>
        )}

        {problem === null ? null : <p className="problem">{problem}</p>}

        <div className="actions">
          <button type="submit" className={stoppedEarly ? 'btn-danger' : 'btn-primary'} disabled={!online || busy}>
            {busy ? 'Ending…' : stoppedEarly ? 'End early' : 'End treatment'}
          </button>
          <button type="button" className="btn-quiet" onClick={() => setOpen(false)}>Cancel</button>
        </div>
      </form>
    </div>
  )
}
