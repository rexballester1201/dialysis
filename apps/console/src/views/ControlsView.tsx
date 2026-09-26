import type {
  CohortViolationReport,
  DialyzerExceptionReport,
  WaterExceptionReport,
} from '@dialysis/api-client'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'

/**
 * The detective controls, on a screen.
 *
 * Cohort breaches, water exceptions and dialyzer failures are all refused at the
 * service boundary now, so anything appearing here got in another way — an
 * import, a console command, a recorded infection-control override. That makes
 * this list short by design, and a row on it is worth reading rather than
 * triaging.
 *
 * `dialysis:check-controls` already reads the same three views twice a day and
 * exits non-zero while anything is outstanding. This is the same information for
 * whoever that alert wakes.
 */
export function ControlsView() {
  const [cohort, setCohort] = useState<CohortViolationReport | null>(null)
  const [water, setWater] = useState<WaterExceptionReport | null>(null)
  const [dialyzers, setDialyzers] = useState<DialyzerExceptionReport | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setProblem(null)

    const [c, w, d] = await Promise.allSettled([
      api.cohortViolations(),
      api.waterExceptions(),
      api.dialyzerExceptions(),
    ])

    if (c.status === 'fulfilled') setCohort(c.value)
    if (w.status === 'fulfilled') setWater(w.value)
    if (d.status === 'fulfilled') setDialyzers(d.value)

    const failed = [c, w, d].find((r) => r.status === 'rejected')
    if (failed !== undefined && failed.status === 'rejected') setProblem(explain(failed.reason))

    setLoading(false)
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const cohortRows = cohort?.violations ?? []
  const waterRows = water?.exceptions ?? []
  const dialyzerRows = dialyzers?.dialyzers ?? []
  const total = cohortRows.length + waterRows.length + dialyzerRows.length

  return (
    <>
      <div className="pagehead">
        <h1>Controls</h1>
      </div>
      <p className="subtle">
        What slipped past a service boundary. These are the same three views the twice-daily check
        reads — a detective control nobody looks at is not a control.
      </p>

      {problem === null ? null : <p className="problem">{problem}</p>}

      <div className="stats">
        <div className="stat">
          <span className="label">Outstanding</span>
          <span className={`value ${total > 0 ? 'bad' : 'good'}`}>{loading ? '—' : total}</span>
        </div>
        <div className="stat">
          <span className="label">Cohort breaches</span>
          <span className={`value ${cohortRows.length > 0 ? 'bad' : ''}`}>{cohortRows.length}</span>
        </div>
        <div className="stat">
          <span className="label">Water exceptions</span>
          <span className={`value ${waterRows.length > 0 ? 'bad' : ''}`}>{waterRows.length}</span>
        </div>
        <div className="stat">
          <span className="label">Dialyzer exceptions</span>
          <span className={`value ${dialyzerRows.length > 0 ? 'bad' : ''}`}>{dialyzerRows.length}</span>
        </div>
      </div>

      <div className="actions" style={{ marginTop: 0, marginBottom: 16 }}>
        <button type="button" className="btn-quiet" onClick={() => void load()} disabled={loading}>
          {loading ? 'Checking…' : 'Re-check'}
        </button>
      </div>

      <div className="panel">
        <header>
          <h2>Cohort breaches</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            A recorded infection-control override appears here too. That is the point of recording it.
          </span>
        </header>
        {cohortRows.length === 0 ? (
          <p className="empty">Nothing outstanding.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Patient</th>
                  <th>MRN</th>
                  <th>Patient cohort</th>
                  <th>Chair</th>
                  <th>Machine</th>
                  <th>Chair dedicated to</th>
                </tr>
              </thead>
              <tbody>
                {cohortRows.map((row, index) => (
                  <tr key={index}>
                    <td className="muted">{row.session_date ?? '—'}</td>
                    <td>{row.full_name ?? '—'}</td>
                    <td className="muted">{row.mrn ?? '—'}</td>
                    <td>
                      <span className="chip chip-danger">{row.cohort ?? '—'}</span>
                    </td>
                    <td>{row.station_code ?? '—'}</td>
                    <td className="muted">{row.asset_tag ?? '—'}</td>
                    <td className="muted">{row.dedicated_cohort ?? 'not dedicated'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="panel">
        <header>
          <h2>Water exceptions</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            Total chlorine above 0.1 ppm blocks the day's first needle.
          </span>
        </header>
        {waterRows.length === 0 ? (
          <p className="empty">Nothing outstanding.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Source</th>
                  <th>Parameter</th>
                  <th className="num">Reading</th>
                  <th className="num">Limit</th>
                  <th>Action taken</th>
                </tr>
              </thead>
              <tbody>
                {waterRows.map((row, index) => (
                  <tr key={index}>
                    <td className="muted">{row.on_date ?? '—'}</td>
                    <td className="muted">{row.system_name ?? row.source ?? '—'}</td>
                    <td>
                      <span className="chip chip-danger">{row.parameter ?? 'exception'}</span>
                    </td>
                    <td className="num">{row.value ?? '—'}</td>
                    <td className="num muted">{row.limit_value ?? '—'}</td>
                    <td className="wrapcell muted">{row.action_taken ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="panel">
        <header>
          <h2>Dialyzer exceptions</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            Below 80% of original total cell volume, or past its reuse count.
          </span>
        </header>
        {dialyzerRows.length === 0 ? (
          <p className="empty">Nothing outstanding.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Unit</th>
                  <th>MRN</th>
                  <th className="num">TCV</th>
                  <th className="num">Uses</th>
                  <th className="num">Max</th>
                  <th>Flag</th>
                </tr>
              </thead>
              <tbody>
                {dialyzerRows.map((row, index) => (
                  <tr key={index}>
                    <td>
                      <strong>{row.label_code ?? '—'}</strong>
                    </td>
                    <td className="muted">{row.mrn ?? '—'}</td>
                    <td className="num">{row.tcv_pct === null ? '—' : `${row.tcv_pct}%`}</td>
                    <td className="num">{row.use_count ?? '—'}</td>
                    <td className="num">{row.max_reuse_count ?? '—'}</td>
                    <td>
                      <span className="chip chip-danger">{row.flag ?? 'exception'}</span>
                    </td>
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
