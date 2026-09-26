import type { Board } from '@dialysis/api-client'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { Cohort, StatusChip } from '../components/Chips'

/**
 * The day's chairs.
 *
 * Generating a board is idempotent on the server -- it builds the day from the
 * standing patterns and skips anything already scheduled -- so the button is
 * safe to press twice. It still says how many it created, because "nothing
 * happened" and "nothing needed to happen" are different answers and a clerk
 * deserves to know which one they got.
 */

function today(): string {
  const now = new Date()

  return [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, '0'),
    String(now.getDate()).padStart(2, '0'),
  ].join('-')
}

export function BoardView({ onOpenSession }: { onOpenSession: (publicId: string) => void }) {
  const [date, setDate] = useState(today)
  const [board, setBoard] = useState<Board | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [result, setResult] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async (on: string) => {
    setProblem(null)

    try {
      setBoard(await api.board(on))
    } catch (error) {
      setProblem(explain(error))
      setBoard(null)
    }
  }, [])

  useEffect(() => {
    void load(date)
  }, [date, load])

  async function generate() {
    setBusy(true)
    setProblem(null)
    setResult(null)

    try {
      const generated = await api.generateBoard(date)
      const created = Number(generated.created ?? 0)

      setResult(
        created === 0
          ? 'Nothing to add — every standing slot for this date is already on the board.'
          : `Added ${created} session${created === 1 ? '' : 's'} from the standing patterns.`,
      )

      await load(date)
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  const sessions = board?.sessions ?? []
  const byShift = new Map<string, typeof sessions>()

  for (const entry of sessions) {
    const shift = entry.shift_code ?? 'Unassigned'
    byShift.set(shift, [...(byShift.get(shift) ?? []), entry])
  }

  return (
    <>
      <div className="pagehead">
        <h1>Daily board</h1>
      </div>
      <p className="subtle">Every chair for the day, grouped by shift and ordered as the unit is walked.</p>

      <div className="panel">
        <header>
          <div className="field" style={{ minWidth: 0 }}>
            <label htmlFor="board-date">Date</label>
            <input
              id="board-date"
              type="date"
              value={date}
              onChange={(event) => setDate(event.target.value)}
            />
          </div>
          <span className="grow" />
          <button type="button" className="btn-primary" onClick={() => void generate()} disabled={busy}>
            {busy ? 'Generating…' : 'Generate from standing patterns'}
          </button>
        </header>

        <div className="panelbody">
          {problem === null ? null : <p className="problem">{problem}</p>}
          {result === null ? null : <p className="good">{result}</p>}

          <div className="stats" style={{ marginBottom: 0 }}>
            <div className="stat">
              <span className="label">Chairs</span>
              <span className="value">{sessions.length}</span>
            </div>
            <div className="stat">
              <span className="label">Running</span>
              <span className="value">{sessions.filter((s) => s.status === 'in_progress').length}</span>
            </div>
            <div className="stat">
              <span className="label">Not arrived</span>
              <span className="value">{sessions.filter((s) => s.status === 'scheduled').length}</span>
            </div>
            <div className="stat">
              <span className="label">Completed</span>
              <span className="value">{sessions.filter((s) => s.status === 'completed').length}</span>
            </div>
          </div>
        </div>
      </div>

      {sessions.length === 0 ? (
        <div className="panel">
          <p className="empty">
            Nothing on the board for {date}. If patients hold standing patterns for this weekday,
            generate it above.
          </p>
        </div>
      ) : (
        [...byShift.entries()].map(([shift, entries]) => (
          <div className="panel" key={shift}>
            <header>
              <h2>{shift}</h2>
              <span className="grow" />
              <span className="chip chip-quiet">
                {entries.length} chair{entries.length === 1 ? '' : 's'}
              </span>
            </header>
            <div className="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>Chair</th>
                    <th>Patient</th>
                    <th>MRN</th>
                    <th>Cohort</th>
                    <th>Machine</th>
                    <th>Nurse</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {[...entries]
                    .sort((a, b) => (a.station_code ?? '~').localeCompare(b.station_code ?? '~'))
                    .map((entry) => (
                      <tr
                        key={entry.public_id}
                        className="clickable"
                        onClick={() => onOpenSession(entry.public_id)}
                      >
                        <td>
                          <strong>{entry.station_code ?? '—'}</strong>
                        </td>
                        <td>{entry.full_name}</td>
                        <td className="muted">{entry.mrn}</td>
                        <td>
                          <Cohort value={entry.cohort} />
                        </td>
                        <td className="muted">{entry.machine ?? '—'}</td>
                        <td className="muted">{entry.primary_nurse ?? '—'}</td>
                        <td>
                          <StatusChip status={entry.status} />
                        </td>
                      </tr>
                    ))}
                </tbody>
              </table>
            </div>
          </div>
        ))
      )}
    </>
  )
}
