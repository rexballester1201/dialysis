# Dialysis Centre Management System

Clinical software for a single in-centre haemodialysis unit in South-East Asia.
Laravel 12 JSON API · MySQL 8 · React 19 SPA · offline-first PWA for bedside charting.

Read `technical-design.md` before making architectural decisions. It explains *why*
things are shaped the way they are; this file is the *rules* for working in the repo.

---

## Prime directive

Real patients are dialysed against this data. A wrong dry weight, a mis-assigned
isolation chair, or a silently overwritten treatment record can cause harm.

- **Never weaken an invariant to make a test pass.** If a test fails, the code is
  wrong until proven otherwise. If the test is genuinely wrong, say so explicitly
  and explain why before changing it.
- **Never invent clinical values.** Reference ranges, dosing, benefit rates and
  cut-offs come from a cited source or from the user. Do not guess a plausible number.
- **Never store a derived value a client sent you.** Kt/V, URR, a lot balance, an
  invoice total: if the server can compute it from the raw inputs, the server
  computes it. A chart holding a number inconsistent with its own inputs is a chart
  that will be believed and is wrong. The exception is a genuine *measurement* —
  an online-clearance Kt/V comes off the machine's sensor and is not a derivation.
- **Prefer failing loudly over guessing.** A blocked save with a clear message beats
  a saved record with a silently defaulted field.
- **A rule with no override gets worked around outside the system.** Where a rule
  must occasionally yield to clinical reality, give it an explicit, reasoned,
  recorded override — not a loophole and not silence.

---

## Repo layout

```
apps/api/            Laravel 12 — the only thing that talks to MySQL
  app/Domain/{Core,Clinical,Ops,Billing,Sync}/   models, services, policies, enums
  app/Support/Auditing/                          Auditable trait + AuditObserver
  app/Support/Database/                          HasGeneratedColumns, BaselineSchema
  app/Support/Exceptions/                        DomainRuleException
  database/schema/mysql-schema.sql               BASELINE schema (see below)
  database/migrations/                           changes AFTER the baseline only
  verify/verify_sync.php                         standalone protocol verifier
apps/bedside/        React PWA — offline-first flow sheet (Dexie + outbox)
  src/components/      board, flow sheet, observation + event forms, sync bar, sign-in
apps/console/        React SPA — admin, billing, reports (online only)
  src/views/           board, patients, patient chart, session, controls
packages/domain/     Kt/V, URR, UF-rate, IDWG maths — pure functions, shared, tested
packages/api-client/ typed fetch + Zod schemas mirroring the API Resources
packages/ui/         shared React primitives — the confirmation dialog, and the
                     sweep that keeps window.confirm out of the clients
deploy/              the shell-less cPanel bundle: DEPLOYMENT.md (full; also the source
                     of docs/deployment-guide.html) and DEPLOYMENT.html (the install
                     steps on one page -- change both when a step changes),
                     preflight.php, sql/01-04 (01 and 03 regenerate from
                     apps/api/database via BaselineSchema::strip), accounts/
                     (staff SQL generator; 04 is generated per installation and
                     gitignored; CREDENTIALS.* never leave this machine)
docs/                the documentation set (nine HTML pages) -- BUILT, never hand-edited:
                     build.php renders docs/src/*.md, docs/src/users-guide.html and
                     deploy/DEPLOYMENT.md, then validates tags, ids and every link
```

`routes/api.php.bundle` is the original design's full route list, kept for reference.
`routes/api.php` is what actually exists.

---

## Commands

