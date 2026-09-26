import type { Patient } from '@dialysis/api-client'
import { useEffect, useState } from 'react'

import { api, explain } from '../api'

/**
 * Find one patient by name or MRN.
 *
 * Searching the registry is not a chart view -- nothing clinical is opened
 * here -- so it is not logged as one. Choosing a patient only names who the
 * claim or invoice is for.
 */
export function PatientPicker({
  id,
  chosen,
  onChoose,
}: {
  id: string
  chosen: Patient | null
  onChoose: (patient: Patient | null) => void
}) {
  const [term, setTerm] = useState('')
  const [results, setResults] = useState<Patient[]>([])
  const [problem, setProblem] = useState<string | null>(null)

  useEffect(() => {
    if (chosen !== null || term.trim().length < 2) {
      setResults([])

      return
    }

    let current = true

    const timer = window.setTimeout(() => {
      api
        .patients({ search: term.trim() })
        .then((page) => {
          if (current) {
            setResults(page.data.slice(0, 8))
            setProblem(null)
          }
        })
        .catch((error: unknown) => {
          if (current) setProblem(explain(error))
        })
    }, 250)

    return () => {
      current = false
      window.clearTimeout(timer)
    }
  }, [term, chosen])

  if (chosen !== null) {
    return (
      <div className="picked">
        <strong>{chosen.full_name}</strong>
        <span className="muted">{chosen.mrn}</span>
        <button type="button" className="btn-link" onClick={() => onChoose(null)}>
          Change
        </button>
      </div>
    )
  }

  return (
    <div className="picker">
      <input
        id={id}
        value={term}
        placeholder="Name or MRN, at least two letters"
        autoComplete="off"
        onChange={(event) => setTerm(event.target.value)}
      />
      {problem === null ? null : <p className="problem">{problem}</p>}
      {results.length === 0 ? null : (
        <ul className="pickresults">
          {results.map((patient) => (
            <li key={patient.public_id}>
              <button type="button" onClick={() => onChoose(patient)}>
                <strong>{patient.full_name}</strong>
                <span className="muted">{patient.mrn}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
