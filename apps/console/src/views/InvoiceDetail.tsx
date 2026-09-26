import type { InvoiceDetail as Invoice, PaymentInput } from '@dialysis/api-client'
import { useConfirm } from '@dialysis/ui'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { ClaimStatus, InvoiceStatus } from '../components/Chips'
import { isAmount, money, today } from '../components/format'

/**
 * One invoice: the lines behind it, the payments against it, and the three
 * things that can happen to it -- issue, take money, void.
 *
 * What is allowed comes from the server (`can_issue`, `can_void`,
 * `takes_payments`); the buttons follow it rather than keeping their own copy
 * of the rules.
 */

const METHODS: { value: PaymentInput['method']; label: string }[] = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'ewallet', label: 'E-wallet' },
  { value: 'cheque', label: 'Cheque' },
  { value: 'payer_remittance', label: 'Payer remittance (e.g. a guarantee letter)' },
  { value: 'adjustment', label: 'Adjustment' },
]

export function InvoiceDetail({
  invoiceNo,
  onBack,
  onOpenSession,
  onOpenPatient,
}: {
  invoiceNo: string
  onBack: () => void
  onOpenSession: (publicId: string) => void
  onOpenPatient: (publicId: string) => void
}) {
  const confirmAction = useConfirm()

  const [invoice, setInvoice] = useState<Invoice | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setProblem(null)

    try {
      setInvoice(await api.invoice(invoiceNo))
    } catch (error) {
      setProblem(explain(error))
    }
  }, [invoiceNo])

  useEffect(() => {
    void load()
  }, [load])

  /** Run one change; true only if the server accepted it. */
  async function act(run: () => Promise<Invoice>, success: string): Promise<boolean> {
    setBusy(true)
    setProblem(null)
    setDone(null)

    try {
      setInvoice(await run())
      setDone(success)

      return true
    } catch (error) {
      setProblem(explain(error))
      void api.invoice(invoiceNo).then(setInvoice).catch(() => undefined)

      return false
    } finally {
      setBusy(false)
    }
  }

  async function issue() {
    if (invoice === null) return

    const answer = await confirmAction({
      title: `Issue ${invoice.invoice_no}?`,
      body: 'It is dated today — the date on the patient’s copy — and can no longer go back to draft.',
      confirmLabel: 'Issue invoice',
    })

    if (answer.confirmed) await act(() => api.issueInvoice(invoice.invoice_no), 'Issued.')
  }

  async function voidIt() {
    if (invoice === null) return

    const answer = await confirmAction({
      title: `Void ${invoice.invoice_no}?`,
      body:
        'Its sessions can then go on a new invoice. The lines stay on the voided copy, and the reason is ' +
        'written on the invoice.',
      confirmLabel: 'Void invoice',
      tone: 'danger',
      reason: {
        label: 'Why is this invoice being voided?',
        minLength: 10,
        destination: 'the invoice’s notes and the audit log',
      },
    })

    if (answer.confirmed && answer.reason !== null) {
      await act(() => api.voidInvoice(invoice.invoice_no, answer.reason!), 'Voided. Its sessions are free to invoice again.')
    }
  }

  if (invoice === null) {
    return (
      <>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← Invoices
        </button>
        <div className="panel" style={{ marginTop: 14 }}>
          {problem === null ? (
            <p className="empty">Loading…</p>
          ) : (
            <div className="panelbody">
              <p className="problem">{problem}</p>
            </div>
          )}
        </div>
      </>
    )
  }

  const currency = invoice.currency
  const receivedAnything = invoice.amount_paid !== '0.00'

  return (
    <>
      <div className="actions" style={{ marginTop: 0, marginBottom: 12 }}>
        <button type="button" className="btn-quiet" onClick={onBack}>
          ← Invoices
        </button>
      </div>

      <div className="pagehead">
        <h1>{invoice.invoice_no}</h1>
        <InvoiceStatus status={invoice.status} />
      </div>
      <p className="subtle">
        {invoice.patient === null ? (
          '—'
        ) : (
          <button type="button" className="btn-link inline" onClick={() => onOpenPatient(invoice.patient!.public_id)}>
            {invoice.patient.full_name}
          </button>
        )}{' '}
        · {invoice.patient?.mrn ?? '—'} · issued {invoice.issued_on}
        {invoice.status === 'draft' ? ' (drafted; the date moves to the day it is issued)' : ''}
      </p>

      {problem === null ? null : <p className="problem">{problem}</p>}
      {done === null ? null : <p className="good">{done}</p>}

      <div className="stats">
        <div className="stat">
          <span className="label">Charges</span>
          <span className="value">{money(invoice.subtotal, currency)}</span>
        </div>
        <div className="stat">
          <span className="label">Payer share</span>
          <span className="value">{money(invoice.payer_covered, currency)}</span>
        </div>
        <div className="stat">
          <span className="label">Patient due</span>
          <span className="value">{money(invoice.patient_due, currency)}</span>
        </div>
        <div className="stat">
          <span className="label">Paid</span>
          <span className="value">{money(invoice.amount_paid, currency)}</span>
        </div>
        <div className="stat">
          <span className="label">Balance</span>
          {/* A void invoice's balance is history, not a debt and not settled -- no colour either way. */}
          <span
            className={`value ${invoice.status === 'void' ? '' : invoice.balance !== '0.00' ? 'bad' : 'good'}`}
          >
            {money(invoice.balance, currency)}
          </span>
        </div>
      </div>

      {invoice.patient_due === '0.00' && invoice.payer_covered !== '0.00' ? (
        <p className="good">
          Nothing is due from the patient: the payer’s package covers these treatments with no balance billing.
        </p>
      ) : null}

      <div className="panel">
        <header>
          <h2>Actions</h2>
        </header>
        <div className="panelbody">
          <div className="actions" style={{ marginTop: 0 }}>
            {invoice.can_issue ? (
              <button type="button" className="btn-primary" disabled={busy} onClick={() => void issue()}>
                Issue invoice
              </button>
            ) : null}
            {invoice.can_void ? (
              <button type="button" className="btn-danger" disabled={busy} onClick={() => void voidIt()}>
                Void invoice
              </button>
            ) : null}
            {!invoice.can_void && invoice.takes_payments && receivedAnything ? (
              <span className="muted">
                To void it, refund the {money(invoice.amount_paid, currency)} received first — a void invoice
                holding money is money nobody can find.
              </span>
            ) : null}
            {!invoice.takes_payments ? (
              <span className="muted">This invoice is {invoice.status.replace(/_/g, ' ')} and takes no payments.</span>
            ) : null}
          </div>
        </div>
      </div>

      {invoice.takes_payments ? (
        <PaymentPanel
          invoice={invoice}
          busy={busy}
          onRecord={(payment, success) => act(() => api.recordPayment(invoice.invoice_no, payment), success)}
        />
      ) : null}

      <div className="panel">
        <header>
          <h2>Lines</h2>
          <span className="grow" />
          <span className="muted" style={{ fontSize: '0.85rem' }}>
            The payer share is what was claimed for each session, not what has been received.
          </span>
        </header>
        <div className="tablewrap">
          <table>
            <thead>
              <tr>
                <th>Description</th>
                <th>Claim</th>
                <th className="num">Qty</th>
                <th className="num">Unit price</th>
                <th className="num">Discount</th>
                <th className="num">Line total</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {invoice.lines.map((line, index) => (
                <tr key={`${line.session_public_id ?? 'line'}-${index}`}>
                  <td>{line.description}</td>
                  <td>
                    {line.claim_no === null ? (
                      <span className="muted">—</span>
                    ) : (
                      <>
                        {line.claim_no}{' '}
                        {line.claim_status === null ? null : <ClaimStatus status={line.claim_status} />}
                      </>
                    )}
                  </td>
                  <td className="num">{line.qty}</td>
                  <td className="num">{money(line.unit_price, currency)}</td>
                  <td className="num">{money(line.discount, currency)}</td>
                  <td className="num">{money(line.line_total, currency)}</td>
                  <td>
                    {line.session_public_id === null ? null : (
                      <button type="button" className="btn-link" onClick={() => onOpenSession(line.session_public_id!)}>
                        Open record
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel">
        <header>
          <h2>Payments</h2>
        </header>
        {invoice.payments.length === 0 ? (
          <p className="empty">Nothing received yet.</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th className="num">Amount</th>
                  <th>Method</th>
                  <th>Reference</th>
                  <th>Received by</th>
                  <th>Notes</th>
                </tr>
              </thead>
              <tbody>
                {invoice.payments.map((payment, index) => (
                  <tr key={`${payment.paid_on}-${index}`}>
                    <td>{payment.paid_on}</td>
                    <td className={`num ${payment.amount.startsWith('-') ? 'refund' : ''}`}>
                      {money(payment.amount, currency)}
                    </td>
                    <td className="muted">{METHODS.find((m) => m.value === payment.method)?.label ?? payment.method}</td>
                    <td className="muted">{payment.reference_no ?? '—'}</td>
                    <td className="muted">{payment.received_by ?? '—'}</td>
                    <td className="wrapcell muted">{payment.notes ?? ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {invoice.notes === null ? null : (
        <div className="panel">
          <header>
            <h2>Notes</h2>
          </header>
          <p className="panelbody prewrap" style={{ margin: 0 }}>
            {invoice.notes}
          </p>
        </div>
      )}
    </>
  )
}

/**
 * Take money, or give it back.
 *
 * A refund is entered as a refund and sent as a negative amount -- the form
 * never asks anyone to type a minus sign. The server refuses a payment above
 * the balance and a refund above what was received.
 */
function PaymentPanel({
  invoice,
  busy,
  onRecord,
}: {
  invoice: Invoice
  busy: boolean
  onRecord: (payment: PaymentInput, success: string) => Promise<boolean>
}) {
  const confirmAction = useConfirm()

  const [kind, setKind] = useState<'payment' | 'refund'>('payment')
  const [form, setForm] = useState({
    amount: '',
    method: 'cash' as PaymentInput['method'],
    paid_on: today(),
    reference_no: '',
    notes: '',
  })
  const [problem, setProblem] = useState<string | null>(null)

  const set = (key: keyof typeof form, value: string) => setForm((current) => ({ ...current, [key]: value }))
  const currency = invoice.currency

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setProblem(null)

    const amount = form.amount.trim()

    if (!isAmount(amount) || /^0+(\.0{1,2})?$/.test(amount)) {
      setProblem('Enter an amount above zero, with up to two decimals — for example 1500.00.')

      return
    }

    const method = METHODS.find((m) => m.value === form.method)?.label ?? form.method
    const answer = await confirmAction(
      kind === 'payment'
        ? {
            title: `Record ${money(amount, currency)} received?`,
            body: `${method}, on ${form.paid_on}, against ${invoice.invoice_no}. The balance is ${money(invoice.balance, currency)}.`,
            confirmLabel: 'Record payment',
          }
        : {
            title: `Record a refund of ${money(amount, currency)}?`,
            body: `${method}, on ${form.paid_on}. ${money(invoice.amount_paid, currency)} has been received on this invoice.`,
            confirmLabel: 'Record refund',
            tone: 'danger',
          },
    )

    if (!answer.confirmed) return

    const payment: PaymentInput = {
      amount: kind === 'refund' ? `-${amount}` : amount,
      method: form.method,
      paid_on: form.paid_on,
    }

    if (form.reference_no.trim() !== '') payment.reference_no = form.reference_no.trim()
    if (form.notes.trim() !== '') payment.notes = form.notes.trim()

    // Cleared only once the server has it: a refused payment keeps what was typed.
    if (await onRecord(payment, kind === 'payment' ? 'Payment recorded.' : 'Refund recorded.')) {
      setForm((current) => ({ ...current, amount: '', reference_no: '', notes: '' }))
      setKind('payment')
    }
  }

  return (
    <div className="panel">
      <header>
        <h2>{kind === 'payment' ? 'Record a payment' : 'Record a refund'}</h2>
        <span className="grow" />
        <div className="segmented" role="radiogroup" aria-label="Payment or refund">
          <label>
            <input type="radio" name="pay-kind" checked={kind === 'payment'} onChange={() => setKind('payment')} />
            Payment
          </label>
          <label>
            <input
              type="radio"
              name="pay-kind"
              checked={kind === 'refund'}
              disabled={invoice.amount_paid === '0.00'}
              onChange={() => setKind('refund')}
            />
            Refund
          </label>
        </div>
      </header>
      <form className="panelbody" onSubmit={(event) => void submit(event)}>
        {problem === null ? null : <p className="problem">{problem}</p>}

        <div className="fields">
          <div className="field">
            <label htmlFor="pay-amount">Amount</label>
            <input
              id="pay-amount"
              inputMode="decimal"
              value={form.amount}
              placeholder={kind === 'payment' ? invoice.balance : invoice.amount_paid}
              onChange={(event) => set('amount', event.target.value)}
              required
            />
          </div>
          <div className="field">
            <label htmlFor="pay-method">Method</label>
            <select id="pay-method" value={form.method} onChange={(event) => set('method', event.target.value)}>
              {METHODS.map((method) => (
                <option key={method.value} value={method.value}>
                  {method.label}
                </option>
              ))}
            </select>
          </div>
          <div className="field">
            <label htmlFor="pay-on">Date</label>
            <input id="pay-on" type="date" value={form.paid_on} max={today()} onChange={(event) => set('paid_on', event.target.value)} required />
          </div>
          <div className="field">
            <label htmlFor="pay-ref">Reference</label>
            <input
              id="pay-ref"
              value={form.reference_no}
              maxLength={60}
              placeholder="OR number, transfer ref…"
              onChange={(event) => set('reference_no', event.target.value)}
            />
          </div>
        </div>

        <div className="fields" style={{ marginTop: 12 }}>
          <div className="field">
            <label htmlFor="pay-notes">Notes</label>
            <input id="pay-notes" value={form.notes} maxLength={255} onChange={(event) => set('notes', event.target.value)} />
          </div>
        </div>

        <div className="actions">
          <button type="submit" className={kind === 'payment' ? 'btn-primary' : 'btn-danger'} disabled={busy}>
            {kind === 'payment' ? 'Record payment' : 'Record refund'}
          </button>
          <span className="muted">
            Balance {money(invoice.balance, currency)} · received {money(invoice.amount_paid, currency)}
          </span>
        </div>
      </form>
    </div>
  )
}
