import { db, type OpType, type OutboxOp } from './db'
import { ulid } from './ulid'

/**
 * Every clinical write goes through here. The UI never calls the network
 * directly, so "saved" and "synced" stay separate concepts: the nurse sees
 * the observation appear instantly, and a small badge tells them how many
 * items are still queued.
 */
export async function enqueue(
  type: OpType,
  sessionPublicId: string,
  payload: Record<string, unknown>,
): Promise<string> {
  const op: OutboxOp = {
    opUuid: ulid(),
    type,
    sessionPublicId,
    payload,
    createdAt: new Date().toISOString(),
    status: 'pending',
    attempts: 0,
  }

  await db.outbox.add(op)
  void requestSync()
  return op.opUuid
}

export async function pendingCount(): Promise<number> {
  return db.outbox.where('status').anyOf('pending', 'inflight').count()
}

export async function conflicts(): Promise<OutboxOp[]> {
  return db.outbox.where('status').equals('conflict').toArray()
}

/**
 * Background Sync where it exists (Chrome/Android, which is what these
 * tablets run). iOS Safari has no Background Sync, so the fallback is a
 * foreground interval plus an `online` listener -- documented, not silent.
 */
export async function requestSync(): Promise<void> {
  if ('serviceWorker' in navigator && 'SyncManager' in window) {
    const reg = await navigator.serviceWorker.ready
    try {
      await (reg as ServiceWorkerRegistration & {
        sync: { register(tag: string): Promise<void> }
      }).sync.register('outbox-flush')
      return
    } catch {
      // fall through to the foreground path
    }
  }

  const { flush } = await import('./sync')
  void flush()
}
