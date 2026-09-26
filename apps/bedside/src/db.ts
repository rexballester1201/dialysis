import Dexie, { type Table } from 'dexie'

/**
 * Local mirror for the bedside tablet.
 *
 * Two kinds of store live here and they must not be confused:
 *   - `cache` tables are a read-only projection of the server, replaced
 *     wholesale on bootstrap. Never treat them as a source of truth.
 *   - `outbox` is the only authoritative local state. Anything the nurse
 *     types exists there first and is considered unsaved until the server
 *     has acknowledged it by op_uuid.
 */

export type OpType =
  | 'session.upsert'
  | 'vital.append'
  | 'event.append'
  | 'note.append'
  | 'med.administer'

export type OpStatus = 'pending' | 'inflight' | 'applied' | 'duplicate' | 'conflict' | 'rejected'

export interface OutboxOp {
  opUuid: string            // ULID, minted on the device; the idempotency key
  type: OpType
  sessionPublicId: string
  payload: Record<string, unknown>
  createdAt: string         // device clock, ISO-8601
  status: OpStatus
  attempts: number
  lastError?: string
  batchUuid?: string
}

export type Cohort = 'clean' | 'hbv' | 'hcv'

export interface CachedSession {
  publicId: string
  patientPublicId: string
  mrn: string
  fullName: string
  cohort: Cohort
  stationCode: string | null
  machine: string | null
  shiftId: number | null
  sessionDate: string
  status: string
  /**
   * Elapsed time on the flow sheet is measured from here, so it has to be in
   * the cache: a tablet that lost the network at 07:00 still has to chart
   * "minute 120" correctly at 09:00.
   */
  startedAt: string | null
  endedAt: string | null
  lockedAt: string | null
  plannedDurationMin: number | null
  plannedUfMl: number | null
  preWeightKg: number | null
  dryWeightKg: number | null
}

export interface CachedVital {
  localId?: number
  sessionPublicId: string
  opUuid: string
  recordedAt: string
  minutesElapsed: number | null
  bpSys: number | null
  bpDia: number | null
  pulse: number | null
  ufVolumeMl: number | null
  ufRateMlHr: number | null
  bloodFlowMlMin: number | null
  venousPressureMmhg: number | null
  comment: string | null
  pending: boolean          // still in the outbox
}

export type EventSeverity = 'minor' | 'moderate' | 'severe' | 'life_threatening'

export interface CachedEvent {
  localId?: number
  sessionPublicId: string
  opUuid: string
  occurredAt: string
  eventCode: string
  severity: EventSeverity
  description: string | null
  intervention: string | null
  pending: boolean          // still in the outbox
}

export interface Meta {
  key: string
  value: unknown
}

class BedsideDb extends Dexie {
  outbox!: Table<OutboxOp, string>
  sessions!: Table<CachedSession, string>
  vitals!: Table<CachedVital, number>
  events!: Table<CachedEvent, number>
  meta!: Table<Meta, string>

  constructor() {
    super('dialysis-bedside')
    this.version(1).stores({
      outbox: 'opUuid, status, sessionPublicId, createdAt',
      sessions: 'publicId, sessionDate, shiftId, stationCode',
      vitals: '++localId, sessionPublicId, opUuid, recordedAt, pending',
      meta: 'key',
    })

    // v2 adds the local projection for events. Vitals and events are both
    // append-only, so the same shape applies to each.
    this.version(2).stores({
      events: '++localId, sessionPublicId, opUuid, occurredAt, pending',
    })
  }
}

export const db = new BedsideDb()

export async function getMeta<T>(key: string, fallback: T): Promise<T> {
  const row = await db.meta.get(key)
  return (row?.value as T) ?? fallback
}

export async function setMeta(key: string, value: unknown): Promise<void> {
  await db.meta.put({ key, value })
}
