import { db, setMeta, type OutboxOp } from './db'
import { ulid } from './ulid'

const API = '/api/v1'
const BATCH_SIZE = 100
const MAX_ATTEMPTS = 8

export interface OpResult {
  op_uuid: string
  status: 'applied' | 'duplicate' | 'conflict' | 'rejected'
  server_id?: number | string
  message?: string
  server_state?: Record<string, unknown>
}

/**
 * Headers for every authenticated call.
 *
 * `X-Device-Id` is not optional. A token is bound to the tablet it was issued
 * to, and the server does not merely refuse a mismatch -- it revokes the token,
 * because refusing one leaves a working credential in the wrong hands. A request
 * that omits the header presents no device at all, which is a mismatch, so a
 * single forgetful call signs the nurse out.
 */
function authHeaders(token: string): Record<string, string> {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Device-Id': deviceId(),
  }
}

let flushing = false

/**
 * Drain the outbox. Safe to call concurrently -- the `flushing` guard means
 * a second caller returns immediately rather than sending the same
 * operations twice while the first request is still in flight.
 */
export async function flush(token: string = localStorage.getItem('token') ?? ''): Promise<void> {
  if (flushing || !navigator.onLine) return
  flushing = true

  try {
    for (;;) {
      const batch = await db.outbox
        .where('status')
        .anyOf('pending', 'inflight')
        .limit(BATCH_SIZE)
        .toArray()

      if (batch.length === 0) break

      const batchUuid = ulid()
      await db.transaction('rw', db.outbox, async () => {
        for (const op of batch) {
          await db.outbox.update(op.opUuid, { status: 'inflight', batchUuid })
        }
      })

      const ok = await send(batchUuid, batch, token)

      // Network failure: put everything back and stop. Do NOT drop the batch;
      // the same batchUuid will be reused, and the server deduplicates it.
      if (!ok) {
        await db.transaction('rw', db.outbox, async () => {
          for (const op of batch) {
            const attempts = op.attempts + 1
            await db.outbox.update(op.opUuid, {
              status: attempts >= MAX_ATTEMPTS ? 'rejected' : 'pending',
              attempts,
              lastError: attempts >= MAX_ATTEMPTS ? 'Gave up after repeated failures' : undefined,
            })
          }
        })
        break
      }
    }
  } finally {
    flushing = false
  }
}

async function send(batchUuid: string, batch: OutboxOp[], token: string): Promise<boolean> {
  let response: Response

  try {
    response = await fetch(`${API}/sync`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...authHeaders(token) },
      body: JSON.stringify({
        batch_uuid: batchUuid,
        device_id: deviceId(),
        operations: batch.map((op) => ({
          op_uuid: op.opUuid,
          type: op.type,
          payload: { session_public_id: op.sessionPublicId, ...op.payload },
        })),
      }),
    })
  } catch {
    return false // offline or DNS failure
  }

  // 401 means the token expired while offline. Keep the queue, prompt for
  // a PIN unlock; charting must never be discarded because of auth.
  if (response.status === 401) {
    window.dispatchEvent(new CustomEvent('auth:reauth-required'))
    return false
  }

  if (!response.ok) return false

  const body = (await response.json()) as { results: OpResult[]; received_at: string }

  await db.transaction('rw', db.outbox, db.vitals, db.events, async () => {
    for (const r of body.results) {
      if (r.status === 'applied' || r.status === 'duplicate') {
        await db.outbox.delete(r.op_uuid)
        await db.vitals.where('opUuid').equals(r.op_uuid).modify({ pending: false })
        await db.events.where('opUuid').equals(r.op_uuid).modify({ pending: false })
      } else {
        // conflict / rejected: keep the row so the nurse can see and resolve it
        await db.outbox.update(r.op_uuid, { status: r.status, lastError: r.message })
      }
    }
  })

  await setMeta('lastSyncedAt', body.received_at)

  if (body.results.some((r) => r.status === 'conflict')) {
    window.dispatchEvent(new CustomEvent('sync:conflict', { detail: body.results }))
  }

  return true
}

export function deviceId(): string {
  let id = localStorage.getItem('deviceId')
  if (!id) {
    id = ulid()
    localStorage.setItem('deviceId', id)
  }
  return id
}

/** Pull today's roster and reference data into the local cache. */
export async function bootstrap(date: string, token: string): Promise<void> {
  const res = await fetch(`${API}/sync/bootstrap?date=${date}`, {
    headers: authHeaders(token),
  })
  if (!res.ok) throw new Error(`bootstrap failed: ${res.status}`)

  const data = await res.json()

  await db.transaction('rw', db.sessions, db.meta, async () => {
    await db.sessions.where('sessionDate').equals(date).delete()
    await db.sessions.bulkPut(
      (data.sessions as Record<string, string | number | null>[]).map((s) => ({
        publicId: String(s.public_id),
        patientPublicId: String(s.patient_public_id),
        mrn: String(s.mrn),
        fullName: String(s.full_name),
        cohort: (s.cohort ?? 'clean') as 'clean' | 'hbv' | 'hcv',
        stationCode: (s.station_code as string) ?? null,
        machine: (s.machine as string) ?? null,
        shiftId: (s.shift_id as number) ?? null,
        sessionDate: String(s.session_date).slice(0, 10),
        startedAt: (s.started_at as string) ?? null,
        endedAt: (s.ended_at as string) ?? null,
        status: String(s.status),
        lockedAt: (s.locked_at as string) ?? null,
        plannedDurationMin: (s.planned_duration_min as number) ?? null,
        plannedUfMl: (s.planned_uf_ml as number) ?? null,
        preWeightKg: (s.pre_weight_kg as number) ?? null,
        dryWeightKg: (s.dry_weight_kg as number) ?? null,
      })),
    )
    await setMeta('reference', data.reference)
    await setMeta('bootstrappedAt', data.server_time)
  })
}

// Fallbacks for platforms without Background Sync (notably iOS Safari).
if (typeof window !== 'undefined') {
  window.addEventListener('online', () => void flush())
  window.setInterval(() => void flush(), 30_000)
}
