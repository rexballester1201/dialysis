import { db, type CachedEvent, type CachedVital, type EventSeverity } from './db'
import { enqueue } from './outbox'
import { ulid } from './ulid'

/**
 * The bedside charting API the UI calls.
 *
 * Design target: adding one observation is a single call, completes against
 * IndexedDB in under 50 ms, and pre-fills from the previous row so the nurse
 * changes two or three numbers rather than twelve. That is the difference
 * between a system nurses adopt and one they route around.
 */
export interface VitalInput {
  bpSys?: number
  bpDia?: number
  pulse?: number
  ufVolumeMl?: number
  ufRateMlHr?: number
  bloodFlowMlMin?: number
  venousPressureMmhg?: number
  comment?: string
}

/**
 * Parse a timestamp that came from the server.
 *
 * Everything is stored UTC. A value that carries no zone is therefore UTC, not
 * local -- and `Date.parse('2026-08-20 04:07:21')` reads it as local, which put
 * elapsed treatment time eight hours out on a tablet in Manila and stamped every
 * observation with the wrong minute of the run. The API now sends ISO-8601 with
 * an offset; this stays as the belt to that braces, because the cost of getting
 * it wrong is a flow sheet whose timings are quietly fiction.
 */
export function parseServerTime(value: string): number {
  const hasZone = /(?:Z|[+-]\d{2}:?\d{2})$/.test(value.trim())

  return Date.parse(hasZone ? value : `${value.trim().replace(' ', 'T')}Z`)
}

export async function lastVital(sessionPublicId: string): Promise<CachedVital | undefined> {
  const rows = await db.vitals.where('sessionPublicId').equals(sessionPublicId).toArray()
  return rows.sort((a, b) => a.recordedAt.localeCompare(b.recordedAt)).at(-1)
}

/** Seed the next observation from the previous one; nurses edit the deltas. */
export async function prefill(sessionPublicId: string): Promise<VitalInput> {
  const prev = await lastVital(sessionPublicId)
  if (!prev) return {}

  return {
    bpSys: prev.bpSys ?? undefined,
    bpDia: prev.bpDia ?? undefined,
    pulse: prev.pulse ?? undefined,
    bloodFlowMlMin: prev.bloodFlowMlMin ?? undefined,
    ufRateMlHr: prev.ufRateMlHr ?? undefined,
    venousPressureMmhg: prev.venousPressureMmhg ?? undefined,
  }
}

export async function addVital(
  sessionPublicId: string,
  startedAt: string,
  input: VitalInput,
): Promise<string> {
  const recordedAt = new Date().toISOString()
  const minutesElapsed = Math.round(
    (Date.parse(recordedAt) - parseServerTime(startedAt)) / 60_000,
  )

  const opUuid = await enqueue('vital.append', sessionPublicId, {
    recorded_at: recordedAt.slice(0, 19).replace('T', ' '),
    minutes_elapsed: minutesElapsed,
    bp_sys: input.bpSys ?? null,
    bp_dia: input.bpDia ?? null,
    pulse: input.pulse ?? null,
    uf_volume_ml: input.ufVolumeMl ?? null,
    uf_rate_ml_hr: input.ufRateMlHr ?? null,
    blood_flow_ml_min: input.bloodFlowMlMin ?? null,
    venous_pressure_mmhg: input.venousPressureMmhg ?? null,
    comment: input.comment ?? null,
    source: 'manual',
  })

  // Write the local projection so the chart updates before any network call.
  await db.vitals.add({
    sessionPublicId,
    opUuid,
    recordedAt,
    minutesElapsed,
    bpSys: input.bpSys ?? null,
    bpDia: input.bpDia ?? null,
    pulse: input.pulse ?? null,
    ufVolumeMl: input.ufVolumeMl ?? null,
    ufRateMlHr: input.ufRateMlHr ?? null,
    bloodFlowMlMin: input.bloodFlowMlMin ?? null,
    venousPressureMmhg: input.venousPressureMmhg ?? null,
    comment: input.comment ?? null,
    pending: true,
  })

  return opUuid
}

export async function recordEvent(
  sessionPublicId: string,
  eventCode: string,
  severity: EventSeverity,
  description: string,
  intervention: string,
): Promise<string> {
  const occurredAt = new Date().toISOString()

  const opUuid = await enqueue('event.append', sessionPublicId, {
    occurred_at: occurredAt.slice(0, 19).replace('T', ' '),
    event_code: eventCode,
    severity,
    description,
    intervention,
  })

  // Same local-projection rule as vitals: the chart shows it before any
  // network call, and `pending` clears when the server acknowledges the op.
  await db.events.add({
    sessionPublicId,
    opUuid,
    occurredAt,
    eventCode,
    severity,
    description: description || null,
    intervention: intervention || null,
    pending: true,
  })

  return opUuid
}

/** The flow sheet, oldest first -- the order a paper chart is read in. */
export async function vitalsFor(sessionPublicId: string): Promise<CachedVital[]> {
  const rows = await db.vitals.where('sessionPublicId').equals(sessionPublicId).toArray()
  return rows.sort((a, b) => a.recordedAt.localeCompare(b.recordedAt))
}

export async function eventsFor(sessionPublicId: string): Promise<CachedEvent[]> {
  const rows = await db.events.where('sessionPublicId').equals(sessionPublicId).toArray()
  return rows.sort((a, b) => a.occurredAt.localeCompare(b.occurredAt))
}

/** Idempotency key for a UI action that may be double-tapped. */
export function actionKey(): string {
  return ulid()
}
