import { ApiClient } from '@dialysis/api-client'

/**
 * The console's single API client.
 *
 * Unlike the bedside tablet there is no outbox here: the console is online-only
 * by design, so a failed write is a failed write and is reported as one. There
 * is no local cache to fall back on and no queue to drain, which is the right
 * trade for a desk — the person at it can retry, and a half-applied billing
 * action reconciled hours later is worse than an error message now.
 */

const TOKEN_KEY = 'console.token'
const STAFF_KEY = 'console.staff'
const DEVICE_KEY = 'console.deviceId'

export interface Staff {
  publicId: string
  fullName: string
}

/**
 * Tokens are bound to the device that requested them, and presenting one from
 * somewhere else revokes it. A stable per-browser id is therefore not cosmetic.
 */
function deviceId(): string {
  let id = localStorage.getItem(DEVICE_KEY)

  if (id === null) {
    id = `CONSOLE-${crypto.randomUUID()}`
    localStorage.setItem(DEVICE_KEY, id)
  }

  return id
}

export const api = new ApiClient({
  baseUrl: '/api/v1',
  deviceId: deviceId(),
  getToken: () => localStorage.getItem(TOKEN_KEY),
})

export function currentStaff(): Staff | null {
  const raw = localStorage.getItem(STAFF_KEY)
  if (raw === null) return null

  try {
    return JSON.parse(raw) as Staff
  } catch {
    return null
  }
}

export function signedIn(): boolean {
  return localStorage.getItem(TOKEN_KEY) !== null
}

export async function signIn(username: string, password: string): Promise<Staff> {
  const issued = await api.login(username, password)
  const staff: Staff = { publicId: issued.staff.public_id, fullName: issued.staff.full_name }

  localStorage.setItem(TOKEN_KEY, issued.token)
  localStorage.setItem(STAFF_KEY, JSON.stringify(staff))

  return staff
}

export function signOut(): void {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(STAFF_KEY)
}

/**
 * Turn any thrown value into something worth showing a person.
 *
 * The API's domain refusals are the useful case: a 422 from a service carries a
 * sentence explaining what was refused and why, and that sentence is better than
 * anything this layer could invent. It is shown verbatim.
 */
export function explain(error: unknown): string {
  if (error !== null && typeof error === 'object') {
    const problem = error as { message?: unknown; status?: unknown }

    if (typeof problem.message === 'string' && problem.message !== '') {
      return problem.message
    }

    if (problem.status === 403) return 'Your role does not allow that.'
    if (problem.status === 401) return 'Your session expired. Sign in again.'
  }

  return 'Something went wrong. Check the connection and try again.'
}
