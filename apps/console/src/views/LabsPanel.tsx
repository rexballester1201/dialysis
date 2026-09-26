import type { LabOrder, LabResult, LabTest } from '@dialysis/api-client'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'

/**
 * A patient's bloods.
 *
 * The whole design problem here is one distinction. Every result has two ranges:
 * the laboratory's reference interval for a general population, and the target
 * a dialysed patient should sit in. They frequently disagree — haemoglobin 11
 * g/dL is *low* against 12–16 and exactly right against a target of 10–11.5.
 *
 * A screen that shows only the reference flag turns a normal monthly panel red
 * and teaches staff that red means nothing, which is worse than showing no flag
 * at all. So the two are shown side by side and never merged, and the target
 * column is the one given the visual weight, because that is the one the unit
 * acts on.
 */

const PANELS = ['monthly', 'quarterly'] as const

/**
 * Values arrive as DECIMAL(14,4) strings, so a haemoglobin reads "11.0000".
 * Trailing zeros are trimmed off the string rather than by parsing to a number:
 * nothing clinical in this repo round-trips through a float, and this is only
 * ever display anyway.
 */
function trim(value: string | number): string {
  const text = String(value)

  if (! text.includes('.')) return text

  return text.replace(/0+$/, '').replace(/\.$/, '')
}

function num(value: string | number | null | undefined): string {
  return value === null || value === undefined ? '—' : trim(value)
}

function rangeLabel(low: unknown, high: unknown, unit: string | null | undefined): string {
  if ((low === null || low === undefined) && (high === null || high === undefined)) return '—'

  const l = low === null || low === undefined ? '' : trim(low as string | number)
  const h = high === null || high === undefined ? '' : trim(high as string | number)
  const suffix = unit === null || unit === undefined || unit === '' ? '' : ` ${unit}`

  if (l !== '' && h !== '') return `${l}–${h}${suffix}`

  return l !== '' ? `≥ ${l}${suffix}` : `≤ ${h}${suffix}`
}

function FlagChip({ flag }: { flag: string | null | undefined }) {
  if (flag === null || flag === undefined) return <span className="muted">—</span>

  const tone = flag === 'N' ? 'ok' : flag === 'L' || flag === 'H' ? 'warn' : 'danger'
  const label = { L: 'Low', H: 'High', N: 'Normal', LL: 'Crit low', HH: 'Crit high' }[flag] ?? flag

  return <span className={`chip chip-${tone}`}>{label}</span>
}

function TargetChip({ onTarget }: { onTarget: boolean | null | undefined }) {
  // null is "this test has no target", not "it passed". Saying "on target" for a
  // test with no target would be inventing a clinical judgement.
  if (onTarget === null || onTarget === undefined) {
    return <span className="muted">no target</span>
  }

  return onTarget
    ? <span className="chip chip-ok">On target</span>
    : <span className="chip chip-danger">Off target</span>
}

