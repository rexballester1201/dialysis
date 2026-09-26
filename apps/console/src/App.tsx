import { useState } from 'react'

import { currentStaff, signedIn, signOut, type Staff } from './api'
import { SignIn } from './components/SignIn'
import { BoardView } from './views/BoardView'
import { ClaimDetail } from './views/ClaimDetail'
import { ClaimsView } from './views/ClaimsView'
import { ControlsView } from './views/ControlsView'
import { InvoiceDetail } from './views/InvoiceDetail'
import { InvoicesView } from './views/InvoicesView'
import { PatientDetail } from './views/PatientDetail'
import { PatientsView } from './views/PatientsView'
import { SessionDetail } from './views/SessionDetail'
import { SettingsView } from './views/SettingsView'
import { WaterView } from './views/WaterView'

/**
 * The console.
 *
 * Online-only by design — there is no outbox and no cache. Everything on screen
 * came from the server just now, which is the right trade for a desk: a billing
 * action reconciled hours later is worse than an error a clerk can act on.
 *
 * No router, for the same reason the bedside app has none: a URL that can be
 * bookmarked straight into a patient's chart is a chart view nobody asked for,
 * and every chart view is logged against the person who opened it.
 */

type Section = 'board' | 'water' | 'patients' | 'claims' | 'invoices' | 'controls' | 'settings'

type View =
  | { name: Section }
  | { name: 'claim'; claimNo: string }
  | { name: 'invoice'; invoiceNo: string }
  // A record opened from somewhere else remembers where, so "back" returns
  // there -- a biller checking a session from a claim should land on the claim.
  | { name: 'patient'; publicId: string; back?: View }
  | { name: 'session'; publicId: string; back?: View }

const NAV: { key: Section; label: string }[] = [
  { key: 'board', label: 'Daily board' },
  { key: 'water', label: 'Water' },
  { key: 'patients', label: 'Patients' },
  { key: 'claims', label: 'Claims' },
  { key: 'invoices', label: 'Invoices' },
  { key: 'controls', label: 'Controls' },
  { key: 'settings', label: 'Settings' },
]

/** The nav item a view sits under, so the nav shows where you are rather than going blank. */
function sectionOf(view: View): Section {
  switch (view.name) {
    case 'claim':
      return 'claims'
    case 'invoice':
      return 'invoices'
    case 'patient':
      return view.back === undefined ? 'patients' : sectionOf(view.back)
    case 'session':
      return view.back === undefined ? 'board' : sectionOf(view.back)
    default:
      return view.name
  }
}

function backLabel(view: View | undefined, fallback: string): string {
  if (view === undefined) return fallback
  if (view.name === 'claim') return view.claimNo
  if (view.name === 'invoice') return view.invoiceNo

  return fallback
}

export function App() {
  const [staff, setStaff] = useState<Staff | null>(() => (signedIn() ? currentStaff() : null))
  const [view, setView] = useState<View>({ name: 'board' })

  if (staff === null) {
    return <SignIn onSignedIn={setStaff} />
  }

  const section = sectionOf(view)

  return (
    <div className="shell">
      <nav className="nav">
        <span className="brand">Dialysis Console</span>

        {NAV.map((item) => (
          <button
            key={item.key}
            type="button"
            aria-current={section === item.key ? 'page' : undefined}
            onClick={() => setView({ name: item.key })}
          >
            {item.label}
          </button>
        ))}

        <div className="whoami">
          <span>{staff.fullName}</span>
          <button
            type="button"
            className="btn-link"
            onClick={() => {
              signOut()
              setStaff(null)
            }}
          >
            Sign out
          </button>
        </div>
      </nav>

      <main className="main">
        {view.name === 'board' ? (
          <BoardView onOpenSession={(publicId) => setView({ name: 'session', publicId })} />
        ) : null}

        {view.name === 'patients' ? (
          <PatientsView onOpen={(publicId) => setView({ name: 'patient', publicId })} />
        ) : null}

        {view.name === 'claims' ? (
          <ClaimsView onOpen={(claimNo) => setView({ name: 'claim', claimNo })} />
        ) : null}

        {view.name === 'invoices' ? (
          <InvoicesView onOpen={(invoiceNo) => setView({ name: 'invoice', invoiceNo })} />
        ) : null}

        {view.name === 'water' ? <WaterView /> : null}

        {view.name === 'controls' ? <ControlsView /> : null}

        {view.name === 'settings' ? <SettingsView /> : null}

        {view.name === 'claim' ? (
          <ClaimDetail
            key={view.claimNo}
            claimNo={view.claimNo}
            onBack={() => setView({ name: 'claims' })}
            onOpenSession={(publicId) => setView({ name: 'session', publicId, back: view })}
            onOpenPatient={(publicId) => setView({ name: 'patient', publicId, back: view })}
          />
        ) : null}

        {view.name === 'invoice' ? (
          <InvoiceDetail
            key={view.invoiceNo}
            invoiceNo={view.invoiceNo}
            onBack={() => setView({ name: 'invoices' })}
            onOpenSession={(publicId) => setView({ name: 'session', publicId, back: view })}
            onOpenPatient={(publicId) => setView({ name: 'patient', publicId, back: view })}
          />
        ) : null}

        {view.name === 'patient' ? (
          <PatientDetail
            key={view.publicId}
            publicId={view.publicId}
            backLabel={backLabel(view.back, 'Patients')}
            onBack={() => setView(view.back ?? { name: 'patients' })}
          />
        ) : null}

        {view.name === 'session' ? (
          <SessionDetail
            key={view.publicId}
            publicId={view.publicId}
            backLabel={backLabel(view.back, 'Board')}
            onBack={() => setView(view.back ?? { name: 'board' })}
          />
        ) : null}
      </main>
    </div>
  )
}
