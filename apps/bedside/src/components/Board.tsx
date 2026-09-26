import { useLiveQuery } from 'dexie-react-hooks'

import { db, type CachedSession, type Cohort } from '../db'

/**
 * Today's chairs, read from the local cache rather than the network.
 *
 * A nurse arriving at a chair with no signal still sees who is in it. The board
 * is a projection of the server and is never written to -- the outbox is the
 * only authoritative local state.
 */

const COHORT_LABEL: Record<Cohort, string> = {
  clean: 'Clean',
  hbv: 'HBV',
  hcv: 'HCV',
}

function chairClass(session: CachedSession): string {
  if (session.lockedAt !== null) return 'chair chair-locked'
  if (session.status === 'in_progress') return 'chair chair-running'

  return 'chair'
}

function statusLabel(session: CachedSession): string {
  if (session.lockedAt !== null) return 'Signed — locked'

  switch (session.status) {
    case 'scheduled':
      return 'Not arrived'
    case 'checked_in':
      return 'Checked in'
    case 'in_progress':
      return 'Running'
    case 'completed':
      return 'Off — awaiting signature'
    default:
      return session.status
  }
}

export function Board({
  date,
  onOpen,
}: {
  date: string
  onOpen: (publicId: string) => void
}) {
  const sessions = useLiveQuery(
    async () => {
      const rows = await db.sessions.where('sessionDate').equals(date).toArray()

      // Station order is how the unit is walked, so it is how the board reads.
      return rows.sort((a, b) => (a.stationCode ?? '~').localeCompare(b.stationCode ?? '~'))
    },
    [date],
    undefined,
  )

  if (sessions === undefined) {
    return <p className="hint">Loading the board…</p>
  }

  if (sessions.length === 0) {
    return (
      <div className="section">
        <div className="emptystate">
          Nothing cached for {date}. Pull the day’s board while you have a connection —
          once it is on the tablet, charting no longer needs one.
        </div>
      </div>
    )
  }

  return (
    <div className="board">
      {sessions.map((session) => (
        <button
          key={session.publicId}
          type="button"
          className={chairClass(session)}
          onClick={() => onOpen(session.publicId)}
        >
          <span className="chair-head">
            <span className="chair-station">{session.stationCode ?? '—'}</span>
            <span className="chair-name">{session.fullName}</span>
          </span>

          <span className="chair-meta">
            <span className={`pill pill-cohort-${session.cohort}`}>
              {COHORT_LABEL[session.cohort]}
            </span>
            <span className="pill pill-quiet">{statusLabel(session)}</span>
          </span>

          <span className="chair-meta">
            <span>{session.mrn}</span>
            {session.machine === null ? null : <span>Machine {session.machine}</span>}
          </span>
        </button>
      ))}
    </div>
  )
}
