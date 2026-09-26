## Read these first, in this order {#reading-order}

| # | Read | Why | Time |
|---|---|---|---|
| 1 | This document | What you are taking on, and what will bite | 20 minutes |
| 2 | `CLAUDE.md` at the repository root | The rules for changing the code: the invariant table, twelve MySQL behaviours that were each hit once, the known gaps, the definition of done | An hour |
| 3 | [TS·1](technical-spec.html) | How it is built, the order of checks, the known defects | An hour |
| 4 | [DS·1](design-spec.html) | What it is for, who may do what, and the open questions | 45 minutes |
| 5 | `technical-design.md`, sections 3 and 7 | Why MySQL shaped the schema the way it did, and the offline sync protocol | 45 minutes |
| 6 | The header comment of `apps/api/database/schema/mysql-schema.sql` | The non-obvious column choices, in the schema's own words | 20 minutes |
| 7 | [AG·1](admin-guide.html) and [DG·1](deployment-guide.html) | Before you touch the live installation | An hour |

Then the code, following one treatment through it: `apps/api/routes/api.php` → `SessionService` → `SessionLockService` → `FlowSheetService` → `BenefitLedger` → `SyncBatchProcessor` and `UpsertSessionHandler` → `WaterComplianceService` and `FacilityCalendar` → `AppServiceProvider`. The tests named in the invariant table show each rule being tried and refused.

## The map {#map}

| Where | What | Touch it when |
|---|---|---|
| `apps/api` | The Laravel API — the only thing that talks to MySQL | Any rule, any data |
| `apps/api/database/schema/mysql-schema.sql` | The baseline schema | Never. Changes are migrations |
| `apps/api/database/migrations` | The three changes since the baseline | Every schema change |
| `apps/api/tests/Feature` | 18 suites, named for what they protect | Every behaviour change |
| `apps/api/verify/verify_sync.php` | The standalone sync-protocol check | Any sync change |
| `apps/bedside` | The tablet app | Charting, the lifecycle at the chair, offline |
| `apps/console` | The desk app | Everything else on a screen |
| `packages/domain` | The clinical maths, twinned with `Adequacy.php` | Only together with its twin |
| `packages/api-client` | Typed calls and schemas for every resource | Whenever a resource changes shape |
| `packages/ui` | The confirmation dialog and its sweep | Any confirmation |
| `deploy/` | The no-shell deployment bundle: SQL files, pre-flight, key generator, account tool | Every release that changes the schema; every new account |
| `deploy/accounts/CREDENTIALS.*` | Live starter passwords and PINs, if they still exist | Never share; destroy once handed out |
| `docs/` | This set and its builder | Whenever described behaviour changes |
| `.github/workflows/ci.yml` | The CI gates — never yet run | When the repository exists |
| `CLAUDE.md` | The working rules | When a rule or a known gap changes |
| `AGENTS.md` | A stale copy of `CLAUDE.md` from 23 August 2026 | Decide: regenerate it from `CLAUDE.md`, or delete it. Until then, do not follow it |
| `README.md`, `technical-design.md`, `INITIAL-PROMPT.md` | The front door, the original design, the original brief | Rarely |

## Four rules not to break {#rules}

| Rule | What breaks if you do |
|---|---|
| **Never weaken an invariant, its database backstop or its test to make something pass.** If a test is wrong, say why before you change it | The refusal that keeps a hepatitis-B patient off a clean machine, a second bill off one treatment, or a quiet edit off a signed record stops existing — and the one suite that would have told you turns green |
| **Never store a derived value a client sent.** The server computes Kt/V, URR, weight gain, duration, invoice totals and stock balances from their inputs | A chart holds a Kt/V that disagrees with its own blood results and is believed; a shelf count drifts from its movements; an invoice line that bcmath read as zero is priced at nothing |
| **Change the database only by a migration with a SQL twin in `deploy/sql`, and never edit the baseline** | Production has no shell, so `php artisan migrate` never runs there. A migration without its SQL twin never reaches the live database, and the code and the schema it expects drift apart without an error until the first query that needs the change |
| **Never delete a patient, a treatment session or an audit row, and never `SET @allow_amendment = 1` outside `SessionLockService::amend()`** | The records are kept 10 to 15 years and are the unit's legal account of care. A deleted row or an unrecorded edit cannot be recovered, and the audit trail stops meaning anything |

