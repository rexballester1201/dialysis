# Dialysis Centre Management System — Technical Design

**Stack:** Laravel 12 (JSON API) · MySQL 8 · React 19 SPA · PWA with full offline charting
**Target:** one in-centre haemodialysis unit, 10–40 stations, South-East Asia
**Status:** design + working schema and scaffold, verified against a live MySQL 8.0.46

## Decisions taken

| Question | Answer | Consequence |
|---|---|---|
| React delivery | **Separate React SPA + Laravel JSON API** | Laravel serves no HTML. Auth, validation and routing are built once on each side; the payoff is that the bedside PWA and the desktop app are the same client architecture. |
| MySQL flavour | **MySQL 8.0.16+** (validated on 8.0.46) | CHECK constraints are enforced, stored generated columns and window functions work. MariaDB caveats are listed in §3.7. |
| Offline depth | **Full offline charting** | A nurse can run a complete session with the network down. This is the largest single piece of engineering in the project and it drives the whole API shape. |

---

## 1. Topology

```
┌──────────────────────┐   ┌──────────────────────┐   ┌────────────────┐
│  Bedside PWA         │   │  Desktop SPA         │   │  Wall display  │
│  React + Dexie       │   │  React (online only) │   │  read-only     │
│  offline-first       │   │  admin · billing     │   │  today's board │
└──────────┬───────────┘   └──────────┬───────────┘   └───────┬────────┘
           │  Bearer token (Sanctum)  │                       │ SSE / Reverb
           └──────────────┬───────────┴───────────────────────┘
                          │  HTTPS · JSON
              ┌───────────▼─────────────────────────────────┐
              │  Laravel 12 API                             │
              │  Domain modules · Policies · Form requests  │
              │  Sync batch processor · Claim generator     │
              │  Queue workers (Horizon) · Scheduler        │
              └───────────┬─────────────────────────────────┘
                          │
              ┌───────────▼──────────┐   ┌──────────────────┐
              │  MySQL 8             │   │  Redis           │
              │  66 tables · 12 views│   │  queue · cache   │
              │  6 trigger backstops │   │  session · locks │
              └──────────────────────┘   └──────────────────┘
```

One box runs all of it. A 4-core / 16 GB server handles ~160 sessions a day with room to spare; see §8.

---

## 2. Application layout

Laravel's default `app/Models` + `app/Http/Controllers` flattens 66 tables into two unreadable folders. Use a **modular monolith** instead — not microservices, just directories that match the domain.

```
app/
├── Domain/
│   ├── Core/          Facility, Station, Shift, Staff, Patient, Consent
│   ├── Clinical/      Prescription, TreatmentSession, Vitals, Labs, Meds, Serology
│   │   ├── Models/    Enums/  Services/  Policies/  Events/
│   ├── Ops/           Machine, WaterLog, Inventory, DialyzerReuse, Schedule, Roster
│   ├── Billing/       Payer, BenefitProgram, Invoice, Claim  +  Claims/Adapters/
│   └── Sync/          SyncBatchProcessor, Handlers/, Http/SyncController
├── Http/
│   ├── Controllers/   thin — validate, authorise, delegate, return a Resource
│   ├── Requests/      one FormRequest per write endpoint
│   ├── Resources/     API shape, decoupled from column names
│   └── Middleware/    LogRecordAccess, EnsureActiveStaff, DeviceBinding
├── Support/Auditing/  Auditable trait + AuditObserver
└── Jobs/              RollForwardSchedule, GenerateClaims, CheckWaterCompliance…
```

**Controllers stay thin.** Anything with a clinical or financial invariant lives in a service, because that is what gets unit-tested and what the trigger backstops mirror:

| Service | Invariant it owns |
|---|---|
| `SessionLockService` | Sign-off, locking, and the only sanctioned amendment path |
| `PrescriptionService` | Versioning with no overlapping effective periods |
| `CohortGuard` | Serology-based station and machine segregation |
| `BenefitLedger` | Session counting against the annual cap; no double-claiming |
| `StockIssuer` | Consumption tied to charting; expiry and quarantine checks |
| `ReuseController` | Dialyzer TCV and reuse-count limits |

