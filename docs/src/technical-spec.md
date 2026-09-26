## Stack {#stack}

Versions are the ones resolved in `apps/api/composer.lock` and `package-lock.json`, and the ones the development machine ran on 26 September 2026.

| Layer | Technology | Version | Notes |
|---|---|---|---|
| Language | PHP | 8.4.24 on the development machine | `composer.json` requires `^8.2`; the CI workflow tests 8.3 and 8.4 |
| Framework | Laravel | 12.67.0 | JSON API only; serves no HTML |
| Authentication | Laravel Sanctum | 4.3.3 | Personal access tokens, not cookie mode |
| Dates | Carbon | 3.13.2 | |
| Database | MySQL | 8.4.9 in development; `mysql:8.0` in CI | 8.0.16 or newer is required ([§6](#data-model)); MariaDB is not supported |
| Tests | Pest on PHPUnit | 3.8.7 on 11.5.56 | Always against MySQL |
| Static analysis | Larastan on PHPStan | 3.10.0 on 2.2.8 | Level 8 |
| Formatting | Laravel Pint | 1.30.5 | |
| Client runtime | Node.js | 24.18 in development; 22 in CI | Build only; nothing runs Node in production |
| UI | React | 19.2.8 | Both clients |
| Build | Vite, with the React plugin | 8.2.1, plugin 6.0.5 | |
| Types | TypeScript | 7.0.2 | Strict, `tsc --noEmit` in every workspace |
| Validation (client) | Zod | 4.4.3 | Schemas mirror the API resources |
| Offline store | Dexie (IndexedDB) | 4.4.5 | Bedside only |
| Client tests | Vitest | 3.2.7 | Clinical maths and the confirm-dialog sweep |
| Documentation | league/commonmark | 2.10.0 | Already in `apps/api/vendor`; used only by `docs/build.php` |

Not installed, on purpose for now: Redis, Horizon, Reverb, Playwright. Queue, cache and session use the `sync` and `file` drivers.

## Repository map {#map}

| Path | What it holds |
|---|---|
| `apps/api/app/Domain/Core` | Patients, staff, authentication, settings, the facility calendar (20 files) |
| `apps/api/app/Domain/Clinical` | Sessions, prescriptions, flow sheet, signing, serology, labs, adequacy (45 files) |
| `apps/api/app/Domain/Ops` | Board, scheduling, water, machines, dialyzer reuse, stock, reports, the two console commands (28 files) |
| `apps/api/app/Domain/Billing` | Claims, invoices, the benefit ledger (18 files) |
| `apps/api/app/Domain/Sync` | The sync batch processor and its three operation handlers (6 files) |
| `apps/api/app/Support` | Auditing, generated-column handling, the baseline loader, the domain exception (6 files) |
| `apps/api/app/Http` | The two middlewares and the base controller (3 files) |
| `apps/api/routes/api.php` | The live API — 80 routes. `api.php.bundle` is the original design's list, kept for reference |
| `apps/api/routes/console.php` | The schedule |
| `apps/api/database/schema/mysql-schema.sql` | The baseline schema. Never edited |
| `apps/api/database/migrations` | The three changes made after the baseline |
| `apps/api/database/mysql-seed.sql` | Reference data, safe to re-run |
| `apps/api/database/mysql-smoke-test.sql` | Five hard database assertions; loads fixtures; fresh database only |
| `apps/api/verify/verify_sync.php` | Sixteen sync-protocol checks, plain PHP, no Laravel |
| `apps/api/tests/Feature` | 18 Pest files, 282 tests |
| `apps/bedside/src` | The tablet app: sign-in, board, lifecycle, flow sheet, outbox, sync (18 files) |
| `apps/console/src` | The desk app: 11 views and their components (20 files) |
| `packages/domain` | Kt/V, URR, UF rate, IDWG — pure functions, cited, tested |
| `packages/api-client` | Typed fetch calls and Zod schemas for every resource the clients read |
| `packages/ui` | The shared confirmation dialog, and the test that keeps `window.confirm` out |
| `deploy/` | The no-shell deployment bundle ([DG·1](deployment-guide.html)) |
| `docs/` | This documentation set and its builder |

## A request, end to end {#request}

Starting a treatment, `POST /api/v1/sessions/{session}/start`, is the request with the most rules behind it. In order:

1. **Routing.** `routes/api.php` puts the route in the `v1` group behind three middlewares: `auth:sanctum` (a valid bearer token), `device` (`EnsureTokenDevice`) and `log.access` (`LogRecordAccess`).
2. **Authentication.** Sanctum resolves the token to a `Staff` model. No token, an expired token or a revoked one: **401**.
3. **Device binding.** `EnsureTokenDevice` compares the token's `device_id` with the `X-Device-Id` header. A mismatch **deletes the token**, writes a `device_mismatch` row to `login_events`, and answers **401** — the credential is treated as copied.
4. **Binding the session.** `{session}` is resolved by `public_id`. A value that is not a 26-character Crockford ULID is rejected before any query runs: **404**, exactly as for an id that does not exist.
5. **Validation.** `StartSessionRequest` checks shapes and ranges — for example `machine_id` must exist, and `cohort_override_reason`, if present, must be 10 to 255 characters. A failure is **422** with an `errors` map.
6. **Authorisation.** `TreatmentSessionPolicy::chart` refuses an inactive account or a role that does not chart (**403**, permanent) and a locked session (**409**, *"Corrections go through the amendment path"* — a matter of timing, which a tablet that was offline needs to tell apart from a lack of permission).
7. **The service.** `SessionService::start` runs inside one database transaction and checks, in this order: the state machine allows `checked_in → in_progress`; a pre-dialysis weight exists; the water gate for the session's date is open; the machine is in service or standby; the chair and machine suit the patient's cohort and the machine carries no other cohort (or a reason was given, in which case the override note is written now); the dialyzer, if one was named, is this patient's, at or above 80% of its original volume and within its count. Any refusal throws, and the transaction rolls back — including an override note already written, so the chart never records an override for a treatment that did not start.
8. **The write.** The unit that passed the dialyzer check is stamped on the session ahead of anything the request sent, together with the delivered settings and `started_at`. The model's `saving` hook strips generated columns so MySQL does not reject the write; `refresh()` reads them back so the response is not missing them.
9. **The audit.** `AuditObserver`, attached through the `Auditable` trait, writes the before-and-after of the changed columns to `audit_logs`.
10. **The response.** `TreatmentSessionResource` at the top level (no `data` envelope), plus `cohort_overrides` — empty unless a breach was overridden — and `machine_warnings`, the non-blocking hygiene notes. **200**.
11. **After the response.** `LogRecordAccess::terminate` writes a `record_access_logs` row: who, which patient, which route, from which address. It runs after the response is sent, so a chart view is never slowed by its own log, and only for responses below 400.

A domain refusal at step 7 renders through `DomainRuleException::render` as `{"message": "…"}` with **422**, or **409** for its conflict subclasses — a locked session, a duplicate observation, an infection-control breach. The breach adds `violations`, `override_with: "cohort_override_reason"` and `override_note`, so a client never has to guess the way through.

### The same path, offline {#request-offline}

A tablet that charted offline sends `POST /api/v1/sync` with a `batch_uuid`, its `device_id` and up to 500 operations, each with an `op_uuid`, a `type` and a `payload`. `SyncBatchProcessor`:

1. returns the stored response verbatim if the `batch_uuid` was seen before, touching no clinical table (`sync_batches.result` is `LONGTEXT` so the replay is byte-identical);
2. otherwise runs each operation **in its own transaction** through the handler registered for its type — `session.upsert`, `vital.append` or `event.append` — and records a verdict: `applied`, `duplicate`, `conflict` or `rejected`, with a message;
3. never lets an exception escape into the batch response: a failed operation is logged as `sync operation failed` and reported as `rejected`, and the rest of the batch still applies.

`vital.append` and `event.append` call the same `FlowSheetService` methods the online endpoints call. `session.upsert` accepts observations and delivered settings only and rejects, naming them, any payload carrying a lifecycle field, a chair or machine, dialyzer fields, adequacy values, the dry weight, or attribution. A write to a locked or already-ended session comes back as `conflict` with the server's state attached.

## API contract {#contract}

| Convention | Rule |
|---|---|
| Base path | `/api/v1` |
| Authentication | `Authorization: Bearer <token>` on everything except `health`, `auth/login` and `auth/pin-unlock` |
| Device | `X-Device-Id` on every authenticated call. Leaving it off signs the user out, because a mismatch revokes the token |
| Body | JSON in, JSON out. Resources at the top level, no `data` envelope. Paginated collections keep Laravel's `data`, `links` and `meta` — a test pins that shape, because `packages/api-client` parses it |
| Identifiers | Patients, staff and sessions by `public_id` (ULID). Claims by `claim_no`, invoices by `invoice_no`. Reference rows — stations, machines, shifts, water systems, medications, payers, items, lab orders — by their integer `id`, because the baseline gives them no ULID |
| Times | ISO 8601 with an offset. Stored as `DATETIME(3)` in UTC. A raw query-builder row is converted before it leaves the server |
| Money and weights | Decimal strings, never floats. Money is `DECIMAL(12,2)`, weights `DECIMAL(6,2)`; arithmetic on the server uses bcmath |
| Derived values | Computed by the server; a client's copy is replaced ([§5](#engine)) |
| Refusals | `{"message": "…"}`; validation adds `errors` |

| Status | Meaning here |
|---|---|
| 200, 201 | Done; 201 when something was created — an observation, a dose, a claim, a water check, a stock movement |
| 401 | No valid token — or the token was just revoked because it arrived from another device |
| 403 | The role or account may never do this |
| 404 | No such record, or a malformed ULID |
| 409 | The record changed under you: a locked session, a duplicate observation, an infection-control breach, a payment on an invoice that cannot take one |
| 422 | Validation failed, or a domain rule refused |
| 429 | Throttled: sign-in 5 a minute, PIN unlock 10 a minute, sync 60 a minute |
| 503 | `GET /api/v1/health` found a failing check |

### The routes {#routes}

| Area | Routes | Examples |
|---|---|---|
| Health and sign-in | 4 | `GET health`, `POST auth/login`, `POST auth/pin-unlock`, `POST auth/logout` |
| Sync | 2 | `GET sync/bootstrap`, `POST sync` |
| Treatment sessions | 13 | list, show, check-in, start, end, vitals, events, medications, both signatures, amend |
| Patients and their clinical record | 21 | registry, dry weights, serology, prescriptions, standing schedule, status, available chairs, dialyzers, lab orders and results |
| Board | 2 | `GET board`, `POST board/generate` |
| Billing | 14 | claims, claimable sessions, remittance, outstanding; invoices, invoiceable sessions, issue, payments, void |
| Water, machines, dialyzers, stock | 13 | water logs; machine list, show, maintenance, disinfection; dialyzer history and reprocessing; stock, lots, receipts, issues |
| Reports | 5 | quality, utilisation, and the three detective controls |
| Settings | 6 | read all; facility; chair cohorts; high-alert flag; staff roles; benefit programmes |
| **Total** | **80** | 37 GET, 37 POST, 3 PATCH, 2 PUT, 1 DELETE |

`php artisan route:list` prints the full list with middleware.

## The rule engine {#engine}

Twelve invariants, each enforced twice. If you change code near one, run its test and say in your summary that you did.

| # | Rule | Service | Database backstop | Test |
|---|---|---|---|---|
| 1 | An HBsAg-reactive patient never occupies a non-HBV chair or machine | `CohortGuard`; `SessionService`, in `assertInfectionControl` | `v_cohort_violation` (detective) | *refuses a machine dedicated to another cohort* — MachineAndStockTest |
| 1b | A machine never carries one cohort to another without disinfection | `MachineService`, in `cohortCarriedOver` | `v_cohort_violation` (detective) | *refuses a machine that last treated a different cohort with no clean since* — MachineAndStockTest |
| 2 | Exactly one active prescription per patient | `PrescriptionService` | triggers `hd_prescriptions_bi`, `_bu` | *never allows two overlapping prescriptions* — ClinicalInvariantsTest; *refuses to widen one prescription over another* — SessionLifecycleTest |
| 3 | A signed session is immutable except through amendment | `SessionLockService` | trigger `treatment_sessions_bu` | *rejects a clinical edit to a locked session* — ClinicalInvariantsTest |
| 4 | The billing columns stay writable on a locked session | `SessionLockService`, its `ADMIN_COLUMNS` | the same trigger's column list | *still accepts the billing link on a locked session* — ClinicalInvariantsTest |
| 5 | One chair holds one patient per shift per day | `SchedulingService` | unique key `ts_slot_station_uq` | *cannot double-book a chair in the same shift* — ClinicalInvariantsTest |
| 6 | A session is billed at most once | `BenefitLedger` | primary key of `claim_sessions` on `session_id` | *refuses to bill the same session twice and names the claim that has it* — BillingTest |
| 7 | A high-alert drug needs a witness, and never the person giving it | `FlowSheetService`, in `administer` | trigger `medication_administrations_bi` | *requires a witness for high-alert medication* — ClinicalInvariantsTest; *refuses a high-alert dose the giver witnessed themselves* — SessionLifecycleTest |
| 8 | A reused dialyzer below 80% of its original volume, past its count, or another patient's is never issued | `DialyzerReuseService` | `v_dialyzer_status` (detective) | *refuses a dialyzer below 80% of its original total cell volume* — OpsControlsTest |
| 9 | Total chlorine above 0.1 ppm blocks the day's first session | `WaterComplianceService` | `v_water_exceptions` (detective) | *refuses to start when total chlorine is over the action limit* — OpsControlsTest |
| 10 | Every chart view is logged | `LogRecordAccess` middleware | — | *logs a chart view to record_access_logs* — AuditingTest |
| 11 | A lot's balance moves only through a movement row | `StockService` | trigger `stock_transactions_ai`, check `sl_qty_ck` | *lets the trigger move the balance, never the service* — MachineAndStockTest |

Rules 1, 8 and 9 have detective views as their database half. Those views are the backstop, not the control: all three are refused at the service boundary, where they can still prevent something. `dialysis:check-controls` reads the views twice a day and exits non-zero while anything is outstanding.

### The order of checks {#order}

| Step | Checks, in order |
|---|---|
| Check-in | state `scheduled`; prescription and dry weight in force on the session date looked up; machine usable; infection control (override writes its note); then pre-dialysis values saved, dry weight and prescription snapshotted, primary nurse defaulted to the caller |
| Start | state `checked_in`; pre-dialysis weight present; water gate; machine usable; infection control; dialyzer issuable, then issued; then settings and `started_at` saved |
| End | state `in_progress`; reason decides `completed` or `aborted`; Kt/V and URR derived from the samples and placed ahead of anything sent |
| Nurse signature | not locked; `pre_weight_kg`, `post_weight_kg`, `started_at`, `ended_at` and `primary_nurse_id` all present, or the refusal names the missing ones |
| Countersignature | not locked; locks the record if both signatures are now present |
| Amendment | locked; a reason; none of `id`, `public_id`, `patient_id`, `locked_at`; then `SET @allow_amendment = 1` for this connection, the change, the note, and back to 0 in a `finally` |
| Claim | sessions exist and belong to the patient; each is locked, billable, completed and unclaimed; a programme is in force; all share one programme and one benefit period; the count fits what remains; then numbered and priced |

Check-in and start each run in one transaction, because some of their checks write — an override note, a dialyzer stamped as issued — and a later refusal must take those writes back with it.

### Adequacy, computed twice on purpose {#adequacy}

`packages/domain/src/index.ts` and `app/Domain/Clinical/Adequacy.php` are twins. The tablet computes so the nurse sees a result at the chair; the server computes because its number is the one stored. Both are pinned to one worked example, in `packages/domain/src/index.test.ts` and `tests/Feature/AdequacyTest.php`:

```text
pre-BUN 60, post-BUN 20, 4.0 h, 3.0 L removed, 70 kg post weight
Kt/V = -ln(0.33333 - 0.008 × 4) + (4 - 3.5 × 0.33333) × (3.0/70) = 1.32
URR  = (60 - 20) / 60 × 100 = 66.7%
```

Change one and you change the other and both tests. Every constant is cited in the source: Kt/V ≥ 1.2 and URR ≥ 65% from KDOQI 2015, the UF-rate concern threshold of 13 mL/kg/h from Flythe 2011, total chlorine 0.1 ppm from AAMI/ISO 23500, the 80% dialyzer volume floor from the schema and `v_dialyzer_status`. A Kt/V measured by the machine (`online_clearance`, `ionic`) is kept as sent; so is `equilibrated`, an equation this code has not been given.

### The water gate {#water-gate}

`WaterComplianceService::startGate()` makes the one decision that both the refusal and the Water screen use. The day's first start needs the latest check of the unit's day to have recorded total chlorine at or below 0.1 ppm. Once any start has happened that day, a start is allowed if a passing check existed at any point in the day — the original authors' "not every needle" policy — or if the latest check passes. A failing re-test at noon is shown on the Water screen but does not stop the 13:00 start; that is an open question for the clinical lead, not a bug ([DS·1 §9](design-spec.html#open-questions)). "Today" is the unit's day from `FacilityCalendar`, and a check timed more than two minutes in the future is refused.

### Known defects {#known-defects}

Found while writing this document, and left for a separate change rather than fixed inside a documentation task:

- **A client's Kt/V or URR is stored when the BUN samples are missing.** `SessionService::end()` derives adequacy only when both BUN values are present; otherwise a `ktv` or `urr_pct` sent in the request is saved as sent. The bedside app never sends either, so no screen can do this, but a direct API call can — against the rule that the server never stores a derived value it cannot compute.
- **Sync verdicts carry the internal row id.** An `applied` verdict includes `server_id`, the BIGINT primary key of the row written. `verify/verify_sync.php` mirrors it. It contradicts "the BIGINT id never leaves the server"; nothing in the clients depends on it.
- **The locked-session trigger covers 71 of the 95 columns.** `treatment_sessions_bu` fingerprints a fixed list with `CONCAT_WS`, which skips NULLs, so moving a value between two adjacent nullable columns leaves the fingerprint unchanged; and six clinical columns — the four standing blood pressures, `pre_assessment` and `checked_in_at` — are outside the list. The application refuses every write to a locked session, so this matters only for direct SQL.
- **The scheduler runs on UTC.** `config/app.php` fixes the application timezone to UTC and `routes/console.php` schedules in it, so the twice-daily check runs at 06:00 and 18:00 UTC — 14:00 and 02:00 in Manila — not before the morning shift as its comment intends. `APP_TIMEZONE` in `.env` is not read.
- **A corrected water breach keeps `check-controls` failing for 90 days.** `v_water_exceptions` lists every breach of the last 90 days whether or not an action was recorded, and the command exits non-zero while any row is listed — so a single failed test makes the twice-daily check fail at every run for three months, which trains people to ignore it.
- **Claims never reach the audit log.** `Claim` carries the `Auditable` trait, but `BenefitLedger` creates and updates claims, and sets a session's billing link, with the query builder, which fires no model events. A claim's trail is `claim_status_histories` — every status with who and a remark — plus its own amount columns; there is no before-and-after for a remittance that is recorded wrongly.
- **`standing_schedules` has an insert trigger but no update trigger**, so an overlapping pattern written by a direct `UPDATE` would pass the database. The service refuses it.

## Data model {#data-model}

69 tables: 66 in the baseline, three added by migrations. 12 views, 6 triggers, 117 foreign keys, 64 CHECK constraints, 238 indexes, 16 stored generated columns. MySQL 8.0.16 or newer, because earlier versions parse CHECK constraints and silently ignore them, and the quality view needs `LATERAL` (8.0.14).

| Group | Tables |
|---|---|
| The unit and its people (9) | `facilities`, `stations`, `station_cohorts`, `shifts`, `staff`, `roles`, `role_staff`, `staff_rosters`, `staff_roster_stations` |
| Patient registry (8) | `patients`, `patient_identifiers`, `patient_contacts`, `patient_coverages`, `patient_status_histories`, `patient_diagnoses`, `consents`, `allergies` |
| Clinical background (7) | `serology_results`, `vaccinations`, `vascular_accesses`, `access_events`, `anthropometry_records`, `hospitalisations`, `dry_weights` |
| Prescribing and scheduling (4) | `hd_prescriptions`, `medication_orders`, `standing_schedules`, `schedule_exceptions` |
| The treatment record (5) | `treatment_sessions` (95 columns), `session_vitals`, `session_events`, `session_notes`, `medication_administrations` |
| Labs (3) | `lab_orders`, `lab_results`, `lab_test_refs` |
| Reference lists (3) | `diagnosis_refs`, `event_refs`, `medication_refs` |
| Machines and water (6) | `machines`, `machine_maintenance_logs`, `machine_disinfection_logs`, `water_systems`, `water_daily_logs`, `water_quality_tests` |
| Dialyzers and stock (6) | `dialyzer_units`, `dialyzer_reprocess_logs`, `items`, `stock_lots`, `stock_transactions`, `suppliers` |
| Billing (11) | `payers`, `benefit_programs`, `benefit_periods`, `claims`, `claim_sessions`, `claim_status_histories`, `claim_attachments`, `service_items`, `invoices`, `invoice_lines`, `payments` |
| Audit and sync (4) | `audit_logs`, `record_access_logs`, `login_events`, `sync_batches` |
| Added after the baseline (3) | `personal_access_tokens` (Sanctum), `monthly_quality_summaries` (the nightly rollup), `migrations` (Laravel's ledger) |

The third migration, `2026_08_21_100000_close_lab_result_duplicate_hole`, added no table: it gave `lab_results` a generated `timing_key` and a new unique key, because the baseline's key included a nullable `timing` column and MySQL ignores rows with a NULL in a unique key.

| View | What it answers |
|---|---|
| `v_patient_serology_current` | Each patient's latest result per marker |
| `v_patient_cohort` | Clean, HBV or HCV, derived from current serology |
| `v_current_prescription`, `v_current_dry_weight` | What is in force today — by MySQL's clock (`CURDATE()`); services use the session date instead |
| `v_daily_board` | The day's sessions with patient, chair, machine, cohort and weights |
| `v_cohort_violation` | Detective: anyone seated against their cohort (rules 1, 1b) |
| `v_dialyzer_status` | Detective: dialyzers that must not be issued again (rule 8) |
| `v_water_exceptions` | Detective: readings over their limits (rule 9) — dated by the UTC day |
| `v_stock_on_hand` | Lots with balance and expiry |
| `v_benefit_utilisation` | Allotted, claimed and remaining per benefit period |
| `v_monthly_quality` | The monthly quality figures — the definition of record, with a `LATERAL` join so events do not multiply sessions |
| `v_station_utilisation` | Chair use over a period |

| Trigger | Stops |
|---|---|
| `hd_prescriptions_bi`, `hd_prescriptions_bu` | An overlapping prescription period for a patient |
| `standing_schedules_bi` | An overlapping standing pattern (on insert) |
| `treatment_sessions_bu` | A change to a locked session's clinical columns, unless `@allow_amendment = 1` |
| `medication_administrations_bi` | A high-alert dose given without a witness |
| `stock_transactions_ai` | Moves the lot's balance by the movement's quantity — the only thing that does |

The 16 stored generated columns: `patients.full_name`, `staff.full_name`; `treatment_sessions.idwg_kg`, `weight_loss_kg`, `actual_duration_min`, `slot_patient_key`, `slot_station_key`; `session_vitals.map_mmhg`; `dialyzer_units.tcv_pct`; `hd_prescriptions.effective_to_x`, `standing_schedules.effective_to_x`; `invoices.balance`, `invoice_lines.line_total`; `patient_diagnoses.one_primary_renal`; `shifts.is_overnight`; and `lab_results.timing_key`. Each model declares its own in `protected array $generated`, and `ModelIntegrityTest` cross-checks the declarations against `information_schema`.

Six tables carry a `client_uuid` with a unique index — `treatment_sessions`, `session_vitals`, `session_events`, `session_notes`, `medication_administrations`, `stock_transactions` — which is what makes an offline operation idempotent. No foreign key cascades a delete into clinical rows, and none can on a column that feeds a generated column.

## Modules {#modules}

| Module | Owns | Its services |
|---|---|---|
| Core | Patients, staff, sign-in, settings, the unit's calendar | `AuthService`, `PatientService`, `SettingsService`, `FacilityCalendar` |
| Clinical | The treatment record and everything that feeds it | `SessionService`, `SessionLockService`, `FlowSheetService`, `PrescriptionService`, `CohortGuard`, `SerologyService`, `LabService`; `Adequacy` |
| Ops | The floor: board, water, machines, dialyzers, stock, reports | `SchedulingService`, `WaterComplianceService`, `MachineService`, `DialyzerReuseService`, `StockService`, `DetectiveControlReader`, `QualitySummariser` |
| Billing | Claims and invoices | `BenefitLedger`, `InvoiceService` |
| Sync | The offline protocol | `SyncBatchProcessor` and the `session.upsert`, `vital.append`, `event.append` handlers |
| Support | Cross-cutting machinery | `Auditable` + `AuditObserver`, `HasGeneratedColumns`, `BaselineSchema`, `DomainRuleException` |

Controllers are thin: validate with a FormRequest, authorise with a policy, delegate to a service, return a resource. Modules reach each other through services or the query builder — Billing reads `treatment_sessions` with the query builder and never imports the Clinical model. Six policies are registered explicitly in `AppServiceProvider::POLICIES`, because Laravel's discovery would find none of them and an undiscovered policy fails open; water and ops work authorise through two gates, `water.view` and `water.record`.

## Front end {#front-end}

Five npm workspaces. Both apps are static builds that call `/api/v1` with relative URLs, so they must be served from the API's origin — there is no CORS configuration.

**Bedside** (`apps/bedside`) is offline-first. Two local stores, never confused: the **cache** — sessions and reference data from `GET sync/bootstrap`, replaced wholesale, never a source of truth — and the **outbox**, the only authoritative local state. Charting calls `enqueue()`, which writes to the outbox and returns; the screen updates from the local copy. The sync loop flushes on the `online` event and every 30 seconds. A 401 during sync raises `auth:reauth-required`, keeps the outbox, and replays after the PIN unlock. Check-in, start and end call the API directly and are disabled offline. `sync.ts::authHeaders` is the one place that builds the bearer and `X-Device-Id` headers. The service worker in `src/sw.ts` type-checks but is not in the Vite build.

**Console** (`apps/console`) is online only, with no router: a section state picks one of seven sections — board, water, patients, claims, invoices, controls, settings — and four detail views: patient, session, claim, invoice.

**Shared packages.** `packages/domain` holds the clinical maths. `packages/api-client` holds typed calls and Zod schemas that mirror the API resources, so a changed resource fails loudly in the client instead of rendering `undefined`. `packages/ui` holds `useConfirm()` — the only way either app asks for confirmation — and a test that fails the build if `window.confirm`, `alert` or `prompt` reappear; the hook's result is always named `confirmAction`, since a bare `confirm(` is indistinguishable from the native global.

## Security {#security}

| Area | What is in place | What is not |
|---|---|---|
| Sign-in | Sanctum tokens, 12-hour lifetime; one token per device per person; five failed attempts lock the account for 15 minutes; every attempt, success or failure, written to `login_events` | No password change or reset in the app; resets are SQL ([AG·1 §2](admin-guide.html#people)) |
| Tablet PIN | Six digits; works only on a device where that person has already signed in with a password; throttled; failures count toward the lockout | No idle auto-lock; the nurse presses Lock |
| Device binding | A token presented from another device is revoked and logged | — |
| Authorisation | Role-based policies on every route, registered explicitly; settings check `admin` in every action | Token abilities are recorded but never checked |
| Audit | Before-and-after rows for changes made through the models of nine record types, and for every settings change; chart views; sign-ins; sync batches | Writes made with the query builder skip it — claims among them ([§5](#known-defects)); so does any direct SQL — hence ROW binary logging and no human write access to the database |
| Transport and storage | HTTPS required by the deployment; `APP_DEBUG=false` in production | Tokens live in the browser's `localStorage`, so a script injected into either app could read them |
| Identifiers | ULIDs for patients, staff and sessions | Reference rows travel by integer id; sync verdicts carry `server_id` ([§5](#known-defects)) |

No independent security review has been done.

## Tests {#tests}

Always on MySQL. SQLite has no triggers here, different generated-column behaviour and looser CHECK semantics, so a green SQLite run would prove nothing about the invariants. `phpunit.xml` sets `DB_CONNECTION=mysql` and `DB_DATABASE=dialysis_test`; `TestCase` pins the unit's timezone to UTC, and the day-boundary tests move it with `unitIn()`.

```bash
cd apps/api
php artisan test                       # 282 passed, 1,309 assertions on 26 September 2026
./vendor/bin/pint --test               # passed
./vendor/bin/phpstan analyse           # level 8, no errors
cd ../..
npm test                               # 6 clinical-maths tests, 3 sweep tests
npm run typecheck                      # five workspaces, clean
```

The database's own checks need a fresh database, because the smoke test loads fixtures:

```bash
mysql -u root -e "CREATE DATABASE dialysis_scratch"
mysql -u root dialysis_scratch < deploy/sql/01-schema.sql       # the baseline without its CREATE DATABASE and USE
sed 's/^USE dialysis;//' apps/api/database/mysql-seed.sql | mysql -u root dialysis_scratch
sed 's/^USE dialysis;//' apps/api/database/mysql-smoke-test.sql | mysql -u root dialysis_scratch   # 5 PASS, 0 FAIL
DB_DATABASE=dialysis_scratch php apps/api/verify/verify_sync.php   # 16 passed, 0 failed
mysql -u root -e "DROP DATABASE dialysis_scratch"
```

The seed and the smoke test both open with `USE dialysis;`. Run them unedited and they write into the development database, whatever database you named on the command line.

| Suite | Tests | Protects |
|---|---|---|
| BillingTest | 38 | Claims, allotments, the payer lifecycle, remittance |
| OpsControlsTest | 30 | Water gate, dialyzer reuse, detective controls |
| SessionLifecycleTest | 27 | Check-in to lock, amendment, witness |
| InvoicingTest | 21 | Invoices, payments, refunds, void |
| LabsTest | 21 | Orders, results, the duplicate hole |
| SyncProtocolTest | 19 | Idempotency, partial success, conflicts, the upsert whitelist |
| MachineAndStockTest | 19 | Machine status, cohort carry-over, stock movements |
| SettingsTest | 14 | Every setting and its refusals |
| PatientRegistryTest | 14 | Registration, status history, closed charts |
| ClinicalInvariantsTest | 13 | The original invariant set |
| SchedulingTest | 12 | Standing patterns and the board |
| ReportingTest | 10 | Quality rollup, the event-join regression |
| AdequacyTest | 10 | The worked example and its edges |
| AuthenticationTest | 9 | Tokens, PIN, lockout, device binding |
| AuditingTest | 9 | Audit rows and chart-view logging |
| CohortControlTest | 8 | Infection control and the override |
| UnitCalendarTest | 6 | The unit's day across timezones |
| ModelIntegrityTest | 4 | Every model loads; generated-column declarations match the database |

The CI workflow runs all of this on PHP 8.3 and 8.4 against MySQL 8.0, and the client checks on Node 22. **It has never run**, because the project is not a git repository. There is no automated UI test, and the Playwright offline test the design calls for does not exist.

## Conventions and traps {#traps}

Each of these was hit and fixed once. `CLAUDE.md` has the full list; these are the ones most likely to cost a day.

- **Never edit the baseline schema.** Changes are migrations. `AppServiceProvider` recreates the `migrations` ledger when the baseline loads (the baseline does not create it), and `BaselineSchema` strips the baseline's `USE dialysis;` so the test suite's tables do not land in the development database.
- **Every migration needs a SQL twin for production.** The target host has no shell, so `php artisan migrate` never runs there; `deploy/sql/02-post-baseline.sql` is the three current migrations as SQL, guarded so it can be re-run. Update the health check's and the pre-flight's expected counts if you add objects.
- **Generated columns have two halves.** MySQL rejects a write to one, and the value does not exist on the model until it is read back. Use `HasGeneratedColumns` and declare `$generated`.
- **No `ON DELETE CASCADE` or `SET NULL` on a column that feeds a generated column.** MySQL refuses the foreign key with an unhelpful message.
- **`DATETIME(3)`, never `TIMESTAMP`**, and every "today" through `FacilityCalendar` — never `whereDate()` on a UTC column, never `today()` on the application clock.
- **A raw query-builder row returns MySQL's naive datetime text**, which a browser reads as local time. Convert to ISO 8601 before it leaves the server.
- **A nullable column in a unique key constrains nothing.** Use a stored generated column that coalesces to a sentinel.
- **`information_schema` columns come back uppercased.** Alias them.
- **A public id must be a real ULID** — Crockford base32 excludes I, L, O and U — or route binding returns 404 before it queries.
- **PHP's array `+` keeps the left operand.** Server-derived values go on the left so they win.
- **Laravel treats `[]` as empty.** An array that may legitimately be empty is validated `present`, not `required`.
- **bcmath treats a non-numeric string as zero.** Validate before adding, or an invoice line is priced at nothing.
- **Never join `session_events` straight into an aggregate over sessions**; it multiplies rows. Use the `LATERAL` subquery in `v_monthly_quality`.
- **Never compute a summary a second way.** The quality report's figures come from `v_monthly_quality` through the nightly rollup, and nowhere else.
- **Every new sync operation type needs a registered handler and a `client_uuid` unique index** on its table; an unregistered type comes back `rejected` as "Unknown operation type".
- **`Model::preventLazyLoading()` is on outside production.** Eager-load explicitly.
- **A flaky test is a bug.** Chase it.