## What runs without you {#unattended}

| What | When | If it stops |
|---|---|---|
| cron → `php artisan schedule:run` | Every minute | Nothing below runs |
| `dialysis:check-controls` | 06:00 and 18:00 **UTC** (14:00 and 02:00 Manila) | Breaches that got past the checks — imports, direct edits, overrides — go unread |
| `dialysis:summarise-quality` | 02:30 UTC | The quality report goes stale; it reports how stale |
| Log rotation | Daily; 14 files kept | A disk quota fills |
| Tablet sync | Every 30 seconds while the app is open, and when the network returns | Charting waits in the outbox; nothing is lost |

**Not running, and yours to arrange:** backups (none exist in the application), a queue worker (nothing queues yet), email (nothing sends), and CI (never run).

## Your first week {#week-one}

| Task | How | Done |
|---|---|---|
| Put the code under version control | `git init`, a first commit — nothing is versioned today, and the only copy may be one machine | |
| Run every check on your own machine | [TS·1 §10](technical-spec.html#tests): expect 282 Pest tests, 9 Vitest tests, five clean workspaces, smoke 5/5, verify 16/16 | |
| Run CI once and fix what it finds | Push to a repository with Actions enabled; it has never run | |
| Take over every access | cPanel, phpMyAdmin, the domain and DNS, TLS, the database password, a copy of `APP_KEY` — in person, then rotate the database password | |
| Prove a backup restores | [AG·1 §7](admin-guide.html#backups) | |
| Confirm the scheduler runs | `SELECT MAX(summarised_at) FROM monthly_quality_summaries;` is within a day | |
| Understand every row on the Controls screen | [TG·1 §12](troubleshooting.html#jobs) | |
| Agree owners for the known defects and open questions | [TS·1 §5](technical-spec.html#known-defects), [DS·1 §9](design-spec.html#open-questions) | |
| Confirm the timezone and the clinical thresholds with the clinical lead | [AG·1 §3](admin-guide.html#settings) and [§8](admin-guide.html#routine) | |
| Rebuild this documentation | `php docs/build.php` exits 0 | |

## Changing it safely {#change}

1. **Find the rule your change touches.** The invariant table in [TS·1 §5](technical-spec.html#engine) names the service, the backstop and the test for each.
2. **Branch.** Once the repository exists, never work on the main line.
3. **Change the test first** in the suite that owns the rule. A new clinical or billing rule gets a case in `ClinicalInvariantsTest`, `OpsControlsTest`, `BillingTest`, `SessionLifecycleTest`, `AdequacyTest` or `SyncProtocolTest`.
4. **Schema change:** a migration, plus the same change as guarded, re-runnable SQL appended to `deploy/sql` in the pattern of `02-post-baseline.sql`; if you add tables, views or triggers, update the counts in `HealthController`, `deploy/preflight.php` and `DEPLOYMENT.md`. A new model goes into `ModelIntegrityTest`, and any generated column into its `$generated`.
5. **New sync operation type:** register the handler in `AppServiceProvider`, give its table a `client_uuid` with a unique index, add a `SyncProtocolTest` case, and keep `verify_sync.php` at 16 passed.
6. **Clinical constant:** cite its source in a comment. If it touches adequacy, change both twins and both tests.
7. **Run the definition of done** ([DS·1 §10](design-spec.html#done)) and say in your summary what you did not verify.
8. **Update the documentation sources** and rebuild the set.
9. **Deploy by the upgrade procedure** in [DG·1](deployment-guide.html), keeping the previous release ready to switch back to.

## What will surprise you {#surprises}

- **It is not a git repository**, and the CI workflow has never run.
- **One missing header signs a nurse out.** A request without the right `X-Device-Id` revokes the token rather than refusing it. `sync.ts::authHeaders` is the one place that builds the headers; add calls through it.
- **The scheduler runs on UTC**, and `APP_TIMEZONE` in `.env` is not read — `config/app.php` fixes UTC.
- **After one failed chlorine test, `check-controls` fails at every run for 90 days**, because the water list keeps every breach of the last 90 days.
- **Claims never reach `audit_logs`.** The ledger writes them with the query builder; their trail is `claim_status_histories`.
- **The baseline, the seed and the smoke test all begin with `USE dialysis;`.** Pipe them into another database unedited and they write into `dialysis` anyway. The application strips it; the `mysql` client does not.
- **The development database is its own MySQL 8.4 instance on port 3308.** The machine's other MySQL on 3306 holds unrelated databases.
- **Token abilities are recorded and never checked.** Authorisation is the role-based policies.
- **The two signatures are separated by role, not person**, and five roles can set a dry weight.
- **The seeded benefit programmes leave a gap** from 1 July to 8 October 2024 (the ₱4,000 rate was never seeded), so a session in that window cannot be claimed until a programme covering it is added.
- **Stock write-offs and quarantine exist in `StockService` with no endpoint**, and `missed`, `cancelled` and `refused` exist as session statuses that no endpoint sets.
- **Disinfecting a machine clears a common refusal, and has no screen.** Nurses meet *"That machine last treated a hbv patient…"* at the chair; the fix is an API call.
- **`apps/api/README.md` is Laravel's stock README.** The project's own is the root `README.md`, and its licence, MIT, is in the root `LICENSE`.

## Known limits {#limits}

The complete lists are [DS·1 §2](design-spec.html#not-built) (what is not built), [TS·1 §5](technical-spec.html#known-defects) (defects found while documenting) and the *Known gaps* section of `CLAUDE.md`. The five that matter most on a working day:

1. **No staff management in the app** — every account, reset and leaver is SQL.
2. **Work with no screen** — serology, patient status and details, prescriptions, standing patterns, doses, amendments, machines, dialyzers, stock and two reports are API-only.
3. **The tablet cannot start from cold without a network**, and there is no automated offline test — the one test the design says catches real bugs.
4. **The safety sweep runs at the wrong time of day** for a unit outside UTC.
5. **Nothing has been independently reviewed** — not the code, not the security, not the clinical content.

## Handover checklist {#checklist}

| Item | Why it matters | Done |
|---|---|---|
| cPanel login transferred, and its password changed | Whoever held it can still change everything | |
| Domain registrar and DNS access transferred | An expired domain takes the unit offline | |
| TLS renewal — who gets the warning when it fails | The tablets will not work over plain HTTP | |
| Database password rotated after handover, `.env` updated the same minute | The one credential that can write the database | |
| A copy of `APP_KEY` in a password manager | Nothing is encrypted with it today, but anything added later that encrypts would depend on it | |
| Backup location, schedule and the date of the last successful restore test | The only way back from the worst case | |
| Cron entry confirmed running | The scheduled checks depend on it | |
| The people holding `admin`, by name | Someone must be able to grant roles | |
| Credentials sheets confirmed destroyed, none on the server or in email | They hold live passwords and PINs | |
| The code copied off the previous owner's machine and under version control | Today there may be one copy | |
| The clinical lead's sign-off on the thresholds, timezone, chair cohorts, high-alert list and benefit rate | They are configuration, and the unit owns them | |
| Owners named for each known defect and open question | Otherwise they are rediscovered the expensive way | |
| This documentation rebuilt and opened on the new owner's machine | Proves the builder works in the new owner's hands | |
