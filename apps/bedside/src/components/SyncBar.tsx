import { useLiveQuery } from 'dexie-react-hooks'
import { useEffect, useState } from 'react'

import { db } from '../db'
import { requestSync } from '../outbox'

/**
 * The one thing a nurse must be able to trust at a glance.
 *
 * "Saved" and "synced" are deliberately separate here. Charting is saved the
 * moment it is typed, whether or not the network exists -- so the queue count
 * is not an error state and is never styled as one. What it is is the answer to
 * "can I put this tablet down yet?", which is why it is on every screen.
 */
export function SyncBar() {
  const [online, setOnline] = useState(navigator.onLine)

  const queued = useLiveQuery(
    () => db.outbox.where('status').anyOf('pending', 'inflight').count(),
    [],
    0,
  )

  const stuck = useLiveQuery(
    () => db.outbox.where('status').anyOf('conflict', 'rejected').count(),
    [],
    0,
  )

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

  return (
    <div className="syncbar">
      <span className={online ? 'dot dot-online' : 'dot dot-offline'} aria-hidden="true" />
      <span>{online ? 'Online' : 'Offline'}</span>

      {queued > 0 ? (
        <span className="pill pill-pending">
          {queued} to send
        </span>
      ) : (
        <span className="pill pill-ok">All sent</span>
      )}

      {stuck > 0 ? <span className="pill pill-danger">{stuck} need attention</span> : null}

      {online && queued > 0 ? (
        <button type="button" className="btn-link" onClick={() => void requestSync()}>
          Send now
        </button>
      ) : null}
    </div>
  )
}
