import type { WaterCheckInput, WaterDay } from '@dialysis/api-client'
import { useConfirm } from '@dialysis/ui'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { timeIn } from '../components/format'

/**
 * The daily water check -- the reading invariant 9 waits for.
 *
 * Until this screen existed the API could take a check but nothing could send
 * one, so on a fresh install every day's first Start was refused. The server
 * judges the reading (above the limit or not) and decides whether the unit is
 * cleared; this screen only collects the numbers and shows the answer.
 *
 * Days are the unit's calendar days, midnight to midnight in the unit's
 * timezone. A check at 05:30 counts for the 06:00 shift.
 */

type Tri = '' | 'yes' | 'no'

interface Form {
  water_system_id: string
  shift_code: string
  total_chlorine_ppm: string
  free_chlorine_ppm: string
  ph: string
  product_conductivity_us: string
  feed_conductivity_us: string
  rejection_pct: string
  temperature_c: string
  hardness_ppm: string
  feed_pressure_psi: string
  product_pressure_psi: string
  reject_pressure_psi: string
  softener_salt_ok: Tri
  carbon_tank_ok: Tri
  action_taken: string
}

const EMPTY: Form = {
  water_system_id: '',
  shift_code: '',
  total_chlorine_ppm: '',
  free_chlorine_ppm: '',
  ph: '',
  product_conductivity_us: '',
  feed_conductivity_us: '',
  rejection_pct: '',
  temperature_c: '',
  hardness_ppm: '',
  feed_pressure_psi: '',
  product_pressure_psi: '',
  reject_pressure_psi: '',
  softener_salt_ok: '',
  carbon_tank_ok: '',
  action_taken: '',
}

/** Optional readings, sent only when something was typed -- never as empty strings. */
const OPTIONAL: (keyof Form & keyof WaterCheckInput)[] = [
  'free_chlorine_ppm', 'ph', 'product_conductivity_us', 'feed_conductivity_us', 'rejection_pct',
  'temperature_c', 'hardness_ppm', 'feed_pressure_psi', 'product_pressure_psi', 'reject_pressure_psi',
]