export function LabsPanel({ publicId }: { publicId: string }) {
  const [tests, setTests] = useState<LabTest[]>([])
  const [latest, setLatest] = useState<LabResult[]>([])
  const [orders, setOrders] = useState<LabOrder[]>([])
  const [problem, setProblem] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [filing, setFiling] = useState(false)
  const [trendOf, setTrendOf] = useState<string | null>(null)
  const [trend, setTrend] = useState<LabResult[]>([])

  const load = useCallback(async () => {
    setProblem(null)

    const [t, l, o] = await Promise.allSettled([
      api.labTests(),
      api.labResults(publicId, { latest: true }),
      api.labOrders(publicId),
    ])

    if (t.status === 'fulfilled') setTests(t.value.tests)
    if (l.status === 'fulfilled') setLatest(l.value.results)
    if (o.status === 'fulfilled') setOrders(o.value.orders)

    const failed = [t, l, o].find((r) => r.status === 'rejected')
    if (failed !== undefined && failed.status === 'rejected') setProblem(explain(failed.reason))
  }, [publicId])

  useEffect(() => {
    void load()
  }, [load])

  async function run(message: string, action: () => Promise<unknown>) {
    setBusy(true)
    setProblem(null)
    setDone(null)

    try {
      await action()
      await load()
      setDone(message)
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  async function showTrend(code: string) {
    if (trendOf === code) {
      setTrendOf(null)
      setTrend([])

      return
    }

    try {
      const history = await api.labResults(publicId, { testCode: code })
      setTrendOf(code)
      setTrend(history.results)
    } catch (error) {
      setProblem(explain(error))
    }
  }

  const openOrder = orders.find((o) => o.status === 'ordered' || o.status === 'collected')

  return (
    <>
      <div className="panel">
        <header>
          <h2>Bloods</h2>
          <span className="grow" />
          {PANELS.map((panel) => (
            <button
              key={panel}
              type="button"
              className="btn-quiet"
              disabled={busy}
              onClick={() => void run(`${panel} panel ordered.`, () => api.orderLabPanel(publicId, panel))}
            >
              Order {panel}
            </button>
          ))}
          <button type="button" className="btn-primary" onClick={() => setFiling(!filing)}>
            {filing ? 'Close' : 'File results'}
          </button>
        </header>

        <div className="panelbody">
          {problem === null ? null : <p className="problem">{problem}</p>}
          {done === null ? null : <p className="good">{done}</p>}

          {openOrder === undefined ? null : (
            <p className="notice">
              An order from {openOrder.ordered_on} is <strong>{openOrder.status}</strong>
              {openOrder.status === 'ordered' ? (
                <>
                  {' — '}
                  <button
                    type="button"
                    className="btn-link"
                    disabled={busy}
                    onClick={() => void run('Marked collected.', () => api.transitionLabOrder(openOrder.id, 'collected'))}
                  >
                    mark the specimen collected
                  </button>
                </>
              ) : ' — filing results below will close it.'}
            </p>
          )}

          {latest.length === 0 ? (
            <p className="muted" style={{ margin: 0 }}>
              No results on file. Ordering a panel does not create results; someone files what the
              laboratory returns.
            </p>
          ) : null}
        </div>

        {latest.length === 0 ? null : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Test</th>
                  <th className="num">Value</th>
                  <th>Reference</th>
                  <th>vs reference</th>
                  <th>Dialysis target</th>
                  <th>vs target</th>
                  <th>Specimen</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {latest.map((row) => (
                  <tr key={row.id}>
                    <td>
                      <strong>{row.name ?? row.test_code}</strong>
                      {row.timing === null || row.timing === undefined ? null : (
                        <span className="muted"> ({row.timing.replace(/_/g, ' ')})</span>
                      )}
                    </td>
                    <td className="num">
                      <strong>{num(row.value_num ?? row.value_text)}</strong>
                      <span className="muted"> {row.unit ?? ''}</span>
                    </td>
                    <td className="muted">{rangeLabel(row.ref_low, row.ref_high, row.unit)}</td>
                    <td><FlagChip flag={row.abnormal_flag} /></td>
                    <td className="muted">{rangeLabel(row.target_low, row.target_high, row.unit)}</td>
                    <td><TargetChip onTarget={row.on_target} /></td>
                    <td className="muted">{row.specimen_date}</td>
                    <td>
                      <button type="button" className="btn-link" onClick={() => void showTrend(row.test_code)}>
                        {trendOf === row.test_code ? 'hide' : 'trend'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {trendOf === null ? null : (
        <div className="panel">
          <header>
            <h2>{trend[0]?.name ?? trendOf} — history</h2>
          </header>
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Specimen</th>
                  <th className="num">Value</th>
                  <th>vs reference</th>
                  <th>vs target</th>
                  <th>Source</th>
                </tr>
              </thead>
              <tbody>
                {trend.map((row) => (
                  <tr key={row.id}>
                    <td className="muted">{row.specimen_date}</td>
                    <td className="num">
                      <strong>{num(row.value_num ?? row.value_text)}</strong>
                      <span className="muted"> {row.unit ?? ''}</span>
                    </td>
                    <td><FlagChip flag={row.abnormal_flag} /></td>
                    <td><TargetChip onTarget={row.on_target} /></td>
                    <td className="muted">{row.source ?? 'manual'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {filing ? (
        <FilingForm
          publicId={publicId}
          tests={tests}
          orderId={openOrder?.status === 'collected' ? openOrder.id : undefined}
          busy={busy}
          onFiled={(message) => {
            setFiling(false)
            void run(message, async () => undefined)
          }}
          onProblem={setProblem}
        />
      ) : null}
    </>
  )
}

/**
 * Typing a panel off a laboratory report.
 *
 * One specimen date for the sheet, because that is how the report is printed —
 * asking for it twelve times invites twelve chances to mistype it. Blank rows
 * are skipped rather than rejected: a monthly panel rarely comes back complete.
 */
function FilingForm({
  publicId,
  tests,
  orderId,
  busy,
  onFiled,
  onProblem,
}: {
  publicId: string
  tests: LabTest[]
  orderId?: number
  busy: boolean
  onFiled: (message: string) => void
  onProblem: (message: string) => void
}) {
  const [panel, setPanel] = useState<string>('monthly')
  const [specimenDate, setSpecimenDate] = useState('')
  const [values, setValues] = useState<Record<string, string>>({})
  const [verdicts, setVerdicts] = useState<{ test_code: string; status: string; message?: string | null }[]>([])
  const [saving, setSaving] = useState(false)

  const inPanel = tests.filter((t) => t.panel === panel)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setVerdicts([])

    const rows = inPanel
      .filter((t) => (values[t.code] ?? '').trim() !== '')
      .map((t) => ({
        test_code: t.code,
        value_num: Number(values[t.code]),
        specimen_date: specimenDate,
      }))

    if (rows.length === 0) {
      onProblem('Nothing to file — enter at least one value.')

      return
    }

    setSaving(true)

    try {
      const filing = await api.fileLabResults(publicId, rows, orderId)
      setVerdicts(filing.results)

      if (filing.results.every((r) => r.status === 'filed')) {
        onFiled(`Filed ${filing.filed} result${filing.filed === 1 ? '' : 's'}.`)
      }
    } catch (error) {
      onProblem(explain(error))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="panel">
      <header>
        <h2>File results</h2>
        <span className="grow" />
        {orderId === undefined ? null : <span className="chip chip-quiet">against order #{orderId}</span>}
      </header>

      <form className="panelbody" onSubmit={(event) => void submit(event)}>
        <div className="fields">
          <div className="field">
            <label htmlFor="lab-panel">Panel</label>
            <select id="lab-panel" value={panel} onChange={(e) => setPanel(e.target.value)}>
              {PANELS.map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
          </div>
          <div className="field">
            <label htmlFor="lab-date">Specimen date</label>
            <input
              id="lab-date"
              type="date"
              value={specimenDate}
              onChange={(e) => setSpecimenDate(e.target.value)}
              required
            />
          </div>
        </div>

        <p className="subtle" style={{ margin: '14px 0 8px' }}>
          Leave a test blank to skip it. The abnormal flag is worked out from the reference interval
          when the result is filed — it is not something you enter.
        </p>

        <div className="fields">
          {inPanel.map((test) => (
            <div className="field" key={test.code}>
              <label htmlFor={`lab-${test.code}`}>
                {test.name}{test.unit === null || test.unit === undefined || test.unit === '' ? '' : ` (${test.unit})`}
              </label>
              <input
                id={`lab-${test.code}`}
                inputMode="decimal"
                autoComplete="off"
                value={values[test.code] ?? ''}
                onChange={(e) => setValues((c) => ({ ...c, [test.code]: e.target.value }))}
              />
            </div>
          ))}
        </div>

        {verdicts.length === 0 ? null : (
          <div style={{ marginTop: 14 }}>
            {verdicts.filter((v) => v.status !== 'filed').map((v) => (
              <p className="notice" key={v.test_code} style={{ marginBottom: 8 }}>
                <strong>{v.test_code}</strong> — {v.status}: {v.message ?? ''}
              </p>
            ))}
            <p className="good">
              {verdicts.filter((v) => v.status === 'filed').length} filed. The rest are listed above
              and were left alone.
            </p>
          </div>
        )}

        <div className="actions">
          <button type="submit" className="btn-primary" disabled={saving || busy}>
            {saving ? 'Filing…' : 'File results'}
          </button>
        </div>
      </form>
    </div>
  )
}