### Baseline schema, not 66 migrations

Put the DDL at `database/schema/mysql-schema.sql`. Laravel's squashed-schema support loads that file automatically when `migrate` runs against an empty `migrations` table. Write migrations only for changes **after** the baseline.

```bash
mysql -u root -p -e "CREATE DATABASE dialysis"
php artisan migrate            # loads database/schema/mysql-schema.sql, then any migrations
php artisan db:seed             # reference data
```

This keeps the schema readable as one reviewable document — which matters when a clinical auditor asks what the system stores — instead of 66 files you must mentally replay.

---

## 3. What changed porting from PostgreSQL, and why

The first design leaned on Postgres features MySQL does not have. These are not cosmetic swaps; each one moves a guarantee from the database into a different layer, and you should know which.

### 3.1 Schemas → one database

Postgres `core` / `clinical` / `ops` / `billing` / `audit` become one MySQL database with Laravel-plural table names. The module boundary now lives only in PHP namespaces. **Lost:** per-schema `GRANT`s. **Mitigation:** application-level RBAC via policies, and a single write-capable DB credential held only by the app.

### 3.2 UUID primary keys → BIGINT + ULID `public_id`

InnoDB clusters the table on the primary key. Random UUID PKs scatter inserts across the B-tree and inflate every secondary index by 16 bytes per entry. So:

- `id BIGINT UNSIGNED AUTO_INCREMENT` — internal, never leaves the server.
- `public_id CHAR(26)` — a ULID, used in URLs and API payloads, so you neither leak row counts nor expose a guessable sequence.

`getRouteKeyName()` returns `public_id`, so route-model binding does the right thing automatically.

### 3.3 `TIMESTAMP` → `DATETIME(3)` — the 2038 problem is inside your retention window

MySQL `TIMESTAMP` cannot represent a date after **2038-01-19**. Dialysis records are retained 10–15 years, so records written today are already inside the window. Every datetime column is `DATETIME(3)`, stored in UTC, with the display timezone in `facilities.timezone`.

In Laravel: keep `date_format` conversions server-side and never rely on MySQL's implicit session timezone.

### 3.4 Exclusion constraints → trigger + row lock

Postgres guaranteed one active prescription per patient with `EXCLUDE USING gist (patient_id WITH =, effective_period WITH &&)`. MySQL has nothing equivalent. The replacement is three layers:

1. `PrescriptionService::revise()` takes `lockForUpdate()` on the patient's prescription rows, closes the current version, then opens the next — all in one transaction.
2. Half-open `[effective_from, effective_to)` with a generated `effective_to_x` column that turns `NULL` into `9999-12-31`, so overlap is a plain range comparison.
3. `hd_prescriptions_bi` / `_bu` triggers `SIGNAL SQLSTATE '45000'` on any overlap that slips through.

Layer 1 gives good UX, layer 3 makes the invariant true regardless of who writes.

### 3.5 Partial indexes → generated NULL column + UNIQUE

Postgres could say "unique, but only for rows matching this predicate". MySQL cannot, but it ignores NULLs in unique indexes, which yields the same result:

```sql
slot_station_key VARCHAR(64) GENERATED ALWAYS AS (
  CASE WHEN status NOT IN ('cancelled','missed','refused') AND station_id IS NOT NULL
       THEN CONCAT_WS('|', station_id, session_date, COALESCE(shift_id,0)) END) STORED,
UNIQUE KEY ts_slot_station_uq (slot_station_key)
```

Cancelled and missed rows evaluate to `NULL` and may pile up on the same slot; live rows cannot collide. Same idiom guards one-primary-renal-diagnosis-per-patient.

**Gotcha this creates:** MySQL forbids `ON DELETE CASCADE` / `SET NULL` on any column that feeds a generated column. So `treatment_sessions.patient_id` cannot cascade. That is the correct clinical behaviour anyway — patients are soft-deleted and clinical rows must never vanish through a cascade — but it is a constraint, not a choice, and it is worth knowing before you try to "fix" it.

### 3.6 Arrays → junction tables and bitmasks

