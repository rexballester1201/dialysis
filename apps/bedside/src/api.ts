import { ApiClient, ApiError } from '@dialysis/api-client'

import { token } from './auth'
import { deviceId } from './sync'

/**
 * The tablet's online client, for the few calls that must not be queued.
 *
 * Almost everything a nurse does at the chair goes through the outbox and works
 * with no signal. Check-in, start and end do not, and that is deliberate: they
 * are where the water check, the infection-control check, the machine check and
 * the dialyzer check run, and a refusal is only worth anything before the needle
 * goes in. Queued offline, a cohort breach would be reported at sync time --
 * hours later, after the patient had been dialysed.
 *
 * THE DEVICE ID MUST BE THE ONE THE TOKEN WAS ISSUED TO. auth.ts signs in with
 * sync.ts's deviceId(), and the server revokes -- not refuses -- a token that
 * turns up with any other. A client built with a fresh id would sign the nurse
 * out on its first request.
 */
export const api = new ApiClient({
  baseUrl: '/api/v1',
  deviceId: deviceId(),
  getToken: () => token(),
})

/**
 * A 401 means the token expired, not that the action failed on its merits.
 * Raise the same event the outbox raises, so the PIN prompt appears and nothing
 * the nurse typed into a form is thrown away.
 */
export function reauthIfExpired(error: unknown): boolean {
  if (error instanceof ApiError && error.isAuthFailure) {
    window.dispatchEvent(new CustomEvent('auth:reauth-required'))

    return true
  }

  return false
}

/** The server's own sentence where there is one; it is better than anything this layer could write. */
export function explain(error: unknown): string {
  if (error instanceof ApiError) return error.message

  if (error instanceof TypeError) {
    return 'No connection to the server. This step needs one, so the checks can run before the needle goes in.'
  }

  return error instanceof Error ? error.message : 'Something went wrong.'
}
