# Initial prompt for Claude Code

Copy everything below the line into a fresh Claude Code session in an empty repo,
with `technical-design.md`, `CLAUDE.md`, `database/`, `app/`, `routes/`, `tests/`,
`pwa/` and `verify/` from this bundle placed at the repo root.

---

We are building a dialysis centre management system for a single in-centre
haemodialysis unit in South-East Asia. Laravel 12 JSON API, MySQL 8, React 19 SPA,
and an offline-first PWA for bedside charting.

**This is clinical software.** Real patients are dialysed against this data. Read
`CLAUDE.md` before writing any code — it lists ten invariants that must not be
weakened, and eight MySQL behaviours that will otherwise bite you.

## Read first, in this order

1. `CLAUDE.md` — the rules for working in this repo.
2. `technical-design.md` — the design and, more importantly, the reasoning. §3 explains
   every place the MySQL port differs from a PostgreSQL design and what guarantee moved
   where. §7 is the offline sync protocol.
3. `database/schema/mysql-schema.sql` — 66 tables, 12 views, 6 triggers. Skim the header
   comment block; it explains the non-obvious column choices.

**Do not redesign the schema.** It has been validated against MySQL 8.0.46 and its
invariants are covered by tests. If you believe something in it is wrong, say so and
wait for me — do not change it unilaterally.

## Goal for this session: Phase 0 — Foundation

A running API with auth, RBAC, auditing and the baseline schema in place, with the
existing test suites green. No clinical features yet. Work through these in order and
stop at each gate.

### 1. Scaffold the monorepo

```
apps/api/       Laravel 12
apps/bedside/   Vite + React 19 + TypeScript (PWA)
apps/console/   Vite + React 19 + TypeScript
packages/domain/        pure TS: Kt/V, URR, UF rate, IDWG
packages/api-client/    typed fetch + Zod
```

Move the bundle's `app/`, `routes/api.php`, `tests/`, `database/` and `verify/` into
`apps/api/`, and `pwa/src` into `apps/bedside/src`. Keep `CLAUDE.md` and
`technical-design.md` at the repo root.

### 2. Install and configure

- `laravel/sanctum`, `laravel/horizon`, `pestphp/pest`, `larastan/larastan`, `laravel/pint`
- MySQL 8.0.16+ connection; **verify `SELECT VERSION()` before proceeding.**
- `phpunit.xml`: `DB_CONNECTION=mysql`, `DB_DATABASE=dialysis_test`. Not SQLite — the
  invariants depend on triggers and generated columns SQLite does not have.
- Redis for queue, cache and session.

**Gate 1:** `php artisan migrate` on an empty `dialysis` database loads
`database/schema/mysql-schema.sql` as the baseline. Confirm by counting objects:
66 tables, 12 views, 6 triggers. Then `php artisan db:seed` loads `mysql-seed.sql`.
Report the actual counts you observed, not the expected ones.

### 3. Prove the database invariants still hold

Run against a **fresh** database (the smoke test loads fixtures and is not idempotent):

```bash
mysql ... < database/schema/mysql-schema.sql
mysql ... < database/mysql-seed.sql
mysql ... < database/mysql-smoke-test.sql   # expect 5 PASS, 0 errors
php verify/verify_sync.php                   # expect 16 passed, 0 failed
```

**Gate 2:** both pass with the stated counts. If either does not, stop and show me the
failure — do not work around it.

### 4. Wire authentication

Sanctum personal access tokens, not SPA cookie mode — a tablet that has been offline
for hours needs a bearer token it can reuse the instant the network returns.

- `POST /api/v1/auth/login` → token with abilities (`bedside` | `admin` | `billing`),
  12-hour TTL, bound to a `device_id`.
- `POST /api/v1/auth/pin-unlock` → 6-digit clinical PIN re-issues the token after the
  15-minute idle lock. Nurses re-authenticate ~20 times a shift; requiring a full
  password guarantees a shared logged-in account and destroys the audit trail.
- Every attempt writes to `login_events`. A token presented from a different `device_id`
  is revoked and logged.
- Rate limit login at `throttle:5,1`.

### 5. Wire authorisation and auditing

- Register `TreatmentSessionPolicy`; add `PatientPolicy` and `PrescriptionPolicy` on the
  same pattern — permissions are conditional on **record state**, not role alone.
- Register `AuditObserver` via the `Auditable` trait on `Patient`, `TreatmentSession`,
  `HdPrescription`, `MedicationAdministration`, serology, claims and invoices.
- Register `LogRecordAccess` as terminating middleware on every patient-scoped route.
- Enable MySQL `binlog_format=ROW`. The audit trail is written in PHP, so a direct SQL
  UPDATE bypasses it; the binlog is the independent record. Note this in the deploy docs.

**Gate 3:** write a Pest test proving that updating a patient through Eloquent creates
an `audit_logs` row with correct before/after JSON, and that a `GET` on a patient
creates a `record_access_logs` row.

### 6. Factories and the existing test suites

`tests/Feature/ClinicalInvariantsTest.php` and `SyncProtocolTest.php` are written but
need factories and two helpers: `staffWithRole()`, `claimAttributes()`,
`seedPhilHealthBenefit()`. Build `PatientFactory` and `TreatmentSessionFactory` with the
states the tests use (`inProgress`, `completed`, `locked`, `hbvReactive`).

**Gate 4:** `php artisan test` green on MySQL. Report the number of tests and assertions.

### 7. Health check and CI

- `GET /api/v1/health` — DB reachable, Redis reachable, migrations current, schema object
  counts, last successful backup timestamp.
- GitHub Actions: MySQL 8 service container, `migrate`, `test`, `pint --test`,
  `phpstan analyse`, `tsc --noEmit` for both client tsconfigs.

## Constraints

- Do not add a package that is not needed for Phase 0. No admin panel generator, no
  repository-pattern layer, no event sourcing.
- Do not scaffold clinical CRUD yet. Phase 1 is registry and scheduling.
- Every controller stays thin: FormRequest → Policy → Service → API Resource.
- Never return an Eloquent model from a controller.
- `public_id` (ULID) in every URL and payload; `id` never leaves the server.

## Stop and ask me if

- The MySQL version is below 8.0.16, or you find MariaDB instead of MySQL.
- Any invariant test fails and the fix would mean changing the schema or the test.
- You think a table, column or trigger in the baseline is wrong.
- A Phase 0 decision would constrain the offline sync design in §7.

## When you're done

Give me:

1. The object counts and test counts you actually observed.
2. Which of the four gates passed, in order.
3. Anything you changed in the bundle's code and why.
4. What you did **not** verify.
5. The first three things you would do in Phase 1.

Do not tell me it works. Show me the command output that proves it.
