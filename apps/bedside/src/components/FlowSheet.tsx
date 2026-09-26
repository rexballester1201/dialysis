import { idwgKg, ufRateIsOfConcern, ufRateMlPerKgPerHour } from '@dialysis/domain'
import { useConfirm } from '@dialysis/ui'
import { useLiveQuery } from 'dexie-react-hooks'

import { db, type CachedSession, type Cohort } from '../db'
import { eventsFor, parseServerTime, vitalsFor } from '../flowsheet'
import { requestSync } from '../outbox'
import { EventForm } from './EventForm'
import { Lifecycle } from './Lifecycle'
import { ObservationForm } from './ObservationForm'

/**
 * The flow sheet: the document this whole system exists to replace.
 *
 * Everything on this screen is read from the local cache. A nurse standing at a
 * chair with no signal sees the full chart, adds to it, and the tablet reconciles
 * later -- which is the entire premise of the bedside app.
 *
 * Rows still in the outbox are marked, never hidden. "Saved" and "sent" are
 * different facts and a nurse is entitled to both.
 */

const COHORT_LABEL: Record<Cohort, string> = { clean: 'Clean', hbv: 'HBV', hcv: 'HCV' }

/**
 * Wall-clock time for the nurse reading the chart.
 *
 * Goes through parseServerTime so a naive server timestamp is read as UTC and
 * then rendered in the tablet's own zone -- the one the nurse's watch shows.
 */
function clock(value: string): string {
  const date = new Date(parseServerTime(value))

  return Number.isNaN(date.getTime())
    ? '—'
    : `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`
}

function num(value: number | null): string {
  return value === null ? '' : String(value)
}

/** Charting is closed once the record is signed; the amendment path takes over. */
function closedReason(session: CachedSession): string | null {
  if (session.lockedAt !== null) {
    return 'Signed and locked. Corrections go through the amendment path, not the flow sheet.'
  }

  if (session.status === 'scheduled' || session.status === 'checked_in') {
    return 'The treatment has not been started, so there is no clock to chart against. Check in and start it above.'
  }

  return null
}

