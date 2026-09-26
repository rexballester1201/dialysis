import { useState } from 'react'

import { explain, signIn, type Staff } from '../api'

/**
 * Console sign-in.
 *
 * Password only — no PIN path. The PIN exists because a nurse re-authenticates
 * about twenty times a shift at a chair; a clerk at a desk signs in once, so the
 * shortcut would be all cost and no benefit.
 */
export function SignIn({ onSignedIn }: { onSignedIn: (staff: Staff) => void }) {
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)
    setBusy(true)

    try {
      onSignedIn(await signIn(username, password))
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="signin" onSubmit={(event) => void submit(event)}>
      <h1>Dialysis Console</h1>

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

      {problem === null ? null : <p className="problem">{problem}</p>}

      <div className="actions" style={{ marginTop: 0 }}>
        <button type="submit" className="btn-primary" disabled={busy}>
          {busy ? 'Signing in…' : 'Sign in'}
        </button>
      </div>
    </form>
  )
}