| Postgres | MySQL |
|---|---|
| `stations.allowed_cohort text[]` | `station_cohorts(station_id, cohort)`; **no rows = unrestricted** |
| `shifts.weekdays smallint[]` | `weekday_mask TINYINT UNSIGNED`, bit 0 = Monday (`21` = Mon/Wed/Fri) |
| `staff_rosters.station_ids int[]` | `staff_roster_stations(roster_id, station_id)` |

The bitmask is indexable and lets you find a patient's session days with `weekday_mask & (1 << n)`.

### 3.7 Everything else

| Postgres | MySQL 8 | Note |
|---|---|---|
| `citext` | default `utf8mb4_0900_ai_ci` | already case-insensitive |
| `inet` | `VARCHAR(45)` | IPv6-safe |
| native `ENUM` types | `VARCHAR` + `CHECK` + PHP backed enums | avoids `ALTER TABLE` pain when a value is added |
| `jsonb` | `JSON` | see the warning below |
| `DISTINCT ON` | `ROW_NUMBER() OVER (PARTITION BY …)` | used in four views |
| `to_jsonb(NEW)` audit trigger | Laravel model observer | see §5.4 |
| `CHECK (birth_date <= current_date)` | **not possible** | MySQL rejects non-deterministic functions in CHECK; moved to FormRequest validation |

**MySQL `JSON` columns are normalised.** MySQL reorders object keys and rewrites numeric literals. This is fine when you query the column, and wrong when you must return a payload byte-identically. It bit this design during verification: a replayed sync batch returned the same verdicts in a different key order. `sync_batches.result` is therefore `LONGTEXT`; `audit_logs.before_data` stays `JSON` because it is queried with `JSON_EXTRACT` and semantic equality is what matters there.

**MariaDB 10.11:** `JSON` is an alias for `LONGTEXT` with no validation; `CHECK` enforcement and stored-generated-column behaviour differ enough that the generated-column tricks in §3.5 need re-testing. Prefer MySQL 8.

### 3.8 Views are weaker here

MySQL merges a simple view into the outer query, but **materialises anything with an aggregate or window function into a temporary table** — predicates do not push down. So:

- Per-row lookups (`v_current_prescription`, `v_patient_cohort`, `v_daily_board`) stay as views. They are cheap and merge fine.
- `v_monthly_quality` is correct but scans. Keep it as the definition of record, and have a nightly job write `monthly_quality_summaries` that the dashboard actually reads.

---

## 4. Data model

66 tables, 12 views, 6 triggers, 116 foreign keys, 64 check constraints, 229 indexes, 15 generated columns. Full DDL in `database/schema/mysql-schema.sql`.

`treatment_sessions` is deliberately wide (95 columns) and mirrors the paper flow sheet section by section — pre-assessment, actual circuit settings, timing, post-assessment, disposition, attestation, billing link. Nurses transcribe from the paper form during rollout, and matching the paper layout is what makes that survivable.

Columns MySQL maintains for you: `idwg_kg`, `weight_loss_kg`, `actual_duration_min`, `session_vitals.map_mmhg`, `dialyzer_units.tcv_pct`, `invoices.balance`, `invoice_lines.line_total`.

Because Eloquent will happily try to write a generated column and MySQL will reject it, `TreatmentSession` strips them in a `saving` hook:

```php
protected array $generated = ['idwg_kg', 'weight_loss_kg', 'actual_duration_min',
                              'slot_patient_key', 'slot_station_key'];

static::saving(function (self $session): void {
    $session->setRawAttributes(
        Arr::except($session->getAttributes(), $session->generated),
        sync: false,
    );
});
```

---

## 5. Cross-cutting concerns

### 5.1 Authentication

**Sanctum personal access tokens**, not SPA cookie mode. Cookie/CSRF auth assumes a live server on every request; a tablet that has been offline for three hours and holds 40 queued operations needs a bearer token it can keep using the instant the network returns.