```bash
# API
cd apps/api
php artisan migrate                      # loads the baseline SQL, then migrations
php artisan db:seed                      # reference data
php artisan test                         # Pest — MUST run against MySQL
./vendor/bin/pint                        # formatting
./vendor/bin/phpstan analyse             # level 8

# Scheduled work (both are also wired into routes/console.php)
php artisan dialysis:check-controls      # detective backstops; non-zero if anything is open
php artisan dialysis:summarise-quality   # rebuilds the dashboard's monthly rollup

# Database checks (fresh DB only — the smoke test loads fixtures)
mysql -u root -p dialysis < database/schema/mysql-schema.sql
mysql -u root -p dialysis < database/mysql-seed.sql
mysql -u root -p dialysis < database/mysql-smoke-test.sql    # expect 5 PASS, 0 errors
php verify/verify_sync.php                                    # expect 16 passed, 0 failed

# Staff accounts for the shell-less host. Writes SQL + a plaintext credentials sheet on
# THIS machine; neither the script nor the sheet ever goes to the server.
php deploy/accounts/make-accounts.php              # accounts.csv -> deploy/sql/04-accounts.sql
php deploy/accounts/make-accounts.php RN-002       # one new person -> deploy/accounts/out/
php deploy/accounts/make-accounts.php --reset RN-001   # new password, lockout cleared, signed out
php deploy/accounts/make-accounts.php --render-sheet deploy/accounts/CREDENTIALS.txt   # printable slips

# Documentation (from the repo root; needs apps/api/vendor for league/commonmark)
php docs/build.php                       # rebuild all nine pages, then validate; non-zero on any problem
php docs/build.php --check               # validate only

# Clients
npm install                              # workspace root
npm run typecheck                        # tsc --noEmit, all five workspaces
npm test                                 # the shared clinical maths in packages/domain
npx playwright test offline.spec.ts      # NOT YET WRITTEN — see "Known gaps"
```

---

## Non-negotiable invariants

Each is enforced in **two** layers on purpose: a service (good errors, an audit
trail, and a chance to stop the write) and a database backstop (survives console
commands, imports and stray scripts). If you touch code near one of these, run the
matching test and say in your summary that you did.

| # | Rule | Service | DB backstop | Test |
|---|---|---|---|---|
| 1 | HBsAg-reactive patients never occupy a non-HBV chair or machine | `CohortGuard` + `SessionService::assertInfectionControl` | `v_cohort_violation` (detective) | `refuses a machine dedicated to another cohort` |
| 1b | A machine never carries one cohort to another without disinfection | `MachineService::cohortCarriedOver` | `v_cohort_violation` (detective) | `refuses a machine that last treated a different cohort with no clean since` |
| 2 | Exactly one active prescription per patient | `PrescriptionService` | `hd_prescriptions_bi` / `_bu` | `never allows two overlapping prescriptions`, `refuses to widen one prescription over another` |
| 3 | A signed session is immutable except via the amendment path | `SessionLockService` | `treatment_sessions_bu` | `rejects a clinical edit to a locked session` |
| 4 | Billing columns stay writable on a locked session | `SessionLockService::ADMIN_COLUMNS` | same trigger's whitelist | `still accepts the billing link on a locked session` |
| 5 | One chair holds one patient per shift per day | `SchedulingService` | `ts_slot_station_uq` | `cannot double-book a chair in the same shift` |
| 6 | A session is billed at most once | `BenefitLedger` | `claim_sessions` PK on `session_id` | `refuses to bill the same session twice and names the claim that has it` |
| 7 | High-alert drugs require a witness, and never the person giving it | `FlowSheetService::administer` | `medication_administrations_bi` | `requires a witness for high-alert medication`, `refuses a high-alert dose the giver witnessed themselves` |
| 8 | Reused dialyzers below 80% TCV, past their count, or belonging to another patient cannot be issued | `DialyzerReuseService` | `v_dialyzer_status` (detective) | `refuses a dialyzer below 80% of its original total cell volume` |
| 9 | Total chlorine > 0.1 ppm blocks the day's first session | `WaterComplianceService` | `v_water_exceptions` (detective) | `refuses to start when total chlorine is over the action limit` |
| 10 | Every chart view is logged | `LogRecordAccess` middleware | — | `logs a chart view to record_access_logs` |
| 11 | A lot's balance is only ever moved by a movement row | `StockService` | `stock_transactions_ai` + `sl_qty_ck` | `lets the trigger move the balance, never the service` |

### Preventive first, detective always

Rules 1, 8 and 9 have views that *find* violations (`v_cohort_violation`,
`v_dialyzer_status`, `v_water_exceptions`). **Those views are the backstop, not the
control.** All three are refused at the service boundary, at the moment they can
still prevent something:

- water is checked before the first needle of the day goes in;
- a dialyzer is refused at the point of issue;
- a cohort-breaching chair or machine is refused at check-in and again at start.

The views exist because not everything comes through a service — an import, a
console command, a legacy row. **A detective control nobody reads is not a control**,
so `dialysis:check-controls` reads all three twice daily and exits non-zero while
anything is outstanding. Wire it to something that wakes a human.

### The infection-control override

