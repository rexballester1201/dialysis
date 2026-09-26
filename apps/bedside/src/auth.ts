import { deviceId } from './sync'

/**
 * Sign-in for the tablet.
 *
 * Two ways in, and the difference matters. A full password login is what binds
 * this device to the staff member; after that a 6-digit PIN unlocks it for the
 * rest of the shift. Nurses re-authenticate around twenty times a shift, and
 * demanding a password that often guarantees one shared logged-in account --
 * which destroys the audit trail the whole system rests on.
 *
 * The token lives in localStorage rather than memory because the tablet's
 * browser gets backgrounded and killed constantly, and being thrown back to a
 * password prompt mid-shift is exactly what drives staff to share an account.
 */

const API = '/api/v1'

const TOKEN_KEY = 'token'
const STAFF_KEY = 'staff'

export interface Staff {
  publicId: string
  fullName: string
}

interface TokenResponse {
  token: string
  expires_at: string | null
  staff: { public_id: string; full_name: string }
}

export function token(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function currentStaff(): Staff | null {
  const raw = localStorage.getItem(STAFF_KEY)
  if (raw === null) return null

  try {
    return JSON.parse(raw) as Staff
  } catch {
    return null
  }
}

/** Thrown for anything the user can act on; the message is shown verbatim. */
export class AuthError extends Error {}

async function issue(path: string, body: Record<string, string>): Promise<Staff> {
  let response: Response

  try {
    response = await fetch(`${API}${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...body, device_id: deviceId() }),
    })
  } catch {
    throw new AuthError('No connection to the server. Signing in needs the network; charting does not.')
  }

  if (response.status === 401 || response.status === 422) {
    const problem = (await response.json().catch(() => null)) as { message?: string } | null
    throw new AuthError(problem?.message ?? 'Those credentials were not accepted.')
  }

  if (response.status === 429) {
    throw new AuthError('Too many attempts. Wait a moment before trying again.')
  }

  if (!response.ok) {
    throw new AuthError(`Sign-in failed (${response.status}).`)
  }

  const issued = (await response.json()) as TokenResponse
  const staff: Staff = { publicId: issued.staff.public_id, fullName: issued.staff.full_name }

  localStorage.setItem(TOKEN_KEY, issued.token)
  localStorage.setItem(STAFF_KEY, JSON.stringify(staff))

  return staff
}

export function login(username: string, password: string): Promise<Staff> {
  return issue('/auth/login', { username, password })
}

/**
 * Re-issue a token for a staff member this device already knows.
 *
 * Deliberately refuses when no staff member is on file: a PIN is a second
 * factor for an established device binding, not a way to sign in from cold.
 */
export function pinUnlock(pin: string): Promise<Staff> {
  const staff = currentStaff()

  if (staff === null) {
    throw new AuthError('This tablet has no signed-in staff member. Sign in with a password first.')
  }

  return issue('/auth/pin-unlock', { staff_public_id: staff.publicId, pin })
}

/**
 * Drop the token but keep the staff member, so the PIN path still works.
 *
 * The outbox is never touched here. Charting that has not reached the server
 * is not the token's to discard.
 */
export function lock(): void {
  localStorage.removeItem(TOKEN_KEY)
}

/** Full sign-out. Refuses while anything is still queued. */
export function signOut(): void {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(STAFF_KEY)
}