- Login returns a token with abilities (`bedside`, `admin`, `billing`) and a 12-hour TTL matched to a shift.
- Tokens are bound to a `device_id`; a token presented from a different device is revoked and logged.
- **Clinical PIN unlock:** a 15-minute idle timeout locks the screen, and a 6-digit PIN re-issues the token. Nurses re-authenticate ~20 times a shift; a password each time guarantees a shared logged-in account, which destroys the audit trail.
- A 401 during sync **never** discards the outbox — the client raises `auth:reauth-required` and replays after unlock.

### 5.2 Authorisation

Policies per aggregate, checked in routes via `->can()`. The interesting rules are conditional on record state, not just role:

```php
public function chart(Staff $staff, TreatmentSession $session): bool
{
    return $staff->is_active
        && ! $session->isLocked()
        && ($staff->hasRole('nurse') || $staff->hasRole('head_nurse') || $staff->hasRole('nephrologist'));
}
```

### 5.3 Validation

One `FormRequest` per write endpoint. Rules that MySQL cannot express live here: `birth_date` not in the future, UF goal ≤ `max_uf_rate × duration`, post-weight within a plausible delta of pre-weight, and a nurse-facing warning (not a block) when IDWG exceeds 5% of dry weight.

### 5.4 Auditing — and its one real weakness

MySQL cannot serialise a row to JSON inside a trigger, so the audit trail is written by an Eloquent observer (`app/Support/Auditing/`). State the consequence plainly: **a direct SQL `UPDATE` bypasses the audit log.**

Mitigations, all of which you should actually do:

1. The application account is the only credential with `INSERT/UPDATE/DELETE`. Humans get a read-only account.
2. DBA write access is break-glass, time-boxed, and logged outside the application.
3. Enable MySQL binary logging with `binlog_format=ROW` and ship binlogs off-box. That is your independent record of what the database did, and it is also your point-in-time recovery.
4. The `treatment_sessions_bu` trigger still blocks a locked-record edit even from raw SQL — verified in §11.

A second, separate trail — `record_access_logs` — captures **who opened whose chart**, written by terminating middleware on every patient-scoped route. This is the question PH/MY/ID privacy regulators actually ask, and most systems cannot answer it.

### 5.5 Queues and scheduled work

Redis + Horizon. Jobs worth having on day one:

| Job | Cadence | Purpose |
|---|---|---|
| `RollForwardSchedule` | nightly | Materialise `standing_schedules` into `treatment_sessions` for the next 14 days |
| `CheckWaterCompliance` | hourly | Flag `total_chlorine > 0.1 ppm`; block the day's first session start |
| `DetectCohortViolations` | nightly | Read `v_cohort_violation`, alert the head nurse on any row |
| `RefreshQualitySummaries` | nightly | Materialise the monthly rollup |
| `GenerateClaims` | daily | Assemble claim packs from locked, billable sessions |
| `ExpiryAndReorderAlerts` | daily | `v_stock_on_hand` → 90-day expiry and reorder warnings |
| `LicenceExpiryAlerts` | weekly | Staff licences within 60 days of expiry |
| `VerifyBackup` | daily | Restore last night's dump into a scratch schema and count rows |

### 5.6 Real-time

Laravel Reverb (first-party WebSockets) for the board and wall display. One channel per `date:shift`; broadcast on session status change, not on every vital, or you will flood the socket during a busy shift.

---

## 6. React SPA

Two applications, one component library, one repo.

| | Bedside PWA | Desktop app |
|---|---|---|
| Users | Nurses, techs | Admin, billing, physicians |
| Network | Offline-first | Online |
| Storage | Dexie (IndexedDB) + outbox | React Query cache |
| Auth | Token + PIN unlock | Token + password |
| Layout | Landscape tablet, large hit targets | Dense desktop tables |

Shared: `packages/api-client` (typed fetch + Zod schemas generated from the Laravel Resources), `packages/ui`, `packages/domain` (Kt/V, UF-rate and IDWG maths — pure functions, unit-tested, used by both).

**Bedside interaction budget:** adding one intra-dialytic observation must take **≤ 15 seconds and ≤ 3 taps**, with the form pre-filled from the previous row (`flowsheet.ts::prefill`). Nurses change two or three numbers, not twelve. If this budget is missed, the unit reverts to paper and everything downstream — billing, quality reporting, registry export — degrades with it.

