## The rule this design serves {#rule}

Every rule that protects a patient or a bill is checked at the moment it can still prevent harm, by the one service that owns it — and checked again by the database underneath. When a rule has to bend, it bends only through a written reason that stays on the record. ([Index §1](index.html#the-rule) explains what that means in practice.)

This document describes the behaviour that rule produces, as built on 26 September 2026. Where the system does less than a reader might expect, it says so; where a behaviour is a choice somebody should review, it says that too.

## Scope {#scope}

The system serves **one in-centre haemodialysis unit** — seeded for a Philippine centre with 20 chairs, four shifts and PhilHealth as the main payer. It is not multi-site and has no notion of more than one facility.

### Built {#built}

| Area | What exists | Where it is used |
|---|---|---|
| Registry | Patients with an MRN, identifiers, contacts, coverage, diagnoses, status history, serology (which decides the infection-control cohort) and effective-dated dry weights | Console registers patients, shows the chart, sets a dry weight. Serology, status changes and demographic edits exist in the API only |
| Prescribing | Haemodialysis prescriptions, versioned, exactly one active per patient | Console shows them. Writing one is API-only (nephrologist) |
| Scheduling | Standing patterns (weekdays × shift × chair) and a daily board generated from them; one chair holds one patient per shift per day | Console generates and shows the board. Patterns are set through the API |
| Treatment record | Check-in, start, end; flow-sheet observations and coded events; medication doses with a witness rule; nurse signature, nephrologist countersignature, lock, amendment | Bedside tablet for check-in to end and the flow sheet; console for both signatures. Doses and amendments are API-only |
| Infection control | Chair cohorts, machine cohorts, cohort carried over on an undisinfected machine, the recorded override | Enforced at check-in and start; chair cohorts set in Settings |
| Water | A daily total-chlorine check that gates the day's first treatment; readings kept for the trend | Console, Water screen |
| Machines and dialyzers | Machine status, maintenance and disinfection logs; dialyzer reuse with volume and count limits | API only — but enforced at every start |
| Stock | Receipts, first-expiry-first-out issue, refusal of quarantined and expired lots; balances moved only by movement rows | API only |
| Labs | Panel orders, filed results, results history per patient | Console, patient chart |
| Billing | Claims per benefit period with allotment counting, the payer's status lifecycle and remittance; invoices with issue, payments, refunds and void | Console, Claims and Invoices |
| Reporting | The three detective controls, a nightly monthly-quality rollup, station utilisation | Console shows the detective controls. Quality and utilisation are API-only |
| Settings | Facility details including its timezone, chair cohorts, high-alert medication flags, staff roles; benefit programmes (added, never edited) | Console, Settings — which shows benefit programmes but has no form to add one |
| Offline charting | An outbox on the tablet, idempotent batch upload, one transaction per operation, conflicts reported rather than resolved silently | Bedside tablet |
| Audit | Before-and-after of changes to nine record types and every settings change (claims are traced by their status history instead), every chart view, every sign-in attempt, every sync batch | No screen; read with SQL ([AG·1 §6](admin-guide.html#audit)) |

### Not built {#not-built}

These are gaps, not refusals. Each is a natural next step, and none has a half-finished implementation waiting.

- **Staff accounts in the app.** Nothing creates a staff member, sets a password or a PIN, or lets anyone change their own password. Accounts come from SQL written by `deploy/accounts/make-accounts.php` ([AG·1 §2](admin-guide.html#people)).
- **Screens for work the API already does:** serology, patient status and demographic edits, prescriptions, standing patterns, amendments, medication doses, machines, dialyzer reprocessing, stock, and the quality and utilisation reports.
- **One-off scheduling.** An add-on session, a reschedule or a cancelled day has no endpoint; `extra_session` schedule exceptions are ignored.
- **Changing a chair after check-in.** The start step takes no chair and there is no reassign endpoint.
- **Lab maintenance.** No reference-range editor and no result amendment. URR is not derived from a filed pair of BUN results.
- **Payer disputes.** No appeal or resubmission of a denied claim as a new one, no clawbacks, no write-offs.
- **Other benefit periods.** Only `calendar_year` and `month`; `rolling_year` and `lifetime` are refused.
- **Tablet comforts.** The service worker is not in the build (the bedside app cannot start from cold without a network), there is no idle auto-lock (a nurse locks the tablet with the Lock button) and no screen wake lock.
- **Real time and queues.** No live board updates, no wall display, no Redis or Horizon.
- **Integrations.** No payer transmission, lab import, scales, dialysis-machine link, registry export or patient portal.

### Deliberately excluded {#excluded}

- **Hard deletion** of a patient, a treatment session, or any audit row. Retiring a patient is a soft delete.
- **Any override other than infection control.** The other rules have no clinical situation that requires bypassing them.
- **MariaDB.** It has no `LATERAL` join, which the quality view depends on, and its CHECK and generated-column behaviour differ.
- **Trusting a derived number from a device.** Covered in [§4](#principles).

### Needs a decision before it can be built {#needs-decision}

- The senior-citizen and PWD 20% discount, and how it meets a no-balance-billing package.
- What `session_no_lifetime` counts — sessions here, or everywhere the patient has dialysed.
- The anchor date for rolling-year and lifetime benefit periods.
- Whether peritoneal dialysis belongs in scope; the modality values exist, the workflow does not.
- The questions in [§9](#open-questions).

## The people, and what each cannot do {#roles}

Nine roles are seeded; seven are checked by the code. A staff member can hold several. Roles are not seniority tiers — each opens a different set of doors.

| Role | For | Cannot |
|---|---|---|
| `nephrologist` | Prescribing; charting; countersigning; amending a locked record; ordering labs | Generate the board or set standing patterns; register a patient; record water, machine or stock work; sign as the delivering nurse; bill; change settings |
| `head_nurse` | The board and standing patterns; registering patients; charting and signing as the delivering nurse; the water log, machines, stock and dialyzer reprocessing; ordering labs | Countersign, amend, prescribe, bill, change settings, or edit a closed chart |
| `nurse` | The board and standing patterns; check-in to end and the flow sheet; signing as the delivering nurse; editing an open chart | Register a patient, countersign, amend, prescribe, order labs, record water or machine work, bill, change settings |
| `technician` | The water log, machine maintenance and disinfection, stock, dialyzer reprocessing | Chart, edit any patient record, bill, change settings |
| `billing` | Claims, remittances, invoices, payments and refunds | Chart, edit patients, record water or machine work, change settings |
| `records` | Registering patients; correcting demographics, including on a closed chart | Chart, sign, bill, record water, change settings |
| `admin` | Settings and roles; registration; the board and patterns; water, machines and stock; billing; retiring a patient | Chart, check in, start or end, sign, countersign, amend, prescribe |
| `dietitian` | Nothing yet — no feature checks this role | Any write |
| `readonly` | Reading the clinical record, the board and the reports | Any write; reading claims or invoices |

Two things apply to everyone. **Every active account can read every patient's chart**, and every such read is logged with who, when and through which route ([AG·1 §6](admin-guide.html#audit)). And **a closed chart** — deceased, transferred out, transplanted, recovered function, lost to follow-up, discontinued — can be edited only by `records` and `admin`.

:::caution Choices a clinical lead should review
- **Signatures are separated by role, not by person.** The nurse signature needs `nurse` or `head_nurse`; the countersignature needs `nephrologist`. Nothing compares the two signers, so an account holding both roles could sign both. Give no account both.
- **Dry weight, serology and lab results ride on the chart-edit permission.** On an open chart, `records`, `nurse`, `head_nurse`, `nephrologist` and `admin` can all set a new dry weight, and its reason is optional. Serology decides the cohort, so the same five roles can change which chairs a patient may use.
- **Nurses can build the board and set standing patterns**, not only head nurses.
- **A unit with no nephrologist account cannot lock a single record**, and an unlocked record cannot be claimed.
:::

Each role also puts *abilities* on the sign-in token (`admin`, `billing`, `bedside`). Nothing checks them; every route authorises through the role-based policies above. They are a label, not a control.

## Principles {#principles}

These words mean exactly this throughout the set.

| Term | Definition |
|---|---|
| **Invariant** | A rule with a clinical or financial consequence, enforced in two layers: the service that owns it, which refuses with a reason and leaves an audit trail, and a database backstop — a trigger, a key, or a detective view — that survives console commands, imports and stray scripts. There are twelve ([TS·1 §5](technical-spec.html#engine)). |
| **Preventive control** | A check made at the moment it can still stop harm: the water before the day's first start, the cohort at check-in and again at start, the dialyzer when it is issued. |
| **Detective control** | A database view that finds violations that arrived another way — an import, a direct edit, a recorded override. Three exist, for cohorts, dialyzers and water. They are read twice a day by `dialysis:check-controls` and shown on the Controls screen. A detective control nobody reads is not a control. |
| **Derived value** | Anything the server can compute from inputs it holds: Kt/V and URR from the BUN samples, interdialytic weight gain, weight lost, actual duration, invoice balances and line totals, stock balances. The server computes and stores it; a device's copy is not trusted. |
| **Measurement** | A reading from an instrument, such as a machine's online-clearance Kt/V. Kept exactly as recorded, even where a derivation exists. |
| **Override** | An explicit, reasoned, recorded exception. Exactly one exists: infection control. It needs a reason of at least ten characters, becomes an *INFECTION CONTROL OVERRIDE* note on the chart and an audit row, and is echoed in the response. |
| **Effective-dated** | Changed by adding a new dated version, never by overwriting: dry weights, prescriptions, standing patterns, benefit programmes. Old versions stay, so an old record re-reads the way it was true at the time. |
| **Snapshot** | A value copied onto a record at the moment it applied. The dry weight and the prescription in force are copied onto the session at check-in, so revising either later does not rewrite past treatments. |
| **Locked record** | A treatment session with both signatures. Its clinical fields change only by amendment — a nephrologist, a reason, the before-and-after in the audit log, a note on the chart. The billing link stays writable, because claims are attached after signing. |
| **Append-only** | The flow sheet adds rows and never edits them. Each observation is keyed by session and time, so two devices cannot collide, and a correction is a new row at the real time. |
| **Online-only step** | Check-in, start and end are refused offline, because the checks they carry are only worth anything before the needle goes in. Charting is not; it queues. |
| **Fail loudly** | A refused save that names what is wrong is preferred to a saved record with a quietly defaulted field. |
| **The unit's day** | The calendar day in the facility's timezone. Every time is stored in UTC; which day an instant belongs to — for the water check, the board, stock expiry — is decided by `facilities.timezone`. |

## What the system keeps {#objects}

| Object | What it is | Stored in | The rule that matters most |
|---|---|---|---|
| Patient | A person in the unit's care, with an MRN and a public ULID | `patients`, plus identifiers, contacts, coverages, status history, diagnoses, consents, allergies | Never hard-deleted; status is a history, not a field |
| Cohort | Clean, HBV or HCV, derived from current serology | `serology_results`, read through `v_patient_cohort` | Decides which chairs and machines the patient may use |
| Dry weight | The target post-dialysis weight | `dry_weights` | Effective-dated; snapshotted onto the session at check-in |
| Prescription | The treatment ordered | `hd_prescriptions` | Exactly one active per patient |
| Standing pattern | Weekdays, shift and chair | `standing_schedules` | No overlapping patterns for one patient |
| Treatment session | One treatment: 95 columns that mirror the paper flow sheet | `treatment_sessions` | Moves through a fixed state machine; locked once both have signed |
| Observation | An intra-dialytic set of vitals | `session_vitals` | Append-only, one per session per time |
| Event | A coded complication | `session_events`, codes from `event_refs` | The code must be on the list |
| Medication dose | A dose given, or recorded as not given with a reason | `medication_administrations` | A high-alert drug needs a witness who is not the giver |
| Session note | Free text on the record | `session_notes` | Where overrides and amendments are written |
| Station | A chair; isolation chairs carry cohorts | `stations`, `station_cohorts` | A chair with no cohorts is unrestricted, not unusable |
| Machine | A dialysis machine with its logs | `machines`, maintenance and disinfection logs | Must be in service or standby; must be disinfected between cohorts |
| Dialyzer unit | One physical, reprocessed dialyzer | `dialyzer_units`, `dialyzer_reprocess_logs` | This patient's own, at least 80% of its original volume, within its reuse count |
| Water check | A day's total chlorine and other readings | `water_daily_logs`, `water_systems`, `water_quality_tests` | Above 0.1 ppm, or no reading, blocks the day's first start |
| Stock lot | A batch of an item on the shelf | `items`, `stock_lots`, `stock_transactions`, `suppliers` | Its balance moves only through a movement row |
| Lab result | A filed result for a test | `lab_orders`, `lab_results`, `lab_test_refs` | One result per patient, test, specimen date and timing |
| Benefit programme | A payer's case rate and allotment, dated | `payers`, `benefit_programs` | Added, never edited |
| Benefit period | A patient's allotment window under a programme | `benefit_periods` | Claimed sessions are counted against it |
| Claim | What the payer is asked for | `claims`, `claim_sessions`, `claim_status_histories`, `claim_attachments` | A session is billed at most once |
| Invoice | What is left for the patient | `invoices`, `invoice_lines`, `payments`, `service_items` | Balances and line totals are computed by the database |
| Audit record | The before-and-after of a change | `audit_logs` | Never deleted |
| Chart view | Who opened whose record | `record_access_logs` | Written for every successful patient-scoped request |
| Sync batch | One upload from a tablet | `sync_batches` | A replay returns the stored answer, byte for byte |

## The treatment day {#workflow}

The steps are a real sequence: the record cannot skip one. "Refused when" lists what the owning service checks, in the order it checks it.

| # | Step | Who | Where | Refused when | Recorded |
|---|---|---|---|---|---|
| 0 | Water check | technician, head nurse, admin | Console, Water | The reading is timed in the future; a reading over the limit has no action written | The readings, the shift, who; clears the unit's day |
| 1 | Generate the board | head nurse, nurse, admin | Console, Daily board | — (a clash was refused when the pattern was set) | One session per patient for the date, from the patterns |
| 2 | Check in | nurse, head nurse, nephrologist | Bedside, online | Wrong status; machine not usable; chair or machine against the cohort, with no override | Pre-dialysis weight and observations, chair, machine; dry weight and prescription snapshotted; any override note |
| 3 | Start | nurse, head nurse, nephrologist | Bedside, online | Wrong status; no pre-dialysis weight; water not cleared; machine not usable; cohort breach, with no override; dialyzer refused | Start time, delivered settings, the dialyzer unit issued |
| 4 | Chart | nurse, head nurse, nephrologist | Bedside, offline allowed | The record is locked; an observation already exists at that time; unknown event code; high-alert dose without a separate witness | Observations, events, doses |
| 5 | End | nurse, head nurse, nephrologist | Bedside, online | Wrong status | Post-dialysis values; Kt/V and URR computed; *completed*, or *aborted* with a reason |
| 6 | Sign | nurse, head nurse | Console, session | Already locked; weights, times or primary nurse missing — named | The nurse signature |
| 7 | Countersign | nephrologist | Console, session | Already locked | The countersignature; the record locks when both are in |
| 8 | Amend | nephrologist | API only | Not locked; no reason; a protected field | The change, a note on the chart, the before-and-after in the audit log |
| 9 | Claim | billing, admin | Console, Claims | Not signed; not billable; not completed; already on a claim; another patient's; no programme in force; mixes programmes or benefit periods; more sessions than the allotment has left | A draft claim, priced from the programme, each session numbered in the period |
| 10 | Payer lifecycle | billing, admin | Console, claim | A move the lifecycle does not allow; a zero approval with no denial code | Each status, the remittance, the history |
| 11 | Invoice | billing, admin | Console, Invoices | A session already invoiced; not billable | Lines, the patient's share, payments and refunds |

### How a session moves {#session-states}

| From | To | By |
|---|---|---|
| `scheduled` | `checked_in` | Check-in |
| `checked_in` | `in_progress` | Start |
| `in_progress` | `completed` | End, with the reason *completed as prescribed* |
| `in_progress` | `aborted` | End, with any other reason — a short run is never recorded as completed |

`missed`, `cancelled` and `refused` exist as statuses but no endpoint sets them. Locking is not a status: it is the moment both signatures are present.

### How a claim moves {#claim-states}

| From | May be moved by hand to |
|---|---|
| `draft` | `ready`, `submitted`, `void` |
| `ready` | `draft`, `submitted`, `void` |
| `submitted` | `acknowledged`, `in_process`, `returned`, `denied` |
| `acknowledged` | `in_process`, `returned`, `denied` |
| `in_process` | `returned`, `denied` |
| `returned` | `resubmitted` |
| `resubmitted` | `acknowledged`, `in_process`, `returned`, `denied` |

`approved`, `partially_paid` and `paid` are never picked from a list: recording the payer's remittance sets them from the amounts. Only a `draft` or `ready` claim can be voided, which releases its sessions to be claimed again; voiding a claim the payer already holds is how one treatment gets paid twice.

## Screens {#screens}

### Bedside tablet {#screens-bedside}

| Screen | What it does | Without a network |
|---|---|---|
| Sign in | Password once per tablet, then a six-digit PIN; a Lock button | Needs the network to sign in or unlock |
| Board | Today's sessions for the unit | Shows the last copy it downloaded |
| Check-in, start, end | The three lifecycle steps and their checks. When infection control refuses, it shows the breach and makes overriding with a reason a deliberate second step rather than a prompt that opens by itself. Shows the machine's runs since its last disinfection, and the server's Kt/V and URR after the end | Not offered |
| Flow sheet | Observations prefilled from the previous row; coded events; the planned UF rate, marked when above 13 ml/kg/h | Works; entries queue in the outbox |
| Sync bar | How many entries are waiting, and how many came back as conflicts or rejections | Shows the waiting count |

### Console {#screens-console}

| Screen | What it does |
|---|---|
| Daily board | The day by shift; generates the day from standing patterns; opens a session |
| Water | Records the morning check; says whether the unit is cleared to dialyse today and whether the next start will be allowed; lists the day's readings |
| Patients | Searches the registry; registers a patient |
| Patient chart | Demographics and cohort; dry weights, with a form to set a new one; prescriptions, standing pattern and serology to read; the bloods panel — order a panel, file results, move an order on |
| Session | The record and its flow sheet; the nurse signature and the countersignature |
| Claims, and a claim | Claimable sessions grouped by benefit period, with the allotment left; generate a claim; outstanding totals; move a claim through the payer's statuses; record a remittance; void a draft |
| Invoices, and an invoice | Draft from sessions; issue; take payments and refunds; void |
| Controls | The three detective controls: cohort violations, dialyzer exceptions, water exceptions |
| Settings | Facility details and timezone; chair cohorts; high-alert flags; staff roles; the benefit programmes in force (shown, not added) |

### Work the API does that no screen offers {#api-only}

| Function | Endpoint | Who may call it |
|---|---|---|
| Record serology | `POST /api/v1/patients/{patient}/serology` | Chart editors |
| Change patient status | `POST /api/v1/patients/{patient}/status` | Chart editors |
| Edit demographics | `PATCH /api/v1/patients/{patient}` | Chart editors |
| Retire a patient | `DELETE /api/v1/patients/{patient}` | admin |
| Write a prescription | `POST /api/v1/patients/{patient}/prescriptions` | nephrologist |
| Set a standing pattern | `POST /api/v1/patients/{patient}/schedule` | head nurse, nurse, admin |
| Give a medication | `POST /api/v1/sessions/{session}/medications` | nurse, head nurse, nephrologist |
| Amend a locked session | `POST /api/v1/sessions/{session}/amend` | nephrologist |
| Machines: list, maintenance, disinfection | `/api/v1/machines…` | read: anyone; write: technician, head nurse, admin |
| Dialyzers: list, reprocess, history | `/api/v1/patients/{patient}/dialyzers`, `/api/v1/dialyzers/{dialyzer}/…` | read: anyone; reprocess: technician, head nurse, admin |
| Stock: on hand, lots, receipts, issues | `/api/v1/stock…` | read: anyone; write: technician, head nurse, admin |
| Quality and utilisation reports | `GET /api/v1/reports/quality`, `/utilisation` | anyone |
| Add a benefit programme | `POST /api/v1/settings/benefit-programs` | admin ([AG·1 §3](admin-guide.html#benefit-rate)) |

## Decisions, and why {#decisions}

| Decision | Why | What it costs |
|---|---|---|
| A separate React client and a Laravel JSON API | The bedside tablet and the desk console share one architecture; the tablet must work offline | Authentication, validation and routing exist on both sides |
| MySQL 8.0.16 or newer, never MariaDB or SQLite | CHECK constraints are enforced from 8.0.16; the quality view needs `LATERAL`; the invariants depend on triggers and stored generated columns | Hosts offering only MariaDB cannot run it |
| One baseline schema file plus migrations after it | The schema stays one readable document a clinical auditor can review | The baseline is never edited; two workarounds keep Laravel happy with it ([TS·1 §11](technical-spec.html#traps)) |
| BIGINT keys inside, ULIDs outside | InnoDB clusters on the key; random keys scatter inserts. ULIDs in URLs leak neither row counts nor a guessable sequence | Only patients, staff and sessions have ULIDs; claims and invoices travel by number, reference rows by integer id |
| `DATETIME(3)` in UTC, never `TIMESTAMP` | `TIMESTAMP` ends in 2038, inside the 10–15-year retention period | Every "today" is computed from the facility's timezone |
| Each invariant twice: service and database | The service gives a readable refusal and an audit row; the database stops what never passes through a service | Two places to change when a rule changes |
| Sign-in tokens bound to one tablet, with a PIN | A tablet offline for hours needs a token it can reuse the moment the network returns; a password twenty times a shift guarantees a shared login | A copied token is revoked on sight, so a client that forgets the device header signs its nurse out |
| An outbox and idempotent sync | Networks retry and nurses re-tap; neither may create a second set of vitals; one bad row must not sink four hours of charting | Every new operation type needs a `client_uuid` column with a unique index |
| Observations append-only, keyed by time | Conflict-free by construction | Corrections are new rows, never edits |
| Check-in, start and end online only | A refusal found at sync time arrives after the patient was dialysed | A unit with no network cannot start treatment in the system; it runs on paper and back-enters |
| Adequacy computed by the server; the tablet computes too | The nurse sees a result at the chair; the stored number is the server's | Two implementations pinned to one worked example ([TS·1 §5](technical-spec.html#adequacy)) |
| Benefit rates as dated rows | The PhilHealth rate went ₱2,600 → ₱4,000 → ₱6,350 and the allotment 90 → 156 in two years | Adding a rate is a data change with rules, not an edit |
| The quality report reads a nightly copy of the quality view | The view materialises every session before it can answer; one definition of "average Kt/V" must not become two | The report can be up to a day old, says how old, and can be asked for the live view instead |
| The unit's day from the facility's timezone | A water check at 05:30 in Manila belongs to that day, not the UTC day before | Every "today" goes through one calendar service |
| One override, for infection control only | A rule with no way through gets worked around outside the system, where nothing records it | The same breach at check-in and at start needs two reasons ([§9](#open-questions)) |
| No `window.confirm`, `alert` or `prompt` | They stall the tablet's sync loop, and a yes/no cannot carry the reason the system demands | Every confirmation goes through one shared dialog |

## Open questions {#open-questions}

These were left as found on purpose. Each needs a clinical or business decision, not a code change.

1. **Does an override at check-in carry to the start?** Today it does not: the start re-checks, so the same breach needs a second reason and leaves a second note.
2. **Should a failing water re-test during the day stop the next start?** Today it does not: once a passing check exists for the day, a later failing one is shown on the Water screen but the next start is allowed — the original "not every needle" policy. Chlorine breakthrough at noon does not block the 13:00 start.
3. **Should the two signatures have to come from two different people?** Today only their roles differ ([§3](#roles)).
4. **Who may set a dry weight, and must they give a reason?** Today five roles may, and the reason is optional.
5. **Is the reuse policy right for this unit?** The seed says 6 uses and an 80% volume floor; the unit's own SOP decides.

## Definition of done {#done}

A change to this system is finished when every line below can be ticked. It is the project's own rule, from `CLAUDE.md`, made checkable.

| Check | How | Done |
|---|---|---|
| The server suite passes on MySQL, never SQLite | `php artisan test` in `apps/api` — today 282 passed | |
| The shared clinical maths passes | `npm test` at the repository root | |
| Formatting and static analysis are clean | `./vendor/bin/pint --test` and `./vendor/bin/phpstan analyse` in `apps/api` | |
| All five client workspaces type-check | `npm run typecheck` | |
| If a table in the invariant list was touched, its named test ran and passed — and the summary says which | [TS·1 §5](technical-spec.html#engine) names each test | |
| If a sync operation type was added, the protocol verifier still passes | `php verify/verify_sync.php` — 16 passed, 0 failed | |
| Every new clinical constant cites its source in a code comment | Reading the diff | |
| The summary states plainly what was not verified | Reading the summary | |
| If the change alters behaviour these documents describe, the source was edited and the set rebuilt | `php docs/build.php` exits 0 | |