Invariant 1 refuses, but it yields to an explicit reason
(`cohort_override_reason`, minimum 10 characters). A unit whose only HBV chair is
broken must still be able to dialyse the patient in front of it. The override is
written to `session_notes` as an `INFECTION CONTROL OVERRIDE` entry and lands in
`audit_logs` with everything else, and the response echoes what was overridden so a
screen cannot succeed quietly.

Do not add an override to any other invariant without asking. Rules 2–11 have no
clinical situation that requires bypassing them.

---

## MySQL rules you will otherwise get wrong

These are not style preferences. Each one was hit and fixed during design.

**1. Never add `ON DELETE CASCADE` / `SET NULL` to a column that feeds a generated column.**
MySQL rejects the FK outright with a useless "Cannot add foreign key constraint".
`treatment_sessions.patient_id` feeds `slot_patient_key`, so it cannot cascade — and
should not, because clinical rows must never vanish through a cascade.

**2. Generated columns have two halves, and `HasGeneratedColumns` handles both.**
MySQL rejects any *write* to one, and Eloquent will try as soon as a `refresh()` has
hydrated it. But MySQL also computes them *during* the write, so until they are read
back they do not exist on the model — which means an API answers its own POST with
`full_name: null`. That was a real bug in two places. Use the trait, and declare:

```php
protected array $generated = ['full_name'];
```

The 16 generated columns (the 15 in the baseline, plus `lab_results.timing_key` from the
2026_08_21 migration):

```
patients.full_name                staff.full_name
treatment_sessions.idwg_kg, .weight_loss_kg, .actual_duration_min,
                   .slot_patient_key, .slot_station_key
session_vitals.map_mmhg           dialyzer_units.tcv_pct
hd_prescriptions.effective_to_x   standing_schedules.effective_to_x
invoices.balance                  invoice_lines.line_total
patient_diagnoses.one_primary_renal   shifts.is_overnight
lab_results.timing_key
```

`ModelIntegrityTest` cross-checks every model's declaration against
`information_schema`, so a missing one fails the suite rather than surfacing as a
null in production.

**3. `DATETIME(3)`, never `TIMESTAMP`.** MySQL `TIMESTAMP` cannot represent a date past
2038-01-19, and these records are retained 10–15 years. All times are stored UTC; the
display timezone lives in `facilities.timezone`. This applies to migrations too —
Sanctum's stock migration was rewritten for it.

**4. `CHECK` cannot use `CURRENT_DATE`, `NOW()` or any non-deterministic function.**
Rules like "birth date is not in the future" belong in a FormRequest.

**5. `JSON` columns are normalised** — MySQL reorders object keys and rewrites numeric
literals. Use `JSON` when you will query with `JSON_EXTRACT` (`audit_logs.before_data`).
Use `LONGTEXT` when a payload must come back byte-identical (`sync_batches.result` —
a replayed sync batch must return exactly what the tablet already reconciled against).

**6. Partial unique indexes do not exist.** The idiom is a `STORED` generated column that
evaluates to `NULL` when the predicate is false, plus a plain `UNIQUE` key — MySQL
ignores NULLs in unique indexes. See `slot_station_key`, `one_primary_renal`.

**7. Views with aggregates or window functions are materialised**, so predicates do not
push down. Per-row lookup views are fine. `v_monthly_quality` is the definition of
record; the dashboard reads `monthly_quality_summaries`, rebuilt nightly *from that
view* by `dialysis:summarise-quality`. Never compute a summary independently — a
second implementation of "average Kt/V this month" will eventually disagree with the
first, and the one on the screen is the one people act on.

**8. Never join `session_events` directly into an aggregate over `treatment_sessions`.**
It multiplies each session row by its event count and inflates every metric. Use the
`LATERAL` subquery already in `v_monthly_quality`. There is a regression test for this,
and a second one proving the nightly rollup inherits the fix.

**9. A nullable column in a UNIQUE index does not constrain anything.** MySQL ignores
NULLs there, so `lab_results`' own `lr_uq (patient_id, test_code, specimen_date, timing)`
accepted the same haemoglobin twice whenever `timing` was null — which is most tests on a
monthly panel. The fix is rule 6's generated-column idiom in reverse: a STORED column that
coalesces to a sentinel so uniqueness can never be suppressed. See the 2026_08_21
migration. `medication_refs`' `med_ref_uq (generic_name, brand_name, strength, form)` has
the same hole -- every seeded drug has no brand -- so a second import of the seed doubled
the formulary. The seed now loads `medication_refs` (and `water_systems`, which has no
unique key at all) only into an empty table; the key itself still constrains nothing.

