import type { Settings, SettingsStation } from '@dialysis/api-client'
import { useConfirm } from '@dialysis/ui'
import { useCallback, useEffect, useState, type ReactNode } from 'react'

import { api, explain } from '../api'

/**
 * Unit settings.
 *
 * Every control on this page changes how the system behaves somewhere else, and
 * some of them change what it will let a nurse do to a patient. So each one
 * carries its consequence inline rather than in a manual nobody opens: the
 * moment to explain that clearing a chair's cohorts lets an HBV patient sit in
 * it is while somebody is looking at the checkbox, not afterwards.
 *
 * The three tones are a real distinction, not decoration:
 *   safety - changes what the system will permit at the chair
 *   money  - changes what gets billed, or to whom
 *   info   - organisational or display only
 */

type Tone = 'safety' | 'money' | 'info'

function Consequence({ tone, label, children }: { tone: Tone; label: string; children: ReactNode }) {
  return (
    <div className={`consequence consequence-${tone}`}>
      <span className="what">{label}</span>
      <div>{children}</div>
    </div>
  )
}

const COHORTS = ['clean', 'hbv', 'hcv'] as const

export function SettingsView() {
  const confirmAction = useConfirm()

  const [settings, setSettings] = useState<Settings | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setProblem(null)

    try {
      setSettings(await api.settings())
    } catch (error) {
      setProblem(explain(error))
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  /** Wrap a write so every one reports the same way. */
  async function run(what: string, action: () => Promise<unknown>) {
    setBusy(true)
    setProblem(null)
    setDone(null)

    try {
      await action()
      await load()
      setDone(what)
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  async function changeCohorts(station: SettingsStation, next: string[]) {
    const wasRestricted = station.cohorts.length > 0
    const nowUnrestricted = next.length === 0

    // Only the loosening direction needs a reason. Tightening a chair, or
    // moving it from one cohort to another, does not widen anything.
    if (wasRestricted && nowUnrestricted) {
      const answer = await confirmAction({
        title: `Make chair ${station.code} unrestricted?`,
        body:
          `${station.code} is currently limited to ${station.cohorts.join(', ').toUpperCase()}. ` +
          'With no cohorts set, the system will seat any patient here — including an HBV-positive ' +
          'patient in a chair staff may still think of as clean. Check-in will not refuse it and ' +
          'will not warn.',
        confirmLabel: 'Make unrestricted',
        cancelLabel: 'Leave as it is',
        tone: 'danger',
        reason: {
          label: 'Why is this chair becoming unrestricted?',
          minLength: 10,
          destination: 'the audit log',
        },
      })

      if (!answer.confirmed) return

      await run(
        `${station.code} is now unrestricted.`,
        () => api.setStationCohorts(station.id, next, answer.reason ?? undefined),
      )

      return
    }

    await run(
      `${station.code} updated.`,
      () => api.setStationCohorts(station.id, next),
    )
  }

  if (settings === null) {
    return (
      <>
        <div className="pagehead"><h1>Settings</h1></div>
        {problem === null ? <p className="subtle">Loading…</p> : <p className="problem">{problem}</p>}
      </>
    )
  }

  const facility = (settings.facility ?? {}) as Record<string, unknown>
  const openPrograms = settings.benefit_programs.filter((p) => p.effective_to === null)

  return (
    <>
      <div className="pagehead">
        <h1>Settings</h1>
      </div>
      <p className="subtle">
        Administrator only. Every change here is recorded against your name — these tables have no
        model behind them, so each write logs itself.
      </p>

      {problem === null ? null : <p className="problem">{problem}</p>}
      {done === null ? null : <p className="good">{done}</p>}

      {/* ================================================== chairs ===== */}
      <div className="panel">
        <header>
          <h2>Chairs and infection control</h2>
        </header>
        <div className="panelbody">
          <Consequence tone="safety" label="At the chair">
            <p>
              This is the highest-consequence setting in the system. It decides which patients may
              be seated where, and check-in refuses an assignment that breaks it.
            </p>
            <p>
              <strong>A chair with no cohorts ticked is unrestricted, not unusable.</strong> The
              system will seat anyone in it. That is the opposite of what most people assume the
              empty state means, and it is why clearing the last tick asks you for a reason.
            </p>
            <p>
              Changing a chair does not move patients already booked into it. Tomorrow's board is
              built from today's settings, so check the board after a change.
            </p>
          </Consequence>

          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Chair</th>
                  <th>Room</th>
                  <th>Accepts</th>
                  <th>Effect</th>
                </tr>
              </thead>
              <tbody>
                {settings.stations.map((station) => (
                  <tr key={station.id}>
                    <td><strong>{station.code}</strong></td>
                    <td className="muted">{station.room ?? '—'}</td>
                    <td>
                      <div className="cohortpick">
                        {COHORTS.map((cohort) => (
                          <label key={cohort}>
                            <input
                              type="checkbox"
                              disabled={busy}
                              checked={station.cohorts.includes(cohort)}
                              onChange={(event) => {
                                const next = event.target.checked
                                  ? [...station.cohorts, cohort]
                                  : station.cohorts.filter((c) => c !== cohort)

                                void changeCohorts(station, next)
                              }}
                            />
                            {cohort.toUpperCase()}
                          </label>
                        ))}
                      </div>
                    </td>
                    <td className="wrapcell">
                      {station.cohorts.length === 0 ? (
                        <span className="unrestricted">Any patient — no restriction</span>
                      ) : (
                        <span className="muted">
                          Only {station.cohorts.map((c) => c.toUpperCase()).join(' or ')}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* ============================================== high alert ===== */}
      <div className="panel">
        <header>
          <h2>High-alert medications</h2>
        </header>
        <div className="panelbody">
          <Consequence tone="safety" label="At the chair">
            <p>
              A drug flagged high-alert <strong>cannot be given without a witness</strong>, and the
              witness may not be the person giving it. Unflagging one removes that second check
              entirely — the dose will save with nobody else having looked at it.
            </p>
            <p>
              Both the service and a database trigger read this column, so the change takes effect
              everywhere at once, including on tablets that are currently offline the moment they
              next sync.
            </p>
          </Consequence>

          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Medication</th>
                  <th>Strength</th>
                  <th>Witness required</th>
                </tr>
              </thead>
              <tbody>
                {settings.high_alert.map((drug) => (
                  <tr key={drug.id}>
                    <td>
                      <strong>{drug.generic_name}</strong>
                      {drug.brand_name === null || drug.brand_name === undefined ? null : (
                        <span className="muted"> ({drug.brand_name})</span>
                      )}
                    </td>
                    <td className="muted">
                      {drug.strength ?? '—'} {drug.unit ?? ''}
                    </td>
                    <td>
                      <label className="switch">
                        <input
                          type="checkbox"
                          disabled={busy}
                          checked={drug.is_high_alert}
                          onChange={async (event) => {
                            const next = event.target.checked

                            if (!next) {
                              const answer = await confirmAction({
                                title: `Stop requiring a witness for ${drug.generic_name}?`,
                                body:
                                  'Doses will save with no independent second check. This is the ' +
                                  'control that catches a wrong drug or a wrong dose before it ' +
                                  'reaches the patient.',
                                confirmLabel: 'Remove the witness requirement',
                                cancelLabel: 'Keep it',
                                tone: 'danger',
                                reason: {
                                  label: `Why does ${drug.generic_name} no longer need a witness?`,
                                  minLength: 10,
                                  destination: "the audit log",
                                },
                              })

                              if (!answer.confirmed) return

                              await run(
                                `${drug.generic_name} no longer requires a witness.`,
                                () => api.setHighAlert(drug.id, false, answer.reason ?? undefined),
                              )

                              return
                            }

                            await run(
                              `${drug.generic_name} now requires a witness.`,
                              () => api.setHighAlert(drug.id, true),
                            )
                          }}
                        />
                        {drug.is_high_alert ? (
                          <span className="chip chip-danger">High alert</span>
                        ) : (
                          <span className="muted">No witness needed</span>
                        )}
                      </label>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* =========================================== benefit rates ===== */}
      <div className="panel">
        <header>
          <h2>Benefit programmes</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            {openPrograms.length} in force
          </span>
        </header>
        <div className="panelbody">
          <Consequence tone="money" label="On the bill">
            <p>
              The case rate and the annual session allotment are read from here at the moment a
              claim is generated, for the date the treatment happened —{' '}
              <strong>never from the code</strong>. That is why a rate is added, never edited.
            </p>
            <p>
              Adding a programme closes the current one on the new start date and leaves it on file,
              so a claim for a past session still prices against the rate that applied then. Editing
              a rate in place would silently re-price every historical claim that touches it.
            </p>
            <p>
              The PhilHealth case rate has moved ₱2,600 → ₱4,000 → ₱6,350 and the allotment 90 →
              156. Each version needs its own code.
            </p>
          </Consequence>

          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Payer</th>
                  <th className="num">Case rate</th>
                  <th className="num">Per period</th>
                  <th>Period</th>
                  <th>Balance billing</th>
                  <th>From</th>
                  <th>To</th>
                </tr>
              </thead>
              <tbody>
                {settings.benefit_programs.map((program) => (
                  <tr key={program.id}>
                    <td><strong>{program.code}</strong></td>
                    <td className="muted">{program.payer_name ?? '—'}</td>
                    <td className="num">
                      {program.currency ?? ''} {program.case_rate}
                    </td>
                    <td className="num">{program.sessions_per_period ?? '—'}</td>
                    <td className="muted">{(program.period_kind ?? '').replace(/_/g, ' ')}</td>
                    <td>
                      {program.no_balance_billing ? (
                        <span className="chip chip-warn">Not allowed</span>
                      ) : (
                        <span className="muted">Allowed</span>
                      )}
                    </td>
                    <td className="muted">{program.effective_from}</td>
                    <td>
                      {program.effective_to === null || program.effective_to === undefined ? (
                        <span className="chip chip-ok">in force</span>
                      ) : (
                        <span className="muted">{program.effective_to}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <p className="subtle" style={{ margin: '14px 0 0' }}>
            <strong>No balance billing</strong> means the patient's share is set to zero whatever the
            list price came to — charging them the difference is exactly what that rule forbids.
          </p>
        </div>
      </div>

      {/* ==================================================== roles ===== */}
      <div className="panel">
        <header>
          <h2>Staff and roles</h2>
        </header>
        <div className="panelbody">
          <Consequence tone="safety" label="Who can do what">
            <p>
              Roles are not seniority tiers. A technician can log water and reprocess dialyzers and a
              nurse cannot; only a nephrologist can countersign a treatment record or amend a locked
              one; only an administrator sees this page.
            </p>
            <p>
              Removing the last nephrologist means no record can be closed — treatments will run and
              never lock. The system will stop you removing your <em>own</em> admin role while you
              are the only administrator, because there would be no way back in short of database
              access.
            </p>
          </Consequence>

          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Staff no.</th>
                  <th>Roles</th>
                </tr>
              </thead>
              <tbody>
                {settings.staff.map((person) => (
                  <tr key={person.public_id}>
                    <td>
                      <strong>{person.full_name}</strong>
                      {person.is_active ? null : <span className="chip chip-quiet"> inactive</span>}
                    </td>
                    <td className="muted">{person.employee_no ?? '—'}</td>
                    <td>
                      <div className="rolepick">
                        {settings.roles.map((role) => (
                          <label key={role.code} title={role.description ?? role.name}>
                            <input
                              type="checkbox"
                              disabled={busy}
                              checked={person.roles.includes(role.code)}
                              onChange={(event) => {
                                const next = event.target.checked
                                  ? [...person.roles, role.code]
                                  : person.roles.filter((r) => r !== role.code)

                                void run(
                                  `${person.full_name} updated.`,
                                  () => api.setStaffRoles(person.public_id, next),
                                )
                              }}
                            />
                            {role.code}
                          </label>
                        ))}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* ================================================= facility ===== */}
      <FacilityPanel
        facility={facility}
        busy={busy}
        onSave={(attributes) => run('Facility updated.', () => api.updateFacility(attributes))}
      />
    </>
  )
}

function FacilityPanel({
  facility,
  busy,
  onSave,
}: {
  facility: Record<string, unknown>
  busy: boolean
  onSave: (attributes: Record<string, unknown>) => void
}) {
  const [form, setForm] = useState({
    name: String(facility.name ?? ''),
    timezone: String(facility.timezone ?? ''),
    currency: String(facility.currency ?? ''),
    station_count: String(facility.station_count ?? ''),
  })

  const set = (key: string, value: string) => setForm((current) => ({ ...current, [key]: value }))

  return (
    <div className="panel">
      <header>
        <h2>Facility</h2>
      </header>
      <div className="panelbody">
        <Consequence tone="safety" label="Decides when the unit's day starts">
          <p>
            <strong>The timezone is where the unit&rsquo;s day begins and ends.</strong> It decides which
            day a water check counts for — so whether the morning&rsquo;s first treatment may start —
            which date the board and the bedside tablets open on, and at what moment stock passes its
            expiry date. Set it to where the unit actually is.
          </p>
          <p>
            Every timestamp is stored in UTC, so changing it does not move a recorded time: a treatment
            charted at 07:00 stays the same instant. It changes which calendar day that instant falls on.
          </p>
        </Consequence>

        <Consequence tone="info" label="Display only">
          <p>
            The currency is the one amounts are shown in. It does not convert anything: existing
            invoices and claims keep the figures they were written with.
          </p>
        </Consequence>

        <form
          onSubmit={(event) => {
            event.preventDefault()
            onSave({
              name: form.name,
              timezone: form.timezone,
              currency: form.currency.toUpperCase(),
              station_count: Number(form.station_count),
            })
          }}
        >
          <div className="fields">
            <div className="field">
              <label htmlFor="fc-name">Unit name</label>
              <input id="fc-name" value={form.name} onChange={(e) => set('name', e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="fc-tz">Unit timezone</label>
              <input id="fc-tz" value={form.timezone} onChange={(e) => set('timezone', e.target.value)} placeholder="Asia/Manila" required />
            </div>
            <div className="field">
              <label htmlFor="fc-cur">Currency</label>
              <input id="fc-cur" value={form.currency} onChange={(e) => set('currency', e.target.value)} maxLength={3} required />
            </div>
            <div className="field">
              <label htmlFor="fc-count">Chairs</label>
              <input id="fc-count" inputMode="numeric" value={form.station_count} onChange={(e) => set('station_count', e.target.value)} required />
            </div>
          </div>

          <div className="actions">
            <button type="submit" className="btn-primary" disabled={busy}>
              Save facility
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
