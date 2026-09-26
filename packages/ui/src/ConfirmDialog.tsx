import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useId,
  useRef,
  useState,
  type ReactNode,
} from 'react'

/**
 * The confirmation dialog, and the reason `window.confirm` is not used anywhere
 * in this repo.
 *
 * Three reasons, in order of how much they matter here:
 *
 * 1. `window.confirm` cannot collect a reason. Every action in this system that
 *    overrides or rewrites a clinical record requires one -- an infection-control
 *    override needs at least 10 characters, an amendment to a locked session needs
 *    a stated reason. A boolean cannot carry that, so a native confirm would force
 *    a second, separate prompt for the text and a half-confirmed state in between.
 *
 * 2. `window.confirm` blocks the JavaScript thread. On the bedside PWA that stalls
 *    the outbox and the sync loop for as long as the dialog is up. A nurse who
 *    walks away mid-prompt should not be pausing the queue that is trying to get
 *    their charting to the server.
 *
 * 3. It cannot be styled, labelled or made to read correctly on a tablet at a
 *    chair, which is where most of this system's confirmations happen.
 *
 * Built on the native `<dialog>` element, so focus trapping, Escape-to-dismiss,
 * background inertness and top-layer stacking come from the platform rather than
 * from a hand-rolled focus trap that will eventually be wrong.
 *
 * NOTE ON THE MINIMUM LENGTH: `reason.minLength` mirrors a server rule; it does
 * not replace it. The server refuses a short reason regardless of what this
 * dialog allowed through. Per the prime directive, the client is a convenience
 * and never the control.
 */

export type ConfirmTone = 'normal' | 'danger'

export interface ConfirmReasonSpec {
  /** Shown above the textarea, e.g. "Why is this override necessary?" */
  label: string
  /**
   * Minimum characters, trimmed. Mirror the server's rule -- 10 for an
   * infection-control override. The server still enforces it.
   */
  minLength: number
  placeholder?: string
  /**
   * Where the reason ends up, in the reader's terms. Defaults to the patient's
   * record, which is where a clinical override lands -- but a settings change
   * goes to the audit log instead, and telling someone it will appear on a
   * chart when it will not is a small lie in a dialog that exists to be
   * trusted.
   */
  destination?: string
}

export interface ConfirmRequest {
  title: string
  /** One or two sentences saying what will happen. Not a restatement of the title. */
  body?: string
  /** Says exactly what the button does: "Override and seat patient", not "OK". */
  confirmLabel?: string
  cancelLabel?: string
  tone?: ConfirmTone
  /** Present when the action must be justified on the record. */
  reason?: ConfirmReasonSpec
}

export type ConfirmResult =
  | { confirmed: false }
  | { confirmed: true; reason: string | null }

type ConfirmFn = (request: ConfirmRequest) => Promise<ConfirmResult>

const ConfirmContext = createContext<ConfirmFn | null>(null)

const STYLE_ID = 'dialysis-confirm-dialog-styles'

/**
 * Injected once rather than shipped as a .css import, so the package drops into
 * either app without either one needing bundler configuration for it.
 */
const STYLES = `
.dcd[open] {
  border: none;
  border-radius: 6px;
  padding: 0;
  max-width: 30rem;
  width: calc(100% - 2rem);
  color: #16221f;
  background: #ffffff;
  box-shadow: 0 12px 40px -12px rgba(22, 34, 31, .45);
  font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
  line-height: 1.55;
}
.dcd::backdrop { background: rgba(22, 34, 31, .55); }
.dcd-inner { padding: 22px 24px 20px; }
.dcd-title { margin: 0 0 8px; font-size: 1.12rem; font-weight: 600; }
.dcd-body { margin: 0; color: #5c6b67; font-size: .95rem; }
.dcd-field { margin-top: 18px; display: flex; flex-direction: column; gap: 6px; }
.dcd-label { font-size: .85rem; font-weight: 600; color: #16221f; }
.dcd-textarea {
  font: inherit;
  font-size: .95rem;
  padding: 9px 11px;
  min-height: 5.5rem;
  resize: vertical;
  color: #16221f;
  background: #ffffff;
  border: 1px solid #ccd5d2;
  border-radius: 4px;
}
.dcd-textarea:focus-visible { outline: 2px solid #0e6b5e; outline-offset: 1px; border-color: #0e6b5e; }
.dcd-hint { font-size: .8rem; color: #7e8c88; font-variant-numeric: tabular-nums; }
.dcd-hint[data-short="true"] { color: #9a6410; }
.dcd-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
.dcd-btn {
  font: inherit;
  font-size: .93rem;
  font-weight: 500;
  padding: 9px 16px;
  border-radius: 4px;
  border: 1px solid transparent;
  cursor: pointer;
}
.dcd-btn:focus-visible { outline: 2px solid #0e6b5e; outline-offset: 2px; }
.dcd-cancel { background: #ffffff; border-color: #ccd5d2; color: #16221f; }
.dcd-cancel:hover { background: #f2f5f4; }
.dcd-confirm { background: #0e6b5e; color: #ffffff; }
.dcd-confirm:hover:not(:disabled) { background: #0b5a4f; }
.dcd-confirm[data-tone="danger"] { background: #9c2f26; }
.dcd-confirm[data-tone="danger"]:hover:not(:disabled) { background: #85271f; }
.dcd-confirm:disabled { opacity: .45; cursor: not-allowed; }
@media (prefers-color-scheme: dark) {
  .dcd[open] { color: #e8edea; background: #161e1b; }
  .dcd-body { color: #9aaaa4; }
  .dcd-label { color: #e8edea; }
  .dcd-textarea { color: #e8edea; background: #101614; border-color: #36423e; }
  .dcd-hint { color: #7c8b86; }
  .dcd-hint[data-short="true"] { color: #d9a64a; }
  .dcd-cancel { background: #161e1b; border-color: #36423e; color: #e8edea; }
  .dcd-cancel:hover { background: #1e2724; }
}
@media (prefers-reduced-motion: reduce) { .dcd, .dcd-btn { transition: none; } }
`