**10. A `public_id` must be a real ULID — 26 characters of Crockford base32**, which
excludes I, L, O and U. `HasUlids` overrides route-model binding to reject anything else
*before* it queries, so a hand-written id like `01J0PATIENT0000000000001` yields a 404 on
every `GET /patients/{id}` while `SELECT` finds the row perfectly. The smoke-test fixtures
were wrong this way and made every seeded patient unreachable through the API. Tests did not
catch it because factories mint valid ULIDs.

**11. A raw query builder row hands back MySQL's own DATETIME text**, with no zone on it
(`"2026-08-20 04:07:21.819"`). `Date.parse` in a browser reads a naive string as *local*
time, so `sync/bootstrap` shipped `started_at` that a tablet in Manila read eight hours
early — every observation was stamped with the wrong minute of the treatment. Eloquent
casts handle this; a raw `DB::table()` select does not. Convert to ISO-8601 before the
row leaves the server (`SyncController::withIsoTimes`), and parse defensively on the
client (`parseServerTime`).

**12. `information_schema` column names come back uppercased.** `$row->table_name` is
null; alias them (`table_name as tbl`) or read `$row->TABLE_NAME`.

---

## Laravel conventions in this repo

- **The schema baseline is `database/schema/mysql-schema.sql`.** Laravel loads it
  automatically on a fresh `migrate`. **Do not edit it.** Everything since has been a
  migration, and everything further should be. Two things work around it rather than
  changing it, and both are deliberate:
  - it does not create a `migrations` table, which Laravel's schema-load path assumes.
    `AppServiceProvider` recreates the ledger on the `SchemaLoaded` event.
  - it contains `USE dialysis;`, which would send the *test* suite's tables into the
    development database. `BaselineSchema` feeds the migrator a stripped copy so the
    standalone `mysql < file` path keeps working unchanged.
- **Controllers are thin**: validate (FormRequest) → authorise (Policy) → delegate to a
  service → return an API Resource. No business logic, no query building.
- **Services own invariants.** If a rule has a clinical or financial consequence, it
  lives in `app/Domain/*/Services`, not in a controller, model or observer.
- **Never return an Eloquent model from a controller.** Always an API Resource — column
  names are an implementation detail the clients must not couple to.
- **`public_id` (ULID) in every URL and payload; `id` (BIGINT) never leaves the server.**
  Only `patients`, `staff` and `treatment_sessions` carry a ULID in the baseline. `claims`
  route by `claim_no` and `invoices` by `invoice_no` — unique and externally meaningful,
  which serves the same purpose. Reference rows (stations, machines, shifts, water
  systems, medications, payers, items, lab orders) have no ULID and travel by their
  integer id; that is the baseline as found, not licence to expose a BIGINT for a
  clinical record. The sync verdicts' `server_id` is a known breach (see Known gaps).
- **Domain refusals throw `DomainRuleException`**, which renders as **422**. A stale-record
  conflict (a locked session, a duplicate observation, a cohort breach) uses a 409
  subclass. Refusing to sign an incomplete record is the system working; it must not
  look like a 500.
- **Policies are registered explicitly** in `AppServiceProvider::POLICIES`. Laravel's
  auto-discovery looks for `App\Policies\FooPolicy` beside `App\Models\Foo`, finds
  nothing here, and an undiscovered policy fails *open*.
- **A policy may return a status.** `Response::denyWithStatus(409, …)` distinguishes
  "you may not chart, you are a technician" from "this record was signed while you were
  offline". The bedside client needs to tell those apart.
- **No `data` envelope.** `JsonResource::withoutWrapping()` — the sync endpoints already
  return their payload at the top level, and an API that wraps half its responses is
  worse than either convention. Paginated collections still carry `data`/`links`/`meta`;
  there is a test pinning that, because `packages/api-client` parses it.
- **Enums are PHP backed enums + `VARCHAR` + `CHECK`**, not MySQL `ENUM`. Adding a value
  should not require `ALTER TABLE` on a hot table.
- **Cross-module access goes through services, not models.** `Billing` reads
  `treatment_sessions` through the query builder and never imports
  `Clinical\Models\TreatmentSession`.