Flow sheet is four tabs: **Pre → Run → Post → Sign**. The Run tab is one large "add observation" button and a running chart. Complications are one tap to a coded `session_event`.

---

## 7. Offline sync protocol

This is the part that decides whether the project works. The design has one guiding idea: **make conflicts structurally impossible where you can, and explicit where you cannot.**

### 7.1 Client state

Two kinds of local store, never confused:

- **Cache tables** (`sessions`, reference data) — a read-only projection of the server, replaced wholesale on bootstrap. Never a source of truth.
- **The outbox** — the only authoritative local state. Everything a nurse types lands there first and is *unsaved* until the server acknowledges it by `op_uuid`.

The UI never calls the network directly. It calls `enqueue()`, which writes to the outbox and returns; the on-screen chart updates from the local projection. "Saved" and "synced" are separate, and a badge shows how many operations are still queued.

### 7.2 Wire format

```jsonc
POST /api/v1/sync
{
  "batch_uuid": "01J8…",          // ULID minted per flush attempt
  "device_id":  "TABLET-01",
  "operations": [
    { "op_uuid": "01J8…",          // ULID minted when the nurse tapped save
      "type": "vital.append",
      "payload": { "session_public_id": "01J8…", "recorded_at": "2026-08-19 14:30:00", … } }
  ]
}
```

Response: one verdict per operation — `applied` · `duplicate` · `conflict` · `rejected` — plus `replayed: true/false`.

### 7.3 The three guarantees

**1. Batch idempotency.** `sync_batches.batch_uuid` is unique. A replayed batch returns the stored response verbatim without touching a clinical table. Networks retry and nurses re-tap; neither may create a second set of vitals.

**2. Operation idempotency.** Every operation carries a client-minted ULID, persisted in the target row's `client_uuid` column under a UNIQUE index. If the ack was lost and the tablet resends under a *new* batch id, each operation comes back `duplicate` and nothing is written twice. Vitals additionally carry `UNIQUE (session_id, recorded_at)`, so even a client that loses its outbox cannot duplicate an observation.

**3. Partial success.** Each operation runs in **its own transaction**. One malformed row does not sink the batch. A nurse must never lose four hours of charting to a single bad payload — this is the difference between a system that survives a bad shift and one that gets abandoned after it.

### 7.4 Conflict rules

Rather than field-level merge, use **ownership**: while a session is open, exactly one device charts it, so last-write-wins on the session header is safe. That leaves exactly one conflict that genuinely occurs — the record was signed and locked on another device while this one was offline. That returns `conflict` with the server state attached; the nurse sees what changed and can resubmit through the amendment workflow. It never silently overwrites a signed record.

Intra-dialytic vitals are append-only and keyed by `(session_id, recorded_at)`, so they are conflict-free by construction. That is not an accident of the schema — it is the reason the schema is shaped that way.

### 7.5 Service worker

Asymmetric caching, deliberately:

- App shell: precached, cache-first. The tablet boots with no network at all.
- `GET /api`: network-first with cache fallback, and the fallback sets `X-From-Cache` so the UI shows an *"as of 14:12"* banner. Stale clinical data presented as current is dangerous; stale data clearly labelled is useful.
- `POST /api`: **never cached.** Writes go through the outbox, never the service worker.

Background Sync flushes the outbox when connectivity returns, even with the tab closed. **iOS Safari has no Background Sync** — the fallback is an `online` listener plus a 30-second foreground interval. If the unit standardises on iPads, that limitation must be stated in training, not discovered.

### 7.6 Operational rules that are not code

- The unit must be able to run a full day on paper. Every session field is nullable at insert and validated at sign-off, so any past date can be back-entered.
- Tablets stay on chargers between shifts and are wipeable — they will be splashed. Budget for spares.
- Screen Wake Lock during an active session; a screen that sleeps mid-run gets propped awake with a coffee cup.

---

## 8. Performance and scale

| Quantity | Estimate |
|---|---|
| Sessions | ~160/day, ~40 k/year |
| Vitals | ~9 per session, ~360 k rows/year |
| Audit rows | ~1–2 M/year |
| 10-year total | well under 20 M rows in the largest table |

