# Dialysis Centre System — Laravel + MySQL + React PWA

Clinical software for a single in-centre haemodialysis unit in South-East Asia.

- **`technical-design.md`** — the design, and the reasoning behind it. Read first.
- **`CLAUDE.md`** — the rules for working in this repo: the invariants that must not
  be weakened, and twelve MySQL behaviours that will otherwise bite you.
- **[`docs/index.html`](docs/index.html)** — the documentation set: design, technical,
  user's, administrator's, deployment and troubleshooting guides, a product overview
  and a handover document. Opens from disk.

Current state: registry, scheduling, the treatment record end to end, the ops controls
that gate treatment, and billing — on a foundation of Sanctum auth, RBAC and auditing:

```
scheduled -> checked_in -> in_progress -> completed -> nurse signs -> physician signs -> LOCKED
                                                                              |
                                                                     amendment path only
```

Plus the ops controls that gate treatment: water compliance (invariant 9) refuses to
start the day's first session until total chlorine has been measured within limits,
and dialyzer reuse (invariant 8) refuses a unit below 80% TCV, past its reuse count,
or belonging to another patient.

And billing: `BenefitLedger` turns locked sessions into payer claims, refusing a
session that is already claimed (invariant 6), unsigned, not billable, another
patient's, or beyond the annual allotment.

**No case rate or session cap appears anywhere in the code.** Both are read from
effective-dated `benefit_programs` rows — the PhilHealth rate has gone
₱2,600 → ₱4,000 → ₱6,350 and the allotment 90 → 156, and the superseded rows stay in
the table so a historical claim re-prices against what was in force at the time.

Patient invoicing sits on top: a claim is what the payer is asked for, an invoice is
what is left, and a package with no-balance billing leaves nothing to charge.

**Adequacy is computed server-side.** Kt/V and URR are derived from the BUN samples
rather than stored as sent -- the tablet computes them too, for an immediate result at
the chair, and both implementations are pinned to the same worked example
(`packages/domain/src/index.test.ts` and `apps/api/tests/Feature/AdequacyTest.php`).
A machine-measured Kt/V (online clearance, ionic) is left alone: that one is a reading,
not a derivation.

Machines and consumables complete the ops side: a machine under repair cannot be put
on a patient, a failed safety test takes it out of service in the same call, and stock
issues first-expiry-first-out while refusing quarantined (recall-held) and expired lots.
Lot balances are moved only by the `stock_transactions_ai` trigger, so the count and
the movement history cannot drift apart.

The dashboard reads `monthly_quality_summaries`, rebuilt nightly from
`v_monthly_quality` (CLAUDE.md rule 7 — the view materialises every session before it
can answer anything). The view stays the definition of record, the report says how
stale the copy is, and `?live=1` goes straight to the view.

## Layout

```
apps/api/            Laravel 12 — the only thing that talks to MySQL
  app/Domain/{Core,Clinical,Ops,Billing,Sync}/  models, services, policies, enums
  app/Support/Auditing/                      Auditable trait + AuditObserver
  database/schema/mysql-schema.sql           BASELINE: 66 tables, 12 views, 6 triggers
  database/migrations/                       changes AFTER the baseline only
  routes/api.php                             the live API surface
  routes/api.php.bundle                      the originally intended surface, for reference
  verify/verify_sync.php                     standalone protocol verifier (no Laravel)
apps/bedside/        React 19 PWA — offline-first flow sheet (Dexie + outbox)
  src/components/      board, flow sheet, observation + event forms, sync bar, sign-in
apps/console/        React 19 SPA — admin, billing, reports (online only)
  src/views/           board, patients, patient chart, session, controls
packages/domain/     Kt/V, URR, UF rate, IDWG — pure functions, shared, cited
packages/api-client/ typed fetch + Zod schemas mirroring the API Resources
packages/ui/         shared React primitives — the confirmation dialog, and the
                     sweep that keeps window.confirm out of the clients
```

## Prerequisites

PHP 8.3+ with `pdo_mysql`, Composer, MySQL 8.0.16+, Node 22+.

Redis is **not** required yet. Queue, cache and session run on `sync`/`file` drivers
until a Redis server exists; `GET /api/v1/health` reports the Redis check as
`not_configured` rather than pretending it passed.

## Run it

```bash
# ---- API ----
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
# .env defaults to 127.0.0.1:3308. Point DB_* at your own server if it differs.

mysql -h 127.0.0.1 -P 3308 -u root -e "
  CREATE DATABASE dialysis      CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE DATABASE dialysis_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

php artisan migrate     # loads database/schema/mysql-schema.sql as the baseline
php artisan db:seed     # reference data from database/mysql-seed.sql
php artisan serve

php artisan test        # Pest, against MySQL — never SQLite
./vendor/bin/pint
./vendor/bin/phpstan analyse
```