- **"Today" and "which day" come from `FacilityCalendar`, never from `now()`.** Instants
  are UTC; a dialysis day is the unit's (`facilities.timezone`). `now()->toDateString()` is
  the UTC date, which in Manila is yesterday until 08:00 -- it refused the 06:00 shift's
  water check, gave the tablets yesterday's board and kept expired stock issuable. Use
  `today()`/`todayString()` for a default date, `dateOf($instant)` to put an instant on
  a day, and `dayWindow($date)` to query instants within a day; never `whereDate()` on a
  UTC column. Tests run on a UTC unit (`TestCase::setUp`); a test about the boundary sets
  a zone with `unitIn()` and pins the clock.
- `Model::preventLazyLoading()` is on outside production. Eager-load explicitly.

---

## Clinical maths

`packages/domain/src/index.ts` and `app/Domain/Clinical/Adequacy.php` are deliberate
twins. The tablet computes so a nurse sees a result at the chair without waiting for a
round trip; the server computes because the server's number is the one that is stored.

Both are pinned to the same worked example, in `packages/domain/src/index.test.ts` and
`tests/Feature/AdequacyTest.php`:

```
pre-BUN 60, post-BUN 20, 4.0 h, 3.0 L removed, 70 kg post weight
Kt/V = -ln(0.33333 - 0.008 × 4) + (4 - 3.5 × 0.33333) × (3.0/70) = 1.32
URR  = (60 - 20) / 60 × 100 = 66.7%
```

**If you change one, change the other and both tests.** Every constant is cited in the
source: Kt/V ≥ 1.2 and URR ≥ 65% from KDOQI 2015, the UF-rate concern threshold of
13 mL/kg/h from Flythe 2011, total chlorine 0.1 ppm from the schema and AAMI/ISO 23500,
80% TCV from this document and `v_dialyzer_status`.

---

## Offline sync rules

The bedside PWA is the reason this project succeeds or fails. Protocol details in
`technical-design.md` §7. When touching `app/Domain/Sync` or `apps/bedside/src`:

- **Every new operation type needs a `client_uuid` column with a UNIQUE index** on its
  target table. No exceptions — that column is the entire idempotency story.
  Registered handlers today: `session.upsert`, `vital.append`, `event.append`. A type the
  client enqueues but the server does not register comes back `rejected` as "Unknown
  operation type", which looks like a protocol bug and is really a missing registration.
- **Every authenticated client call must send `X-Device-Id`.** A token is bound to the
  tablet it was issued to, and a mismatch is *revoked*, not refused — so a single call
  that forgets the header signs the nurse out. `sync.ts::authHeaders` is the one place
  that builds them; add routes through it.
- **Each operation runs in its own transaction.** One malformed row must never sink a
  batch. A nurse losing four hours of charting to a bad payload ends the rollout.
  This is also why batch-level validation stays structural: `operations.*.payload` is
  `present`, not `required`, because Laravel treats `[]` as empty and would 422 the lot.
- **A replayed `batch_uuid` returns the stored response verbatim** and touches no
  clinical table.
- **A 401 during sync must not discard the outbox.** Raise `auth:reauth-required`,
  keep the queue, replay after PIN unlock.
- **Check-in, start and end are online-only, and `session.upsert` is not a side door.**
  Those three steps are where the water, cohort, machine and dialyzer checks run, and a
  refusal is only worth anything before the needle goes in — queued offline, a cohort
  breach would surface at sync time, after the patient was dialysed. `session.upsert`
  accepts observations and delivered settings only, and REJECTS (naming them) any
  operation carrying `status`, lifecycle timestamps, `station_id`/`machine_id`, dialyzer
  fields, `ktv`/`urr_pct`, `dry_weight_kg` or attribution. It once `forceFill`ed all of
  those with no checks, which let a payload start a treatment past invariants 1, 8 and 9.
  It also refuses, as a conflict, any write to a session that has already ended.
- **Vitals stay append-only, keyed by `(session_id, recorded_at)`.** That is what makes
  them conflict-free. Do not add an update path for them.
- **Online and offline writes take the same code path.** `FlowSheetService` is the only
  implementation; `AppendVitalHandler` translates sync verdicts onto it. Two
  implementations of "append a vital" would drift, and the drift would show up as a
  flow sheet that reads differently depending on which device wrote it.
- **`POST /api` is never cached by the service worker.** Writes go through the outbox.
- **Never call `window.confirm`, `alert` or `prompt`.** Use `useConfirm()` from
  `@dialysis/ui`. They block the JS thread — on the bedside app that stalls the outbox
  and the sync loop — and a boolean cannot carry the reason this system demands whenever
  a record is overridden or rewritten. `packages/ui`'s sweep fails the build if one
  reappears; name the hook's result `confirmAction`, since a bare `confirm(` is
  indistinguishable from the native global to a reader and to the sweep.
