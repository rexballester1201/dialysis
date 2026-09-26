import { useCallback, useEffect, useState } from 'react'

import { currentStaff, lock, token, type Staff } from './auth'
import { Board } from './components/Board'
import { FlowSheet } from './components/FlowSheet'
import { SignIn } from './components/SignIn'
import { SyncBar } from './components/SyncBar'
import { bootstrap } from './sync'

/**
 * The bedside app.
 *
 * Two screens: the board, and one patient's flow sheet. Deliberately no router
 * -- a nurse moves between exactly these two places, and a URL that can be
 * bookmarked into a patient's chart is a chart view nobody asked for.
 *
 * Everything the screens render comes from the local cache. The only network
 * calls in the whole app are the bootstrap pull and the outbox flush, both of
 * which can fail without stopping anyone charting.
 */

function today(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')

  return `${now.getFullYear()}-${month}-${day}`
}

export function App() {
  const [staff, setStaff] = useState<Staff | null>(() => (token() === null ? null : currentStaff()))
  const [open, setOpen] = useState<string | null>(null)
  const [date] = useState(today)
  const [pulling, setPulling] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)

  const pull = useCallback(async () => {
    const current = token()
    if (current === null) return

    setPulling(true)
    setProblem(null)

    try {
      await bootstrap(date, current)
    } catch {
      // A failed pull is not an outage. Whatever is already cached still works,
      // and charting was never waiting on this.
      setProblem('Could not refresh the board. Charting continues on what is already here.')
    } finally {
      setPulling(false)
    }
  }, [date])

  // Pull the day's board once signed in.
  useEffect(() => {
    if (staff !== null) void pull()
  }, [staff, pull])

  /**
   * The token expired while offline. Drop it and show the PIN prompt -- the
   * outbox is untouched, so the queue replays after unlocking.
   */
  useEffect(() => {
    const reauth = () => {
      lock()
      setStaff(null)
    }

    window.addEventListener('auth:reauth-required', reauth)

    return () => window.removeEventListener('auth:reauth-required', reauth)
  }, [])

  if (staff === null) {
    return (
      <div className="app">
        <SignIn onSignedIn={setStaff} />
      </div>
    )
  }

  return (
    <div className="app">
      <header className="topbar">
        <h1>Bedside</h1>
        <span className="pill pill-quiet">{date}</span>
        <span className="spacer" />
        <SyncBar />
        <button type="button" className="btn-link" onClick={() => void pull()} disabled={pulling}>
          {pulling ? 'Refreshing…' : 'Refresh board'}
        </button>
        <button
          type="button"
          className="btn-link"
          onClick={() => {
            lock()
            setStaff(null)
          }}
        >
          Lock ({staff.fullName})
        </button>
      </header>

      <main className="content">
        {problem === null ? null : <p className="notice">{problem}</p>}

        {open === null ? (
          <Board date={date} onOpen={setOpen} />
        ) : (
          <FlowSheet sessionPublicId={open} onBack={() => setOpen(null)} />
        )}
      </main>
    </div>
  )
}
