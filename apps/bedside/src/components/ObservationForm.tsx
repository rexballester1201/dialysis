import { useEffect, useState } from 'react'

import { addVital, prefill, type VitalInput } from '../flowsheet'

/**
 * Adding one observation.
 *
 * The design target from flowsheet.ts holds here: the form arrives pre-filled
 * from the previous row so the nurse changes two or three numbers rather than
 * twelve. That is the difference between a system nurses adopt and one they
 * route around -- a half-hourly form that takes a minute to fill costs an hour
 * a shift, and the way staff recover that hour is by charting from memory at
 * the end.
 *
 * `inputMode="decimal"` rather than `type="number"`: a numeric keypad on a
 * tablet, without the scroll-wheel and spinner behaviour that silently changes
 * a value when a gloved hand brushes it.
 */

/** The numeric grid only. `comment` is free text and has its own control. */
type NumericField = Exclude<keyof VitalInput, 'comment'>

const FIELDS: { key: NumericField; label: string; unit?: string }[] = [
  { key: 'bpSys', label: 'BP sys', unit: 'mmHg' },
  { key: 'bpDia', label: 'BP dia', unit: 'mmHg' },
  { key: 'pulse', label: 'Pulse', unit: '/min' },
  { key: 'bloodFlowMlMin', label: 'Blood flow', unit: 'ml/min' },
  { key: 'venousPressureMmhg', label: 'Venous P', unit: 'mmHg' },
  { key: 'ufRateMlHr', label: 'UF rate', unit: 'ml/h' },
  { key: 'ufVolumeMl', label: 'UF so far', unit: 'ml' },
]

type Draft = Partial<Record<NumericField, string>>

function toDraft(input: VitalInput): Draft {
  const draft: Draft = {}

  for (const { key } of FIELDS) {
    const value = input[key]
    draft[key] = typeof value === 'number' ? String(value) : ''
  }

  return draft
}

export function ObservationForm({
  sessionPublicId,
  startedAt,
  disabled,
  disabledReason,
}: {
  sessionPublicId: string
  startedAt: string | null
  disabled: boolean
  disabledReason?: string
}) {
  const [draft, setDraft] = useState<Draft>(() => toDraft({}))
  const [comment, setComment] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  // Seed from the previous row whenever the session changes.
  useEffect(() => {
    let cancelled = false

    void prefill(sessionPublicId).then((seed) => {
      if (!cancelled) setDraft(toDraft(seed))
    })

    return () => {
      cancelled = true
    }
  }, [sessionPublicId])

  const set = (key: NumericField, value: string) =>
    setDraft((current) => ({ ...current, [key]: value }))

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)

    if (startedAt === null) {
      setProblem('This session has not been started, so there is no clock to chart against.')
      return
    }

    const input: VitalInput = {}
    for (const { key, label } of FIELDS) {
      const raw = (draft[key] ?? '').trim()
      if (raw === '') continue

      const value = Number(raw)
      if (!Number.isFinite(value)) {
        setProblem(`${label} is not a number.`)
        return
      }

      input[key] = value
    }

    if (Object.keys(input).length === 0 && comment.trim() === '') {
      setProblem('Nothing to record yet — enter at least one reading or a comment.')
      return
    }

    if (comment.trim() !== '') input.comment = comment.trim()

    setSaving(true)
    try {
      await addVital(sessionPublicId, startedAt, input)
      setComment('')
      // The numbers stay put: the next observation starts from this one.
      setDraft(toDraft(input))
    } catch (error) {
      setProblem(error instanceof Error ? error.message : 'Could not save that observation.')
    } finally {
      setSaving(false)
    }
  }

  if (disabled) {
    return (
      <div className="form">
        <p className="notice">{disabledReason ?? 'This record is closed to charting.'}</p>
      </div>
    )
  }

  return (
    <form className="form" onSubmit={(event) => void submit(event)}>
      <div className="fields">
        {FIELDS.map(({ key, label, unit }) => (
          <div className="field" key={key}>
            <label htmlFor={`obs-${key}`}>
              {label}
              {unit === undefined ? '' : ` (${unit})`}
            </label>
            <input
              id={`obs-${key}`}
              inputMode="decimal"
              autoComplete="off"
              value={draft[key] ?? ''}
              onChange={(event) => set(key, event.target.value)}
            />
          </div>
        ))}
      </div>

      <div className="field">
        <label htmlFor="obs-comment">Comment</label>
        <textarea
          id="obs-comment"
          value={comment}
          onChange={(event) => setComment(event.target.value)}
          placeholder="Anything the numbers do not say."
        />
      </div>

      {problem === null ? null : <p className="problem">{problem}</p>}

      <div className="actions">
        <button type="submit" className="btn-primary" disabled={saving}>
          {saving ? 'Recording…' : 'Record observation'}
        </button>
        <span className="hint">
          Timed automatically and saved on this tablet, connection or not.
        </span>
      </div>
    </form>
  )
}