Trivial for InnoDB. No partitioning before year five; when you do partition, partition `audit_logs` and `session_vitals` by year.

Targets: flow-sheet write **< 200 ms p95**; board load **< 1 s**. Both are comfortable — the risk is not row count, it is N+1 queries. Enable `Model::preventLazyLoading()` in non-production and eager-load the board query explicitly.

`innodb_buffer_pool_size` = 60–70% of RAM. The whole working set fits in memory for years.

---

## 9. Security and compliance

- **Transport:** TLS everywhere, HSTS. On-prem deployments still get TLS — clinic Wi-Fi is not a trusted network.
- **At rest:** full-disk encryption plus MySQL keyring encryption on the clinical tablespaces. Backups encrypted before they leave the box.
- **Retention:** assume **10–15 years** for dialysis records under DOH (PH) rules. Design for archival, never deletion. `patients.deleted_at` is a soft delete; a purge is a deliberate, audited job.
- **Privacy:** PH Data Privacy Act (RA 10173) / MY PDPA / ID PDP Law / SG PDPA. Register as a personal-information controller where required, appoint a DPO, keep `record_access_logs`, and write the breach-notification runbook *before* go-live, not after the breach.
- **Payer:** correct claim documentation and no double-billing a session already claimed inpatient. The `claim_sessions.session_id` primary key is the technical half; the workflow half is checking inpatient dates before generating a claim.
- **Rate limiting:** `throttle:5,1` on login, `throttle:60,1` on sync. A tablet flushing a four-hour backlog sends a handful of 100-operation batches, not hundreds of requests.

---

## 10. Testing