- The client's cache tables are a read-only projection. **The outbox is the only
  authoritative local state.**

---

## Testing

- **Run Pest against MySQL, never SQLite.** SQLite has no triggers here, different
  generated-column behaviour and looser CHECK semantics — a green SQLite run proves
  nothing about the invariants above. `phpunit.xml` already sets `DB_CONNECTION=mysql`.
- New clinical or billing rule → add a case to the suite that owns it. The suites are
  named for what they protect: `ClinicalInvariantsTest`, `OpsControlsTest`,
  `BillingTest`, `SessionLifecycleTest`, `AdequacyTest`, `SyncProtocolTest`.
- **`ModelIntegrityTest` is a cheap sweep, and it exists for a reason:** a broken class
  file once survived a fully green suite because the models with no controller were
  never loaded. Add new models to its list.
- New sync behaviour → add a case to `tests/Feature/SyncProtocolTest.php`, and keep
  `verify/verify_sync.php` passing 16/16.
- **A flaky test is a bug, not noise.** `changed_cols` once included `updated_at` only
  when a write crossed a millisecond boundary. Chase it; do not re-run until green.
- Changing the flow sheet → run the Playwright offline test. It is the one people skip
  and the one that catches real bugs. **It does not exist yet.**

---

## Never do this

- Hardcode a benefit rate or session cap. They changed three times in two years
  (₱2,600 → ₱4,000 → ₱6,350; 90 → 156 sessions). They are effective-dated rows in
  `benefit_programs`, and superseded rows stay so historical claims re-price correctly.
- Hard-delete a patient, a treatment session, or anything in `audit_logs`.
- `SET @allow_amendment = 1` anywhere except inside `SessionLockService::amend()`.
- Add a nullable clinical field and default it silently on save.
- Widen a `CHECK` constraint to accommodate test data.
- Use `float`/`double` for money or weights. Money is `DECIMAL(12,2)`; weights are
  `DECIMAL(6,2)`. Arithmetic goes through `bcmath` on strings — and note that bcmath
  treats a non-numeric string as zero, which on an invoice is a line silently priced
  at nothing. Validate before you add.
- Trust a client-supplied derived value. See the prime directive.
- Set a claim to `approved`, `partially_paid` or `paid` by hand. Those come from
  `recordRemittance()`, from the amounts on the advice; everything else moves only along
  `BenefitLedger::TRANSITIONS`, which the claim screen reads rather than copies.
- Delete `claim_sessions` rows to free a session. The only release is voiding a claim
  that never reached the payer, which writes each row to `audit_logs` first. Freeing the
  sessions of a claim the payer holds is how one treatment gets paid twice.
- Remove `config.platform.php` (`8.2.0`) from `apps/api/composer.json`. The development
  machine runs PHP 8.4; without the pin, `composer update` locks Symfony 8, which needs
  PHP 8.4.1, and the vendor then dies on the 8.2 and 8.3 hosts the pre-flight accepts.

---

## Known gaps

Written down because an undocumented gap gets rediscovered the expensive way.

- **No staff management in the app.** Nothing creates a staff member, sets a password or
  sets a PIN; Settings only grants roles to existing accounts. On the target host (no
  shell) accounts exist only through SQL from `deploy/accounts/make-accounts.php`, and
  nobody can change their own password -- a reset is an admin importing a reset file.
- **MySQL-defaulted timestamps use the server's zone, not UTC.** The app writes UTC from
  PHP, but columns left to `DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE` (e.g. `claims.updated_at`
  after a query-builder update) take the MySQL session zone -- UTC+8 on the dev machine.
  `water_daily_logs.created_at` is now set explicitly; rows written before that carry
  server-local time. Setting the connection's `timezone` to `+00:00` would close the rest,
  but it also moves what `CURDATE()` means inside views, so it needs checking rather than
  a one-line change.
- **Views still count days by MySQL's clock, not the unit's.** `v_current_dry_weight`,
  `v_current_prescription` and `v_stock_on_hand` anchor to `CURDATE()`, and
  `v_water_exceptions` dates a breach by `DATE(logged_at)` -- the UTC day, so a 05:30
  Manila breach is listed under the day before. Display only (the breach is listed
  either way), and the services that enforce use `FacilityCalendar`; changing a view is
  a migration and was left out of the day-boundary fix.