export function WaterView() {
  const confirmAction = useConfirm()

  // null means "the unit's today", which only the server knows for certain.
  const [date, setDate] = useState<string | null>(null)
  const [day, setDay] = useState<WaterDay | null>(null)
  const [form, setForm] = useState<Form>(EMPTY)
  const [problem, setProblem] = useState<string | null>(null)
  const [formProblem, setFormProblem] = useState<string | null>(null)
  const [done, setDone] = useState<{ text: string; cleared: boolean } | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async (on: string | null) => {
    setProblem(null)

    try {
      const loaded = await api.water(on ?? undefined)
      setDay(loaded)
      // With one water system there is nothing to choose.
      setForm((current) =>
        current.water_system_id === '' && loaded.systems.length === 1
          ? { ...current, water_system_id: String(loaded.systems[0]!.id) }
          : current,
      )
    } catch (error) {
      setProblem(explain(error))
    }
  }, [])

  useEffect(() => {
    void load(date)
  }, [date, load])

  const set = (key: keyof Form, value: string) => setForm((current) => ({ ...current, [key]: value }))

  const limit = day?.action_limit_ppm ?? null
  const chlorine = form.total_chlorine_ppm.trim()
  const chlorineValid = /^\d+(\.\d{1,3})?$/.test(chlorine)
  // A hint while typing. The server makes the call, with the same comparison
  // v_water_exceptions uses: strictly above the limit.
  const breaching = chlorineValid && limit !== null && Number(chlorine) > limit
  const viewingToday = day !== null && day.date === day.today

  async function record(event: React.FormEvent) {
    event.preventDefault()
    setFormProblem(null)
    setDone(null)

    if (day === null) return

    if (!chlorineValid) {
      setFormProblem('Enter total chlorine in ppm, with up to three decimals — for example 0.02.')

      return
    }

    if (form.water_system_id === '') {
      setFormProblem('Choose the water system this reading came from.')

      return
    }

    if (breaching && form.action_taken.trim() === '') {
      setFormProblem(`${chlorine} ppm is above the ${day.action_limit_ppm} ppm action limit. Say what was done about it.`)

      return
    }

    if (breaching) {
      // Says what the gate will actually do. Once treatment is under way the
      // system does not stop the next start on a later reading -- a dialog that
      // promised it would is a control the unit would wrongly rely on.
      const answer = await confirmAction({
        title: 'Record a reading above the action limit?',
        body: day.gate.treatment_started
          ? `Total chlorine ${chlorine} ppm is above ${day.action_limit_ppm} ppm. Treatment is already under way ` +
            'today, so the system will not stop the next start on this reading — whether to carry on is the ' +
            'unit’s decision, and it has to be made now.'
          : `Total chlorine ${chlorine} ppm is above ${day.action_limit_ppm} ppm. The day’s first treatment will be ` +
            'refused until a passing check is recorded.',
        confirmLabel: 'Record the breach',
        tone: 'danger',
      })

      if (!answer.confirmed) return
    }

    const check: WaterCheckInput = {
      water_system_id: Number(form.water_system_id),
      total_chlorine_ppm: chlorine,
    }

    for (const key of OPTIONAL) {
      const value = form[key].trim()
      if (value !== '') (check as unknown as Record<string, string>)[key] = value
    }

    if (form.shift_code !== '') check.shift_code = form.shift_code
    if (form.softener_salt_ok !== '') check.softener_salt_ok = form.softener_salt_ok === 'yes'
    if (form.carbon_tank_ok !== '') check.carbon_tank_ok = form.carbon_tank_ok === 'yes'
    if (form.action_taken.trim() !== '') check.action_taken = form.action_taken.trim()

    setBusy(true)

    try {
      const result = await api.recordWaterCheck(check)
      setDone({
        cleared: result.clearance.cleared,
        text: result.clearance.cleared
          ? `Recorded at ${timeIn(result.logged_at, day.timezone)}. The unit is cleared to dialyse today.`
          : `Recorded at ${timeIn(result.logged_at, day.timezone)}. Not cleared: ${result.clearance.reason ?? 'see the reading.'}`,
      })
      setForm((current) => ({ ...EMPTY, water_system_id: current.water_system_id, shift_code: current.shift_code }))
      await load(date)
    } catch (error) {
      // What was typed stays: a refused check is fixed and sent again, not retyped.
      setFormProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  if (day === null) {
    return (
      <>
        <div className="pagehead">
          <h1>Water</h1>
        </div>
        <div className="panel">
          {problem === null ? <p className="empty">Loading…</p> : <div className="panelbody"><p className="problem">{problem}</p></div>}
        </div>
      </>
    )
  }

  const clearance = day.clearance
  // Latest reading failed, yet starts still go through: treatment is under way
  // and the day had a passing check. Shown as its own state, never as "cleared".
  const failingButAllowed = viewingToday && !clearance.cleared && day.gate.start_allowed

  return (
    <>
      <div className="pagehead">
        <h1>Water</h1>
        <span className={`chip ${clearance.cleared ? 'chip-ok' : failingButAllowed ? 'chip-warn' : 'chip-danger'}`}>
          {clearance.cleared ? 'Cleared' : failingButAllowed ? 'Latest reading failed' : 'Not cleared'}
        </span>
      </div>
      <p className="subtle">
        The day&rsquo;s first treatment waits for a passing total-chlorine reading — at most{' '}
        {day.action_limit_ppm} ppm. Days run midnight to midnight in {day.timezone}; every time here is on that clock.
      </p>

      {problem === null ? null : <p className="problem">{problem}</p>}

      <div className={`clearance ${clearance.cleared ? 'is-clear' : failingButAllowed ? 'is-warn' : 'is-blocked'}`}>
        <strong>
          {clearance.cleared
            ? viewingToday
              ? 'Cleared to dialyse today.'
              : `Cleared on ${day.date}.`
            : !viewingToday
              ? `Not cleared on ${day.date}.`
              : failingButAllowed
                ? 'The latest reading failed — and the system will not stop the next start.'
                : 'Not cleared — the next treatment start will be refused.'}
        </strong>
        <span>
          {clearance.cleared
            ? `Total chlorine ${clearance.total_chlorine_ppm} ppm at ${timeIn(clearance.checked_at, day.timezone)}.`
            : clearance.reason}
        </span>
        {failingButAllowed ? (
          <span>
            Treatment is already under way today and the day had a passing check, so a later failing reading does not
            block starts. Whether to carry on is the unit&rsquo;s decision; record a passing re-test once it is fixed.
          </span>
        ) : null}
      </div>

      {viewingToday ? (
        day.can_record ? (
          <div className="panel">
            <header>
              <h2>Record a check</h2>
            </header>
            <form className="panelbody" onSubmit={(event) => void record(event)}>
              {formProblem === null ? null : <p className="problem">{formProblem}</p>}
              {/* A recorded breach is not good news, however smoothly it was saved. */}
              {done === null ? null : <p className={done.cleared ? 'good' : 'notice'}>{done.text}</p>}

              {day.systems.length === 0 ? (
                <p className="problem">
                  No active water system is on file, so there is nothing to record a reading against. One has to be
                  added to the database before checks can be recorded.
                </p>
              ) : null}

              <div className="fields">
                <div className="field">
                  <label htmlFor="w-system">Water system</label>
                  {day.systems.length === 1 ? (
                    <span className="fixedvalue">{day.systems[0]!.name}</span>
                  ) : (
                    <select id="w-system" value={form.water_system_id} onChange={(e) => set('water_system_id', e.target.value)} required>
                      <option value="">Choose…</option>
                      {day.systems.map((system) => (
                        <option key={system.id} value={String(system.id)}>
                          {system.name}
                        </option>
                      ))}
                    </select>
                  )}
                </div>
                <div className="field">
                  <label htmlFor="w-shift">Before shift</label>
                  <select id="w-shift" value={form.shift_code} onChange={(e) => set('shift_code', e.target.value)}>
                    <option value="">Not tied to a shift</option>
                    {day.shifts.map((shift) => (
                      <option key={shift.code} value={shift.code}>
                        {shift.code} — {shift.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="field">
                  <label htmlFor="w-cl">Total chlorine (ppm)</label>
                  <input
                    id="w-cl"
                    inputMode="decimal"
                    value={form.total_chlorine_ppm}
                    placeholder="0.02"
                    onChange={(e) => set('total_chlorine_ppm', e.target.value)}
                    aria-describedby="w-cl-hint"
                    className={breaching ? 'breach' : undefined}
                    required
                  />
                  <span id="w-cl-hint" className={`hint ${breaching ? 'hint-bad' : ''}`}>
                    {breaching ? `Above the ${day.action_limit_ppm} ppm action limit` : `Action limit ${day.action_limit_ppm} ppm`}
                  </span>
                </div>
                <div className="field">
                  <label htmlFor="w-free">Free chlorine (ppm)</label>
                  <input id="w-free" inputMode="decimal" value={form.free_chlorine_ppm} onChange={(e) => set('free_chlorine_ppm', e.target.value)} />
                </div>
              </div>

              <fieldset className="readings">
                <legend>Reverse osmosis</legend>
                <div className="fields">
                  <div className="field">
                    <label htmlFor="w-pc">Product conductivity <span className="unit">(µS/cm)</span></label>
                    <input id="w-pc" inputMode="decimal" value={form.product_conductivity_us} onChange={(e) => set('product_conductivity_us', e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="w-fc">Feed conductivity <span className="unit">(µS/cm)</span></label>
                    <input id="w-fc" inputMode="decimal" value={form.feed_conductivity_us} onChange={(e) => set('feed_conductivity_us', e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="w-rej">Rejection (%)</label>
                    <input id="w-rej" inputMode="decimal" value={form.rejection_pct} onChange={(e) => set('rejection_pct', e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="w-fp">Feed pressure (psi)</label>
                    <input id="w-fp" inputMode="decimal" value={form.feed_pressure_psi} onChange={(e) => set('feed_pressure_psi', e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="w-pp">Product pressure (psi)</label>
                    <input id="w-pp" inputMode="decimal" value={form.product_pressure_psi} onChange={(e) => set('product_pressure_psi', e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="w-rp">Reject pressure (psi)</label>
                    <input id="w-rp" inputMode="decimal" value={form.reject_pressure_psi} onChange={(e) => set('reject_pressure_psi', e.target.value)} />
                  </div>
                </div>
              </fieldset>

              <fieldset className="readings">
                <legend>Pre-treatment and water</legend>
                <div className="fields">
                  <div className="field">
                    <label htmlFor="w-salt">Softener salt</label>
                    <select id="w-salt" value={form.softener_salt_ok} onChange={(e) => set('softener_salt_ok', e.target.value)}>
                      <option value="">Not checked</option>
                      <option value="yes">OK</option>
                      <option value="no">Not OK</option>
                    </select>
                  </div>
                  <div className="field">
                    <label htmlFor="w-carbon">Carbon tank</label>
                    <select id="w-carbon" value={form.carbon_tank_ok} onChange={(e) => set('carbon_tank_ok', e.target.value)}>
                      <option value="">Not checked</option>
                      <option value="yes">OK</option>
                      <option value="no">Not OK</option>
                    </select>
                  </div>
                  <div className="field">
                    <label htmlFor="w-ph">pH</label>
                    <input id="w-ph" inputMode="decimal" value={form.ph} onChange={(e) => set('ph', e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="w-temp">Temperature (°C)</label>
                    <input id="w-temp" inputMode="decimal" value={form.temperature_c} onChange={(e) => set('temperature_c', e.target.value)} />
                  </div>
                  <div className="field">
                    <label htmlFor="w-hard">Hardness (ppm)</label>
                    <input id="w-hard" inputMode="decimal" value={form.hardness_ppm} onChange={(e) => set('hardness_ppm', e.target.value)} />
                  </div>
                </div>
              </fieldset>

              <div className="field" style={{ marginTop: 14 }}>
                <label htmlFor="w-action">What was done{breaching ? ' (required — the reading is above the limit)' : ''}</label>
                <textarea
                  id="w-action"
                  value={form.action_taken}
                  maxLength={2000}
                  placeholder={breaching ? 'e.g. unit stopped, carbon tank being changed, re-test after' : 'Only needed when something was wrong'}
                  onChange={(e) => set('action_taken', e.target.value)}
                  required={breaching}
                />
              </div>

              <div className="actions">
                <button type="submit" className={breaching ? 'btn-danger' : 'btn-primary'} disabled={busy || day.systems.length === 0}>
                  {busy ? 'Recording…' : breaching ? 'Record the breach' : 'Record check'}
                </button>
                <span className="muted">Recorded at the server&rsquo;s time, on the unit&rsquo;s day.</span>
              </div>
            </form>
          </div>
        ) : (
          <p className="notice">
            Recording a water check is for the renal technician, a head nurse or an administrator. Everyone else can see
            whether the unit is cleared.
          </p>
        )
      ) : null}

      <div className="panel">
        <header>
          <h2>Checks on {day.date === day.today ? 'today' : day.date}</h2>
          <span className="grow" />
          <div className="field inline">
            <label htmlFor="w-date">Day</label>
            <input
              id="w-date"
              type="date"
              value={day.date}
              max={day.today}
              onChange={(event) => {
                setDone(null)
                setDate(event.target.value === '' ? null : event.target.value)
              }}
            />
          </div>
          {viewingToday ? null : (
            <button type="button" className="btn-quiet" onClick={() => setDate(null)}>
              Today
            </button>
          )}
        </header>

        {day.logs.length === 0 ? (
          <p className="empty">No checks recorded on this day.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Time</th>
                  <th className="num">Total Cl</th>
                  <th className="num">Free Cl</th>
                  <th className="num">pH</th>
                  <th className="num">Product <span className="unit">µS/cm</span></th>
                  <th className="num">Rejection</th>
                  <th>Salt / carbon</th>
                  <th>What was done</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                {day.logs.map((log, index) => (
                  <tr key={`${log.logged_at}-${index}`}>
                    <td>
                      {timeIn(log.logged_at, day.timezone)}
                      {log.shift_code === null ? null : <span className="muted"> · {log.shift_code}</span>}
                      <div className="muted small">{log.system_name}</div>
                      {enteredLater(log.logged_at, log.recorded_at) ? (
                        <div className="muted small">entered {timeIn(log.recorded_at, day.timezone)}</div>
                      ) : null}
                    </td>
                    <td className={`num ${log.is_out_of_range ? 'breachcell' : ''}`}>
                      <strong>{log.total_chlorine_ppm ?? '—'}</strong>
                      {log.is_out_of_range ? <div className="small">above limit</div> : null}
                    </td>
                    <td className="num">{log.free_chlorine_ppm ?? '—'}</td>
                    <td className="num">{log.ph ?? '—'}</td>
                    <td className="num">{log.product_conductivity_us ?? '—'}</td>
                    <td className="num">{log.rejection_pct === null ? '—' : `${log.rejection_pct}%`}</td>
                    <td className="muted">
                      {okay(log.softener_salt_ok)} / {okay(log.carbon_tank_ok)}
                    </td>
                    <td className="wrapcell">{log.action_taken ?? ''}</td>
                    <td className="muted">{log.logged_by ?? '—'}</td>
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

function okay(value: boolean | null): string {
  return value === null ? '—' : value ? 'OK' : 'Not OK'
}

/** More than five minutes between the check and its entry is worth showing. */
function enteredLater(loggedAt: string, recordedAt: string | null): boolean {
  if (recordedAt === null) return false

  return new Date(recordedAt).getTime() - new Date(loggedAt).getTime() > 5 * 60 * 1000
}