Run the test suite **on MySQL, never SQLite.** SQLite has no triggers here, different generated-column behaviour and looser CHECK semantics — a green SQLite suite would prove nothing about the invariants this design depends on.

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE"   value="dialysis_test"/>
```

| Layer | Tool | What it covers |
|---|---|---|
| Domain maths | Pest unit | Kt/V, URR, UF rate, IDWG, benefit counting |
| Clinical invariants | Pest feature | `tests/Feature/ClinicalInvariantsTest.php` — 13 rules |
| Sync protocol | Pest feature | `tests/Feature/SyncProtocolTest.php` — 6 protocol properties |
| Schema | SQL | `database/mysql-smoke-test.sql` — runs against a live MySQL |
| Client offline | Playwright | Load the PWA, `context.setOffline(true)`, chart a full session, go online, assert reconciliation |
| Static | PHPStan level 8, ESLint, `tsc --noEmit` | |

The Playwright offline test is the one people skip and the one that catches real bugs. Automate it before the first pilot shift.

---

## 11. Verification already done

Everything below was executed against **MySQL 8.0.46** in preparing this design, not asserted:

- `database/schema/mysql-schema.sql` loads clean: **66 tables, 12 views, 6 triggers, 116 FKs, 64 CHECKs, 229 indexes, 15 generated columns.**
- `database/mysql-smoke-test.sql` — **5 hard assertions pass, 0 errors**: overlapping-prescription rejection, high-alert-medication witness requirement, chair double-booking rejection, locked-record immutability, duplicate-claim rejection. Nine further outputs verified by inspection: serology cohort derivation (`hbv`), cohort-violation detection and clearance, computed columns (IDWG 2.90, duration 240), stock decrement (300 → 299), the amendment path, benefit utilisation (1 of 156), the daily board, the monthly rollup reporting **1** session rather than 2, and dialyzer reuse flags. The script loads fixtures and is deliberately not idempotent — run it once on a fresh database.
- `verify/verify_sync.php` — **16/16 pass**: full offline shift applied; batch replay byte-identical with no duplicate rows; same operations under a new batch id deduplicated; partial success with one bad operation; conflict raised for a write against a session signed while offline; raw SQL edit of a locked record blocked by the trigger; amendment workflow still succeeds.
- All 26 PHP files lint clean under PHP 8.4; `tsc --noEmit` passes for both the app and the service worker.

Two real defects were found and fixed during that verification, both worth knowing about:

1. MySQL rejects `ON DELETE CASCADE` on a column that feeds a generated column — the partial-index workaround and cascading deletes are mutually exclusive.
2. MySQL `JSON` columns reorder keys, which broke byte-identical idempotent replay. `sync_batches.result` is now `LONGTEXT`.

---

## 12. Build plan

| Phase | Weeks | Deliverable | Done when |
|---|---|---|---|
| 0 — Foundation | 1–2 | Baseline schema, Sanctum auth, RBAC, audit observer, seeded reference data | `mysql-smoke-test.sql` and the Pest invariant suite pass on your instance |
| 1 — Registry & scheduling | 3–5 | Patients, serology, standing schedules, roll-forward job, daily board | Front desk stops using the paper diary |
| 2 — **Bedside PWA** | 6–12 | Flow sheet, outbox, service worker, sync endpoint, conflict UI | One shift runs paperless for a week, including a deliberate network cut |
| 3 — Clinical depth | 13–16 | Prescriptions, medications, labs, vascular access, quality report | Physicians stop keeping a parallel notebook |
| 4 — Ops | 17–20 | Inventory, reprocessing, machine and water logs | The inspection binder is produced from the system |
| 5 — Billing & claims | 21–26 | Charges, invoices, benefit ledger, payer claim generation | First month filed from the system with zero missed sessions |
| 6 — Integrations | ongoing | Labs (CSV → HL7), scales, machines, registry export | Per §13 |

Phase 2 is longer than it looks and must not be compressed. Ship it before Phase 5: nurses adopting the flow sheet is what makes the billing data trustworthy, and billing built on unreliable clinical entry produces confident wrong invoices.

---

## 13. Integrations, in payoff order

1. **Payer claims.** Highest ROI. Generate a validated claim pack first; automate transmission once the format is stable. Build it as `Billing/Claims/Adapters/{PhilHealthAdapter, HmoAdapter}` behind one interface — you will add payers.
2. **Laboratory.** CSV import first, HL7 v2 `ORU^R01` when the lab supports it. `lab_results.source` already distinguishes them.
3. **Weighing scales.** Serial or BLE scale writing pre/post weight removes the most common transcription error in the unit. Cheap, high value.
4. **Dialysis machines.** Highest effort, real benefit, and capability varies sharply by fleet. `machines.data_export_mode` records what each unit can actually do. **Survey the fleet before promising this to anyone.**
5. **Patient portal.** Schedule, results, balance. Last.

---

## 14. Still open

1. **Country and payer.** Seed data is Philippine. Malaysia or Indonesia changes the payer, benefit program and registry export; the schema does not change.
2. **Tablet platform.** Android → Background Sync works. iPadOS → it does not, and the foreground fallback needs to be in the training material.
3. **Dialyzer reuse policy.** Seeded at 6 uses / 80% TCV. Confirm against your unit's SOP.
4. **Machine fleet inventory.** Decides whether §13.4 is a real phase or never.
5. **Hosting.** On-prem vs regional cloud, driven by your data-residency position. On-prem survives an ISP outage, and a dialysis unit runs regardless of the internet.
6. **Peritoneal dialysis in v1?** `clinical.modality` already carries `pd_capd` / `pd_apd`; the question is whether Phase 3 grows to cover it.

---

## Appendix — running what is in this bundle

```bash
# 1. Schema + reference data + invariant tests (needs MySQL 8.0.16+)
mysql -u root -p < database/schema/mysql-schema.sql
mysql -u root -p < database/mysql-seed.sql
mysql -u root -p < database/mysql-smoke-test.sql      # optional: demo data + assertions

# 2. Sync protocol verification (plain PHP, no Laravel needed)
php verify/verify_sync.php                             # expect: 16 passed, 0 failed

# 3. PWA type-check
cd pwa && npm install && npx tsc -p tsconfig.json --noEmit \
                      && npx tsc -p tsconfig.worker.json --noEmit
```

`app/`, `routes/` and `tests/` drop into a fresh `laravel/laravel` skeleton. Move
`database/schema/mysql-schema.sql` into place, then `php artisan migrate` loads it as the baseline.