- **The Playwright offline test does not exist**, and the service worker in
  `apps/bedside/src/sw.ts` is type-checked but not wired into the Vite build.
- **Redis and Horizon are not installed.** Queue, cache and session run on `sync`/`file`
  drivers; `GET /api/v1/health` reports Redis as `not_configured` rather than pretending.
  Horizon additionally needs `pcntl`/`posix` and cannot run on Windows at all.
- **CI has never executed.** `.github/workflows/ci.yml` enforces all four gates and pins
  MySQL 8.0, but this is not a git repository yet.
- **Verified on MySQL 8.4.9**, which is also what the target host runs (`8.4.9-cll-lve`,
  a CloudLinux build). The schema's authors validated it on 8.0.46; CI pins 8.0 to keep
  that the line of record, so a change that needs 8.1+ will fail there rather than in
  production.
- **Both clients have now been driven in a browser** — bedside charting and the treatment
  lifecycle end to end, and the console's board, registry, patient chart, session view,
  claims, invoices and controls dashboard. Neither has an automated UI test, so that
  verification was by hand and does not repeat itself.
- **The console covers nine screens, not the whole desk.** Built: daily board, water
  (the daily check invariant 9 waits for), patient registry and chart (with bloods),
  session view with countersignature, claims (generation by benefit period, the payer
  lifecycle, remittance), invoices (drafting, issue, payments and refunds, void),
  controls dashboard, settings. Not built: machine logs, stock, quality and utilisation
  reports; prescriptions and standing patterns are read-only. Water systems cannot be
  added in the app -- a unit with none on file cannot record a check.
- **Labs have no reference-range editor and no result amendment.** `lab_test_refs` is
  seeded reference data and is not editable through the app, and a filed result can only
  be superseded by correcting the row directly — there is no amend path like a session's.
- **Nothing derives URR from a filed BUN pair.** BUN_PRE and BUN_POST can both be filed
  for a specimen date, and URR is computable from them alone, but the two are not linked:
  the session's URR still comes only from the session's own samples.
- **The bedside app has no signing or medication-administration screens.** Check-in,
  start and end exist and have been driven in a browser. Signing happens at the console.
  Medications need a witness picker and that witness's own authentication (invariant 7).
- **A chair cannot be changed after check-in.** `start` takes no `station_id`, and there is
  no reassign endpoint, so a nurse who finds the chair wrong at start can only override or
  have the session corrected outside the app.
- **Two policy questions, deliberately left as found — they need a clinical decision:**
  - An infection-control override at check-in does not carry to start. `start()` re-checks
    on purpose, so the same breach needs a second reason and leaves a second note.
  - Once a passing water check exists for the day, a later *failing* re-test does not stop
    the next start (`hadPassingCheckOn()` is "at any point today", matching the authors'
    "not every needle" policy). Chlorine breakthrough at noon does not block the 13:00 start.
    The Water screen now says so in words when it happens (`startGate()`), rather than
    showing a red "not cleared" that implies a refusal the gate will not make.
- **Remittance has no appeal path.** `recordRemittance()` writes what the payer decided
  and derives the status from it, but a denied claim cannot be corrected and resubmitted
  as a new one -- the denial code is stored for a human to act on outside the system.
  For the same reason a returned claim can only be refiled as it stands (`resubmitted`),
  and a claim can only be voided before it is submitted: changing what a claim the payer
  has already seen covers needs the `claims.resubmission_of` path, which nobody has built.
  Payer clawbacks are not modelled either -- "paid to date" can only stay or grow.
- **Invoices take the payer's share from the claim, as it stands when drafted.** The share
  is the amount *claimed*, not remitted, and nothing re-prices an invoice when its claim
  is later denied or short-paid. A session invoiced before it is claimed is charged at
  the full list price (the screen warns). `written_off` exists in the status CHECK but
  there is no endpoint for it -- forgiving a balance needs a rule about who may.
- **No senior-citizen or PWD discount.** Statutory 20% in the Philippines; how it
  interacts with a no-balance-billing package needs an answer before it is coded.
- **Nothing consumes stock automatically.** Starting a session does not issue a dialyzer
  or bloodline; issuing is a separate deliberate call.
