import { useEffect, useState } from 'react'

import { getMeta, type EventSeverity } from '../db'
import { recordEvent } from '../flowsheet'

/**
 * Charting an intra-dialytic event.
 *
 * The event code comes from a fixed list pulled down at bootstrap, because a
 * free-text code creates a category no report ever counts -- the server refuses
 * an unrecognised one, and a refusal a nurse only discovers hours later at sync
 * time is a refusal that loses the record. So the list is a select, not a text
 * box, and the tablet cannot mint a code the server will reject.
 */

interface EventRef {
  code: string
  label: string
}

interface Reference {
  event_refs?: EventRef[]
}

const SEVERITIES: { value: EventSeverity; label: string }[] = [
  { value: 'minor', label: 'Minor' },
  { value: 'moderate', label: 'Moderate' },
  { value: 'severe', label: 'Severe' },
  { value: 'life_threatening', label: 'Life-threatening' },
]

export function EventForm({
  sessionPublicId,
  disabled,
  onDone,
}: {
  sessionPublicId: string
  disabled: boolean
  onDone: () => void
}) {
  const [codes, setCodes] = useState<EventRef[]>([])
  const [code, setCode] = useState('')
  const [severity, setSeverity] = useState<EventSeverity>('moderate')
  const [description, setDescription] = useState('')
  const [intervention, setIntervention] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    void getMeta<Reference>('reference', {}).then((reference) => {
      setCodes(reference.event_refs ?? [])
    })
  }, [])

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)

    if (code === '') {
      setProblem('Choose what happened.')
      return
    }

    if (description.trim() === '') {
      setProblem('Describe what happened — the code alone will not mean anything later.')
      return
    }

    setSaving(true)
    try {
      await recordEvent(sessionPublicId, code, severity, description.trim(), intervention.trim())
      setCode('')
      setSeverity('moderate')
      setDescription('')
      setIntervention('')
      onDone()
    } catch (error) {
      setProblem(error instanceof Error ? error.message : 'Could not record that event.')
    } finally {
      setSaving(false)
    }
  }

  if (disabled) {
    return (
      <div className="form">
        <p className="notice">This record is signed and closed to charting.</p>
      </div>
    )
  }

  return (
    <form className="form" onSubmit={(event) => void submit(event)}>
      <div className="fields">
        <div className="field">
          <label htmlFor="ev-code">What happened</label>
          <select id="ev-code" value={code} onChange={(event) => setCode(event.target.value)}>
            <option value="">Choose…</option>
            {codes.map((ref) => (
              <option key={ref.code} value={ref.code}>
                {ref.label}
              </option>
            ))}
          </select>
        </div>

        <div className="field">
          <label htmlFor="ev-severity">Severity</label>
          <select
            id="ev-severity"
            value={severity}
            onChange={(event) => setSeverity(event.target.value as EventSeverity)}
          >
            {SEVERITIES.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      {codes.length === 0 ? (
        <p className="notice">
          No event list cached yet. Connect once to pull it down — the tablet will not invent a
          code the server would refuse.
        </p>
      ) : null}

      <div className="field">
        <label htmlFor="ev-description">What you saw</label>
        <textarea
          id="ev-description"
          value={description}
          onChange={(event) => setDescription(event.target.value)}
          placeholder="BP 78/44 at 90 minutes, patient lightheaded."
        />
      </div>

      <div className="field">
        <label htmlFor="ev-intervention">What you did</label>
        <textarea
          id="ev-intervention"
          value={intervention}
          onChange={(event) => setIntervention(event.target.value)}
          placeholder="200 ml normal saline, UF paused, Trendelenburg."
        />
      </div>

      {problem === null ? null : <p className="problem">{problem}</p>}

      <div className="actions">
        <button type="submit" className="btn-primary" disabled={saving}>
          {saving ? 'Recording…' : 'Record event'}
        </button>
      </div>
    </form>
  )
}