function injectStyles(): void {
  if (typeof document === 'undefined') return
  if (document.getElementById(STYLE_ID) !== null) return

  const style = document.createElement('style')
  style.id = STYLE_ID
  style.textContent = STYLES
  document.head.appendChild(style)
}

interface Pending {
  request: ConfirmRequest
  resolve: (result: ConfirmResult) => void
}

/**
 * Wrap the app once, at the root. A single dialog element serves every
 * confirmation, so there is never more than one on screen.
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
  const [pending, setPending] = useState<Pending | null>(null)
  const [reason, setReason] = useState('')
  const dialogRef = useRef<HTMLDialogElement | null>(null)
  const settled = useRef(true)
  const titleId = useId()
  const bodyId = useId()

  useEffect(injectStyles, [])

  useEffect(() => {
    const dialog = dialogRef.current
    if (dialog === null || pending === null || dialog.open) return

    settled.current = false
    setReason('')
    dialog.showModal()
  }, [pending])

  /**
   * Every exit route lands here, so the promise resolves exactly once whether
   * the user confirmed, cancelled, or pressed Escape.
   */
  const settle = useCallback(
    (result: ConfirmResult) => {
      if (settled.current) return
      settled.current = true

      pending?.resolve(result)
      dialogRef.current?.close()
      setPending(null)
      setReason('')
    },
    [pending],
  )

  const confirm = useCallback<ConfirmFn>(
    (request) =>
      new Promise<ConfirmResult>((resolve) => {
        setPending((current) => {
          // A second request while one is open would orphan the first promise.
          current?.resolve({ confirmed: false })

          return { request, resolve }
        })
      }),
    [],
  )

  const request = pending?.request
  const spec = request?.reason
  const trimmed = reason.trim()
  const reasonSatisfied = spec === undefined || trimmed.length >= spec.minLength

  return (
    <ConfirmContext.Provider value={confirm}>
      {children}
      <dialog
        ref={dialogRef}
        className="dcd"
        aria-labelledby={titleId}
        aria-describedby={request?.body === undefined ? undefined : bodyId}
        onCancel={(event) => {
          // Escape. Cancelling is always the safe outcome, so it needs no guard.
          event.preventDefault()
          settle({ confirmed: false })
        }}
        onClose={() => settle({ confirmed: false })}
      >
        {request === undefined ? null : (
          <div className="dcd-inner">
            <h2 className="dcd-title" id={titleId}>
              {request.title}
            </h2>

            {request.body === undefined ? null : (
              <p className="dcd-body" id={bodyId}>
                {request.body}
              </p>
            )}

            {spec === undefined ? null : (
              <div className="dcd-field">
                <label className="dcd-label" htmlFor={`${titleId}-reason`}>
                  {spec.label}
                </label>
                <textarea
                  id={`${titleId}-reason`}
                  className="dcd-textarea"
                  value={reason}
                  placeholder={spec.placeholder}
                  autoFocus
                  onChange={(event) => setReason(event.target.value)}
                />
                <span className="dcd-hint" data-short={String(!reasonSatisfied)}>
                  {reasonSatisfied
                    ? `This is written to ${spec.destination ?? 'the patient’s record'}.`
                    : `${trimmed.length} of ${spec.minLength} characters. This is written to ${spec.destination ?? 'the patient’s record'}, so say why.`}
                </span>
              </div>
            )}

            <div className="dcd-actions">
              <button
                type="button"
                className="dcd-btn dcd-cancel"
                onClick={() => settle({ confirmed: false })}
              >
                {request.cancelLabel ?? 'Cancel'}
              </button>
              <button
                type="button"
                className="dcd-btn dcd-confirm"
                data-tone={request.tone ?? 'normal'}
                disabled={!reasonSatisfied}
                autoFocus={spec === undefined}
                onClick={() =>
                  settle({ confirmed: true, reason: spec === undefined ? null : trimmed })
                }
              >
                {request.confirmLabel ?? 'Confirm'}
              </button>
            </div>
          </div>
        )}
      </dialog>
    </ConfirmContext.Provider>
  )
}

/**
 * The replacement for `window.confirm`.
 *
 *   const confirmAction = useConfirm()
 *
 *   const answer = await confirmAction({
 *     title: 'No HBV-designated chair is free',
 *     body: 'Seating this patient at S-04 breaches infection-control segregation.',
 *     confirmLabel: 'Override and seat patient',
 *     tone: 'danger',
 *     reason: { label: 'Why is this override necessary?', minLength: 10 },
 *   })
 *
 *   if (answer.confirmed) {
 *     await checkIn({ cohort_override_reason: answer.reason })
 *   }
 */
export function useConfirm(): ConfirmFn {
  const confirm = useContext(ConfirmContext)

  if (confirm === null) {
    throw new Error('useConfirm() needs a <ConfirmProvider> above it in the tree.')
  }

  return confirm
}