export function FlowSheet({
  sessionPublicId,
  onBack,
}: {
  sessionPublicId: string
  onBack: () => void
}) {
  const confirmAction = useConfirm()

  const session = useLiveQuery(() => db.sessions.get(sessionPublicId), [sessionPublicId], undefined)
  const vitals = useLiveQuery(() => vitalsFor(sessionPublicId), [sessionPublicId], [])
  const events = useLiveQuery(() => eventsFor(sessionPublicId), [sessionPublicId], [])

  const stuck = useLiveQuery(
    () =>
      db.outbox
        .where('sessionPublicId')
        .equals(sessionPublicId)
        .filter((op) => op.status === 'conflict' || op.status === 'rejected')
        .toArray(),
    [sessionPublicId],
    [],
  )

  if (session === undefined) {
    return (
      <div className="section">
        <div className="emptystate">
          That session is not on this tablet. Go back to the board and pull today’s list.
        </div>
      </div>
    )
  }

  const closed = closedReason(session)
  const idwg =
    session.preWeightKg !== null && session.dryWeightKg !== null
      ? idwgKg(session.preWeightKg, session.dryWeightKg)
      : null

  // The planned UF rate, checked before the needle goes in rather than after.
  // Flythe 2011 concerns itself with the achieved rate; a plan that already
  // exceeds the threshold is worth saying out loud at the start of the run.
  const plannedRate =
    session.plannedUfMl !== null &&
    session.dryWeightKg !== null &&
    session.plannedDurationMin !== null &&
    session.plannedDurationMin > 0
      ? ufRateMlPerKgPerHour(
          session.plannedUfMl,
          session.dryWeightKg,
          session.plannedDurationMin / 60,
        )
      : null

  async function discard(opUuid: string) {
    const answer = await confirmAction({
      title: 'Discard this charting?',
      body:
        'It never reached the server, so discarding destroys it. If it was refused for a reason ' +
        'you can fix, fix it and chart it again instead.',
      confirmLabel: 'Discard permanently',
      cancelLabel: 'Keep it',
      tone: 'danger',
    })

    if (!answer.confirmed) return

    await db.outbox.delete(opUuid)
    await db.vitals.where('opUuid').equals(opUuid).delete()
    await db.events.where('opUuid').equals(opUuid).delete()
  }

  return (
    <>
      <div className="actions" style={{ marginBottom: 12 }}>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← Board
        </button>
      </div>

      <div className="sheet-head">
        <h2>{session.fullName}</h2>
        <span className={`pill pill-cohort-${session.cohort}`}>{COHORT_LABEL[session.cohort]}</span>
        <span className="pill pill-quiet">{session.mrn}</span>
        {session.stationCode === null ? null : (
          <span className="pill pill-quiet">Chair {session.stationCode}</span>
        )}
        {session.machine === null ? null : (
          <span className="pill pill-quiet">Machine {session.machine}</span>
        )}
        {session.lockedAt === null ? null : <span className="pill pill-danger">Signed — locked</span>}
      </div>

      <div className="sheet-head">
        <div className="stat">
          <dt>Started</dt>
          <dd>{session.startedAt === null ? '—' : clock(session.startedAt)}</dd>
        </div>
        <div className="stat">
          <dt>Planned</dt>
          <dd>
            {session.plannedDurationMin === null ? '—' : `${session.plannedDurationMin} min`}
          </dd>
        </div>
        <div className="stat">
          <dt>Pre-weight</dt>
          <dd>{session.preWeightKg === null ? '—' : `${session.preWeightKg} kg`}</dd>
        </div>
        <div className="stat">
          <dt>Dry weight</dt>
          <dd>{session.dryWeightKg === null ? '—' : `${session.dryWeightKg} kg`}</dd>
        </div>
        <div className="stat">
          <dt>IDWG</dt>
          <dd>{idwg === null ? '—' : `${idwg.toFixed(1)} kg`}</dd>
        </div>
        <div className="stat">
          <dt>UF goal</dt>
          <dd>{session.plannedUfMl === null ? '—' : `${session.plannedUfMl} ml`}</dd>
        </div>
        <div className="stat">
          <dt>Planned UF rate</dt>
          <dd className={plannedRate !== null && ufRateIsOfConcern(plannedRate) ? 'concern' : ''}>
            {plannedRate === null ? '—' : `${plannedRate.toFixed(1)} ml/kg/h`}
          </dd>
        </div>
      </div>

      {plannedRate !== null && ufRateIsOfConcern(plannedRate) ? (
        <p className="notice">
          The planned ultrafiltration rate is above 13 ml/kg/h. That is a marker for review, not a
          refusal — the clinical call is yours.
        </p>
      ) : null}

      <Lifecycle session={session} />

      {stuck.length > 0 ? (
        <div className="section">
          <h3>Needs attention</h3>
          {stuck.map((op) => (
            <div className="eventrow" key={op.opUuid}>
              <span className="eventtime">{clock(op.createdAt)}</span>
              <div className="eventbody">
                <span className={op.status === 'conflict' ? 'pill pill-danger' : 'pill pill-danger'}>
                  {op.status === 'conflict' ? 'Conflict' : 'Refused'}
                </span>
                <p>{op.lastError ?? 'The server would not accept this entry.'}</p>
                <div className="actions">
                  <button type="button" className="btn-quiet" onClick={() => void requestSync()}>
                    Try again
                  </button>
                  <button
                    type="button"
                    className="btn-danger"
                    onClick={() => void discard(op.opUuid)}
                  >
                    Discard
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      ) : null}

      <div className="section">
        <h3>Observations</h3>
        {vitals.length === 0 ? (
          <div className="emptystate">Nothing charted yet.</div>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th className="left">Time</th>
                  <th>Min</th>
                  <th>BP</th>
                  <th>Pulse</th>
                  <th>Blood flow</th>
                  <th>Venous P</th>
                  <th>UF rate</th>
                  <th>UF total</th>
                  <th className="left">Comment</th>
                </tr>
              </thead>
              <tbody>
                {vitals.map((vital) => (
                  <tr key={vital.opUuid} className={vital.pending ? 'pending' : ''}>
                    <td className="left">
                      {clock(vital.recordedAt)}
                      {vital.pending ? (
                        <span className="rowflag" title="Saved here, not sent yet" />
                      ) : null}
                    </td>
                    <td>{vital.minutesElapsed ?? ''}</td>
                    <td>
                      {vital.bpSys === null && vital.bpDia === null
                        ? ''
                        : `${num(vital.bpSys)}/${num(vital.bpDia)}`}
                    </td>
                    <td>{num(vital.pulse)}</td>
                    <td>{num(vital.bloodFlowMlMin)}</td>
                    <td>{num(vital.venousPressureMmhg)}</td>
                    <td>{num(vital.ufRateMlHr)}</td>
                    <td>{num(vital.ufVolumeMl)}</td>
                    <td className="left">{vital.comment ?? ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="section">
        <h3>Add an observation</h3>
        <ObservationForm
          sessionPublicId={sessionPublicId}
          startedAt={session.startedAt}
          disabled={closed !== null}
          disabledReason={closed ?? undefined}
        />
      </div>

      <div className="section">
        <h3>Events</h3>
        {events.length === 0 ? (
          <div className="emptystate">No events this run.</div>
        ) : (
          events.map((event) => (
            <div className="eventrow" key={event.opUuid}>
              <span className="eventtime">{clock(event.occurredAt)}</span>
              <div className="eventbody">
                <span className={`pill sev-${event.severity}`}>
                  {event.eventCode.replace(/_/g, ' ')}
                  {event.pending ? ' · not sent' : ''}
                </span>
                {event.description === null ? null : <p>{event.description}</p>}
                {event.intervention === null ? null : <p>Did: {event.intervention}</p>}
              </div>
            </div>
          ))
        )}
      </div>

      <div className="section">
        <h3>Record an event</h3>
        <EventForm
          sessionPublicId={sessionPublicId}
          disabled={closed !== null}
          onDone={() => undefined}
        />
      </div>
    </>
  )
}
