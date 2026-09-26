import type { PatientPage } from '@dialysis/api-client'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { Cohort, PatientStatus } from '../components/Chips'

/**
 * The registry.
 *
 * Cohort is shown on the list rather than only on the chart, because it decides
 * which chairs a patient may use and the person building tomorrow's board needs
 * it at a glance. It is derived from serology and is not editable here — the
 * only way to move a patient between cohorts is to file a result.
 */
export function PatientsView({ onOpen }: { onOpen: (publicId: string) => void }) {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState<PatientPage | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [registering, setRegistering] = useState(false)

  const load = useCallback(async (term: string, only: string, at = 1) => {
    setProblem(null)

    try {
      setPage(await api.patients({ search: term || undefined, status: only || undefined, page: at }))
    } catch (error) {
      setProblem(explain(error))
    }
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(search, status), 250)

    return () => window.clearTimeout(timer)
  }, [search, status, load])

  const rows = page?.data ?? []
  const meta = page?.meta

  return (
    <>
      <div className="pagehead">
        <h1>Patients</h1>
      </div>
      <p className="subtle">
        Everyone the unit treats. Retiring a record is a soft delete an administrator performs —
        nothing clinical is ever removed.
      </p>

      {registering ? (
        <RegisterPanel
          onDone={(publicId) => {
            setRegistering(false)
            void load(search, status)
            onOpen(publicId)
          }}
          onCancel={() => setRegistering(false)}
        />
      ) : null}

      <div className="panel">
        <header>
          <div className="field">
            <label htmlFor="pt-search">Search</label>
            <input
              id="pt-search"
              value={search}
              placeholder="Name or MRN"
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="pt-status">Status</label>
            <select id="pt-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">All</option>
              <option value="active">Active</option>
              <option value="transferred">Transferred</option>
              <option value="transplanted">Transplanted</option>
              <option value="deceased">Deceased</option>
              <option value="lost_to_followup">Lost to follow-up</option>
            </select>
          </div>
          <span className="grow" />
          {registering ? null : (
            <button type="button" className="btn-primary" onClick={() => setRegistering(true)}>
              Register a patient
            </button>
          )}
        </header>

        {problem === null ? null : (
          <div className="panelbody">
            <p className="problem">{problem}</p>
          </div>
        )}

        {rows.length === 0 ? (
          <p className="empty">No patients match that.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>MRN</th>
                  <th>Name</th>
                  <th>Born</th>
                  <th>Sex</th>
                  <th>Cohort</th>
                  <th>Status</th>
                  <th>First dialysis</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((patient) => (
                  <tr key={patient.public_id} className="clickable" onClick={() => onOpen(patient.public_id)}>
                    <td className="muted">{patient.mrn}</td>
                    <td>
                      <strong>{patient.full_name}</strong>
                    </td>
                    <td className="muted">{patient.birth_date}</td>
                    <td className="muted">{patient.sex}</td>
                    <td>
                      <Cohort value={patient.cohort} />
                    </td>
                    <td>
                      <PatientStatus status={patient.status} />
                    </td>
                    <td className="muted">{patient.first_dialysis_date ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta === undefined || meta.last_page <= 1 ? null : (
          <div className="panelbody" style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <button
              type="button"
              className="btn-quiet"
              disabled={meta.current_page <= 1}
              onClick={() => void load(search, status, meta.current_page - 1)}
            >
              Previous
            </button>
            <span className="muted">
              Page {meta.current_page} of {meta.last_page} · {meta.total} patients
            </span>
            <button
              type="button"
              className="btn-quiet"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => void load(search, status, meta.current_page + 1)}
            >
              Next
            </button>
          </div>
        )}
      </div>
    </>
  )
}

/**
 * Registration.
 *
 * Only what a patient cannot be created without. Everything effective-dated —
 * dry weight, serology, prescription, standing pattern — is deliberately not
 * here: those are histories, and starting one on the registration form invites
 * a backdated first entry nobody meant to make.
 */
function RegisterPanel({
  onDone,
  onCancel,
}: {
  onDone: (publicId: string) => void
  onCancel: () => void
}) {
  const [form, setForm] = useState({
    mrn: '',
    first_name: '',
    middle_name: '',
    last_name: '',
    birth_date: '',
    sex: 'F',
  })
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const set = (key: string, value: string) => setForm((current) => ({ ...current, [key]: value }))

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)
    setBusy(true)

    try {
      const created = await api.registerPatient({
        ...form,
        middle_name: form.middle_name === '' ? null : form.middle_name,
      })
      onDone(created.public_id)
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="panel">
      <header>
        <h2>Register a patient</h2>
      </header>
      <form className="panelbody" onSubmit={(event) => void submit(event)}>
        {problem === null ? null : <p className="problem">{problem}</p>}

        <div className="fields">
          <div className="field">
            <label htmlFor="reg-mrn">MRN</label>
            <input id="reg-mrn" value={form.mrn} onChange={(e) => set('mrn', e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="reg-first">First name</label>
            <input id="reg-first" value={form.first_name} onChange={(e) => set('first_name', e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="reg-middle">Middle name</label>
            <input id="reg-middle" value={form.middle_name} onChange={(e) => set('middle_name', e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="reg-last">Last name</label>
            <input id="reg-last" value={form.last_name} onChange={(e) => set('last_name', e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="reg-born">Birth date</label>
            <input id="reg-born" type="date" value={form.birth_date} onChange={(e) => set('birth_date', e.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="reg-sex">Sex</label>
            <select id="reg-sex" value={form.sex} onChange={(e) => set('sex', e.target.value)}>
              <option value="F">Female</option>
              <option value="M">Male</option>
            </select>
          </div>
        </div>

        <div className="actions">
          <button type="submit" className="btn-primary" disabled={busy}>
            {busy ? 'Registering…' : 'Register'}
          </button>
          <button type="button" className="btn-quiet" onClick={onCancel}>
            Cancel
          </button>
          <span className="muted">
            A new patient starts in the clean cohort until serology says otherwise.
          </span>
        </div>
      </form>
    </div>
  )
}
