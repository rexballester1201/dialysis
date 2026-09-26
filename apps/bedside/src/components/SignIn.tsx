import { useState } from 'react'

import { AuthError, currentStaff, login, pinUnlock, signOut, type Staff } from '../auth'

/**
 * Two ways in, and which one you get depends on whether this tablet already
 * knows you.
 *
 * The PIN path is not a weaker password -- it only exists once a full password
 * login has bound this device to a staff member, and it re-issues a token for
 * that same person. Signing in as somebody else needs the password path, which
 * is the point: a shared logged-in account is what destroys the audit trail.
 */
export function SignIn({ onSignedIn }: { onSignedIn: (staff: Staff) => void }) {
  const known = currentStaff()

  const [usePin, setUsePin] = useState(known !== null)
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [pin, setPin] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)
    setBusy(true)

    try {
      const staff = usePin ? await pinUnlock(pin) : await login(username, password)
      setPin('')
      setPassword('')
      onSignedIn(staff)
    } catch (error) {
      setProblem(
        error instanceof AuthError
          ? error.message
          : 'Sign-in failed. Check the connection and try again.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="signin" onSubmit={(event) => void submit(event)}>
      <h1>Bedside</h1>

      {usePin && known !== null ? (
        <>
          <p className="hint">
            Unlock as <strong>{known.fullName}</strong>.
          </p>
          <div className="field">
            <label htmlFor="pin">PIN</label>
            <input
              id="pin"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={pin}
              onChange={(event) => setPin(event.target.value)}
            />
          </div>
        </>
      ) : (
        <>
          <div className="field">
            <label htmlFor="username">Username</label>
            <input
              id="username"
              autoComplete="username"
              autoCapitalize="none"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="password">Password</label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </div>
        </>
      )}

      {problem === null ? null : <p className="problem">{problem}</p>}

      <div className="actions">
        <button type="submit" className="btn-primary" disabled={busy}>
          {busy ? 'Signing in…' : usePin ? 'Unlock' : 'Sign in'}
        </button>

        {known === null ? null : (
          <button
            type="button"
            className="btn-link"
            onClick={() => {
              setProblem(null)

              if (usePin) {
                // Switching to the password form means signing in as someone
                // else, so the remembered staff member goes with it.
                signOut()
              }

              setUsePin(!usePin)
            }}
          >
            {usePin ? 'Sign in as someone else' : 'Back to PIN unlock'}
          </button>
        )}
      </div>
    </form>
  )
}
