import type { Invoiceable, InvoicePage, Patient } from '@dialysis/api-client'
import { useConfirm } from '@dialysis/ui'
import { useCallback, useEffect, useState } from 'react'

import { api, explain } from '../api'
import { ClaimStatus, InvoiceStatus } from '../components/Chips'
import { daysAgo, money } from '../components/format'
import { PatientPicker } from '../components/PatientPicker'

/**
 * Patient invoices: what is left after the payer's share.
 *
 * For a package with no-balance billing the patient owes nothing, and the
 * server sets that -- it is the point of the flag. Every figure here is the
 * server's; this screen formats money and never adds it up.
 */

const STATUSES = ['draft', 'issued', 'partially_paid', 'paid', 'void', 'written_off']

export function InvoicesView({ onOpen }: { onOpen: (invoiceNo: string) => void }) {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState<InvoicePage | null>(null)
  const [problem, setProblem] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  const load = useCallback(async (term: string, only: string, at = 1) => {
    setProblem(null)

    try {
      setPage(await api.invoices({ q: term || undefined, status: only || undefined, page: at }))
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
        <h1>Invoices</h1>
      </div>
      <p className="subtle">
        What a patient owes once the payer’s share is taken off. Prices come from the service price list,
        by modality.
      </p>

      {creating ? (
        <NewInvoicePanel
          onCreated={(invoiceNo) => {
            setCreating(false)
            onOpen(invoiceNo)
          }}
          onCancel={() => setCreating(false)}
        />
      ) : null}

      <div className="panel">
        <header>
          <div className="field">
            <label htmlFor="in-search">Search</label>
            <input
              id="in-search"
              value={search}
              placeholder="Invoice no, name or MRN"
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
          <div className="field">
            <label htmlFor="in-status">Status</label>
            <select id="in-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="">All</option>
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {value.replace(/_/g, ' ')}
                </option>
              ))}
            </select>
          </div>
          <span className="grow" />
          {creating ? null : (
            <button type="button" className="btn-primary" onClick={() => setCreating(true)}>
              New invoice
            </button>
          )}
        </header>

        {problem === null ? null : (
          <div className="panelbody">
            <p className="problem">{problem}</p>
          </div>
        )}

        {rows.length === 0 ? (
          <p className="empty">{page === null ? 'Loading…' : 'No invoices match that.'}</p>
        ) : (
          <div className="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Status</th>
                  <th>Issued</th>
                  <th>Patient</th>
                  <th className="num">Patient due</th>
                  <th className="num">Paid</th>
                  <th className="num">Balance</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((invoice) => (
                  <tr key={invoice.invoice_no} className="clickable" onClick={() => onOpen(invoice.invoice_no)}>
                    <td>
                      <strong>{invoice.invoice_no}</strong>
                    </td>
                    <td>
                      <InvoiceStatus status={invoice.status} />
                    </td>
                    <td className="muted">{invoice.issued_on}</td>
                    <td>
                      {invoice.patient.full_name}
                      <div className="muted small">{invoice.patient.mrn}</div>
                    </td>
                    <td className="num">{money(invoice.patient_due, invoice.currency)}</td>
                    <td className="num">{money(invoice.amount_paid, invoice.currency)}</td>
                    <td className="num">
                      {/* A void invoice's balance is not owed; bold would say it is. */}
                      {invoice.status === 'void' || invoice.status === 'written_off' ? (
                        <span className="muted">{money(invoice.balance, invoice.currency)}</span>
                      ) : (
                        <strong>{money(invoice.balance, invoice.currency)}</strong>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta === undefined || meta.last_page <= 1 ? null : (
          <div className="panelbody pager">
            <button
              type="button"
              className="btn-quiet"
              disabled={meta.current_page <= 1}
              onClick={() => void load(search, status, meta.current_page - 1)}
            >
              Previous
            </button>
            <span className="muted">
              Page {meta.current_page} of {meta.last_page} · {meta.total} invoices
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
 * Draft an invoice over a patient's signed sessions.
 *
 * Drafting is how the total is seen: the server prices each line off the list,
 * takes off the payer's share from the claim each session is on, and applies
 * no-balance billing. A draft that comes out wrong is voided, not edited.
 */
function NewInvoicePanel({
  onCreated,
  onCancel,
}: {
  onCreated: (invoiceNo: string) => void
  onCancel: () => void
}) {
  const confirmAction = useConfirm()

  const [patient, setPatient] = useState<Patient | null>(null)
  // Ninety days back is where the list starts, not a rule: an older session
  // appears as soon as the date is moved.
  const [from, setFrom] = useState(daysAgo(90))
  const [found, setFound] = useState<Invoiceable | null>(null)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [problem, setProblem] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    setFound(null)
    setSelected(new Set())
    setProblem(null)

    if (patient === null) return

    let current = true

    api
      .invoiceable(patient.public_id, from || undefined)
      .then((result) => {
        if (current) setFound(result)
      })
      .catch((error: unknown) => {
        if (current) setProblem(explain(error))
      })

    return () => {
      current = false
    }
  }, [patient, from])

  function toggle(publicId: string) {
    setSelected((current) => {
      const next = new Set(current)

      if (next.has(publicId)) next.delete(publicId)
      else next.add(publicId)

      return next
    })
  }

  async function draft() {
    if (patient === null || found === null || selected.size === 0) return

    const answer = await confirmAction({
      title: `Draft an invoice for ${selected.size} session${selected.size === 1 ? '' : 's'}?`,
      body:
        `${patient.full_name}. The server prices it and takes off the payer’s share. It starts as a draft: ` +
        'check it, then issue it — or void it if it came out wrong.',
      confirmLabel: 'Draft invoice',
    })

    if (!answer.confirmed) return

    setBusy(true)
    setProblem(null)

    try {
      const invoice = await api.draftInvoice(
        patient.public_id,
        found.sessions.filter((s) => selected.has(s.public_id)).map((s) => s.public_id),
      )
      onCreated(invoice.invoice_no)
    } catch (error) {
      setProblem(explain(error))
    } finally {
      setBusy(false)
    }
  }

  const sessions = found?.sessions ?? []

  return (
    <div className="panel">
      <header>
        <h2>New invoice</h2>
      </header>
      <div className="panelbody">
        {problem === null ? null : <p className="problem">{problem}</p>}

        <div className="fields">
          <div className="field">
            <label htmlFor="ni-patient">Patient</label>
            <PatientPicker id="ni-patient" chosen={patient} onChoose={setPatient} />
          </div>
          <div className="field">
            <label htmlFor="ni-from">Treatments since</label>
            <input id="ni-from" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          </div>
        </div>

        {patient === null ? null : found === null ? (
          problem === null ? <p className="empty">Looking for sessions…</p> : null
        ) : sessions.length === 0 ? (
          <p className="empty">
            Nothing to invoice since {from || 'the first treatment'}. Only signed, billable sessions not already
            on an invoice appear here.
          </p>
        ) : (
          <div className="tablewrap" style={{ marginTop: 12 }}>
            <table>
              <thead>
                <tr>
                  <th className="checkcell">
                    <input
                      type="checkbox"
                      aria-label="Select every session"
                      checked={selected.size === sessions.length}
                      onChange={(event) =>
                        setSelected(new Set(event.target.checked ? sessions.map((s) => s.public_id) : []))
                      }
                    />
                  </th>
                  <th>Treatment date</th>
                  <th>Modality</th>
                  <th className="num">List price</th>
                  <th>Claim</th>
                  <th className="num">Claimed from payer</th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.public_id}>
                    <td className="checkcell">
                      <input
                        type="checkbox"
                        aria-label={`Invoice the session on ${session.session_date}`}
                        checked={selected.has(session.public_id)}
                        onChange={() => toggle(session.public_id)}
                      />
                    </td>
                    <td>
                      {session.session_date}
                      {session.status === 'completed' ? null : (
                        <span className="chip chip-warn" style={{ marginLeft: 8 }}>
                          {session.status}
                        </span>
                      )}
                    </td>
                    <td className="muted">{session.modality.toUpperCase()}</td>
                    <td className="num">
                      {session.list_price === null ? (
                        <span className="chip chip-danger">No price-list entry</span>
                      ) : (
                        money(session.list_price)
                      )}
                    </td>
                    <td>
                      {session.claim_no === null ? (
                        <span className="muted">Not claimed</span>
                      ) : (
                        <>
                          {session.claim_no}{' '}
                          {session.claim_status === null ? null : <ClaimStatus status={session.claim_status} />}
                        </>
                      )}
                    </td>
                    <td className="num">{money(session.payer_amount)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {sessions.some((s) => selected.has(s.public_id) && s.claim_no === null) ? (
          <p className="notice" style={{ marginTop: 12 }}>
            {(() => {
              const count = sessions.filter((s) => selected.has(s.public_id) && s.claim_no === null).length

              return `${count} selected session${count === 1 ? ' is' : 's are'} not on a claim yet, so the patient is charged the full list price for ${count === 1 ? 'it' : 'them'}.`
            })()}{' '}
            The payer’s share is taken from each session’s claim when the invoice is drafted — if the patient is
            covered, claim those sessions first.
          </p>
        ) : null}

        {sessions.some((s) => s.claim_status === 'denied' || s.claim_status === 'partially_paid') ? (
          <p className="notice" style={{ marginTop: 12 }}>
            A session here is on a claim the payer denied or short-paid. The invoice still takes off the
            amount claimed, not what was received — check before issuing.
          </p>
        ) : null}

        <div className="actions">
          <button
            type="button"
            className="btn-primary"
            disabled={busy || selected.size === 0}
            onClick={() => void draft()}
          >
            {busy ? 'Drafting…' : `Draft invoice${selected.size === 0 ? '' : ` for ${selected.size} session${selected.size === 1 ? '' : 's'}`}`}
          </button>
          <button type="button" className="btn-quiet" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </div>
    </div>
  )
}