```bash
# ---- Database invariants, straight through the mysql client ----
# Fresh database only: the smoke test loads fixtures and is not idempotent.
cd apps/api
mysql -h 127.0.0.1 -P 3308 -u root < database/schema/mysql-schema.sql
mysql -h 127.0.0.1 -P 3308 -u root < database/mysql-seed.sql
mysql -h 127.0.0.1 -P 3308 -u root < database/mysql-smoke-test.sql   # expect 5 PASS
php verify/verify_sync.php                                            # expect 16 passed
```

```bash
# ---- Clients ----
npm install                 # workspace root
npm run typecheck           # tsc --noEmit across all five workspaces
npm run dev -w @dialysis/bedside    # http://localhost:5173

# The bedside app proxies /api to http://127.0.0.1:8000 by default. If something
# else already holds port 8000, requests reach the wrong application and the
# failure looks like a bug in this one -- point it somewhere else instead:
#   DIALYSIS_API_URL=http://127.0.0.1:8010 npm run dev -w @dialysis/bedside
npm run dev -w @dialysis/console    # http://localhost:5174
#   DIALYSIS_API_URL also applies here.
npm test                    # the shared Kt/V and URR maths
```

## Deployment notes

**Enable `binlog_format=ROW`.** The audit trail is written in PHP by `AuditObserver`,
so a direct SQL `UPDATE` bypasses it entirely. The binary log is the independent
record, and it is the only one a DBA cannot quietly edit through the application.
Grant no human a write-capable MySQL account; DBA access should be break-glass and
logged outside the application.

**The detective controls have a reader — make sure something watches it.**
Invariants 1, 8 and 9 (cohort violations, dialyzer TCV/reuse limits, water chlorine)
are now refused at the point of action, so those views are no longer the only line of
defence — they are the second one. A row still turns up in `v_cohort_violation`,
`v_dialyzer_status` or `v_water_exceptions` when something was written by a console
command, a stray script, or a recorded infection-control override. `dialysis:check-controls`
reads all three and exits non-zero when anything is outstanding; it is scheduled
twice daily at 06:00 and 18:00, so a violation found at 06:00 can still be acted on
before the morning shift is seated.

That only works if Laravel's scheduler is actually running and its failures reach a
person:

```bash
# on the server, once
* * * * * cd /path/to/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

A detective control nobody reads is not a control.

## Documentation

Nine documents in `docs/`, starting at [docs/index.html](docs/index.html). They open from
disk with no server and print on A4.

| Ref | Document | For |
|---|---|---|
| DS·1 | [Design specification](docs/design-spec.html) | What it is for, who may do what, the open questions |
| TS·1 | [Technical specification](docs/technical-spec.html) | How it is built, the order of checks, known defects |
| UG·1 | [User's guide — The Dialysis Day](docs/users-guide.html) | Ward staff |
| AG·1 | [Administrator's guide](docs/admin-guide.html) | Accounts, settings, jobs, audit, backups |
| DG·1 | [Deployment guide](docs/deployment-guide.html) | Install, upgrade, roll back, move hosts |
| TG·1 | [Troubleshooting guide](docs/troubleshooting.html) | Symptom, cause, action |
| MK·1 | [Product overview](docs/marketing.html) | Deciding whether it suits a unit |
| HO·1 | [Handover](docs/handover.html) | Whoever maintains it next |

The pages are built: edit `docs/src/` (or `deploy/DEPLOYMENT.md` for the deployment
guide), then run `php docs/build.php`, which rebuilds and validates the set. Do not edit
the HTML. `docs/faq.html` (*Why It Stopped You*, 20 August 2026) predates the screens and
is kept as written; the troubleshooting guide covers the same messages today.

## Deploying

`deploy/` holds everything needed to put this on shared hosting with no shell:
a browser-run [pre-flight check](deploy/preflight.php), an [APP_KEY generator](deploy/generate-key.php),
a [production .env template](deploy/.env.production.example), the SQL files that
build the database from nothing, and a [step-by-step guide](deploy/DEPLOYMENT.md).

**Step 0 of that guide can end the deployment.** The schema needs MySQL 8.0.16+ —
earlier versions silently ignore CHECK constraints — and MariaDB has no `LATERAL`
support at any version, so `v_monthly_quality` cannot be created on it. Check the host
before doing anything else.

## Verified on

PHP 8.4.24 · MySQL 8.4.9 · Laravel 12.67 · TypeScript 7 strict mode.

The schema itself was validated by its authors on MySQL 8.0.46; CI runs 8.0 to keep
that the line of record.

## Licence

Released under the MIT licence — see [LICENSE](LICENSE).
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) covers the Laravel skeleton
and Sanctum in `apps/api/`, and the Tailwind CSS in Laravel's stock welcome
page. The licence disclaims every warranty: this is clinical software, and it
has had no independent audit, security review or regulatory certification.