- **`session_no_lifetime` is left null.** The column says "lifetime" but patients often
  dialysed elsewhere first, and nobody has said whether it counts sessions here or
  everywhere.
- **`extra_session` schedule exceptions are ignored**, and there is no endpoint for
  schedule exceptions at all — cancelling a day means a direct row insert.
- **Benefit periods only support `calendar_year` and `month`.** `rolling_year` and
  `lifetime` throw, because both need an anchor date nobody has defined.
- **Machines have no registration endpoint** — service history and status only.
- **Found while writing the documentation set (26 September 2026), not yet fixed:**
  - `SessionService::end()` stores a client-sent `ktv`/`urr_pct` when either BUN sample
    is missing — derivation only runs with both. The bedside app never sends them, but a
    direct API call can, against the prime directive.
  - The scheduler runs on UTC: `config/app.php` fixes `timezone` to UTC (so `APP_TIMEZONE`
    is not read) and `routes/console.php` schedules in it. `check-controls` runs at 14:00
    and 02:00 Manila, not before the morning shift as its comment intends.
  - `v_water_exceptions` lists every breach of the last 90 days, corrected or not, and
    `check-controls` exits non-zero while anything is listed — one failed chlorine test
    keeps the twice-daily check red for three months.
  - Claims never reach `audit_logs`: `BenefitLedger` writes claims (and a session's
    `benefit_claim_id`) with the query builder, which fires no model events. The trail is
    `claim_status_histories`.
  - Sync verdicts carry `server_id`, the BIGINT row id (mirrored in `verify_sync.php`).
  - `treatment_sessions_bu` fingerprints 71 of 95 columns with `CONCAT_WS`, which skips
    NULLs; the standing blood pressures, `pre_assessment` and `checked_in_at` are outside it.
    The application refuses every write to a locked session; this matters for raw SQL only.
  - `standing_schedules` has an insert trigger and no update trigger.
  - Token abilities (`admin`/`billing`/`bedside`) are written and never checked; the two
    signatures are separated by role, not person; five roles can set a dry weight, with an
    optional reason. Design questions, recorded in `docs/design-spec.html` §3 and §9.
  - The deploy bundle said MySQL 8.0.14; the minimum is 8.0.16 (CHECK constraints are
    parsed and ignored before it). The pre-flight now requires 8.0.16 and proves CHECK
    enforcement with a probe.

---

## Definition of done

Before you report a change complete:

1. `php artisan test` green **on MySQL**, and `npm test` green for the shared maths.
2. `pint` and `phpstan analyse` clean; `tsc --noEmit` clean for all five workspaces.
3. If you touched a table in the invariant table above, the matching test ran and passed
   — say which one.
4. If you added a sync operation type, `verify/verify_sync.php` still passes 16/16.
5. Any new clinical constant has a cited source in the code comment.
6. If the change alters behaviour the documentation describes, the source in `docs/src/`
   (or `deploy/DEPLOYMENT.md`) is updated and `php docs/build.php` exits 0.
7. You state plainly what you did **not** verify.

---

## Domain glossary

| Term | Meaning |
|---|---|
| **HD / HDF** | Haemodialysis / haemodiafiltration |
| **Dry weight** | Target post-dialysis weight. Effective-dated — never a mutable column. Snapshotted onto the session at check-in |
| **IDWG** | Interdialytic weight gain = pre-weight − dry weight |
| **UF** | Ultrafiltration — fluid removed. `uf_goal` planned, `net_uf_ml` achieved |
| **Kt/V, URR** | Dialysis adequacy. Targets: Kt/V ≥ 1.2, URR ≥ 65%. Derived server-side from BUN unless the machine measured them |
| **Cohort** | Infection-control group from serology: `clean` / `hbv` / `hcv`. Drives chair and machine assignment |
| **TCV** | Total cell volume of a dialyzer. Below 80% of new → discard |
| **Reuse** | Reprocessing a patient's own dialyzer. Legal and common in SE Asia; tracked per physical unit, and never issued to a second patient |
| **Vascular access** | AVF (fistula), AVG (graft), CVC (catheter) |
| **Shift** | A repeating time block (AM/MID/PM/NOC). Patients hold a standing pattern like Mon/Wed/Fri AM |
| **Station** | A chair/bed. Isolation stations are restricted by cohort |
| **Case rate** | Fixed payer amount per session, regardless of actual cost |
| **PhilHealth** | Philippine national insurer. Currently 156 HD sessions/year — verify against the latest circular |
