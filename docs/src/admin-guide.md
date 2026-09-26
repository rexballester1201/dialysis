## What is yours, and what runs by itself {#responsibilities}

The system enforces its clinical rules on its own: nobody has to remember to refuse an HBV patient a clean chair. What it cannot do for itself is the work around it — creating accounts, keeping settings true to the room, making sure the scheduled checks run and reach a person, and keeping backups. That is the administrator's job, and this guide covers all of it.

| Yours | The system's |
|---|---|
| Creating, renaming, resetting and retiring staff accounts | Refusing what a role may not do; locking an account after five failed sign-ins |
| Granting roles | Recording every role change in the audit log |
| Keeping chair cohorts, high-alert flags, the timezone and the benefit rate true | Applying them to every check-in, start, dose and claim |
| Making sure cron runs, and that the twice-daily report reaches a person | Reading the three detective controls and failing loudly when anything is outstanding |
| Backups, and proving they restore | Keeping every record — nothing clinical is ever deleted |
| Reading the audit trail when something is questioned | Writing it |

The console's **Settings** screen is open only to accounts with the `admin` role. Everything else in this guide is SQL in phpMyAdmin, a command on your own computer, or a scheduled job — each shown where it is needed, and gathered in [§9](#commands).

## People and roles {#people}

### One account, one person

An account is a person, never a role. The name on it is printed on everything it signs, a countersignature by "Attending Nephrologist" attributes a legal attestation to nobody, and the high-alert witness check means nothing if two nurses share a sign-in. Never pass an account on: give the new person their own.

### Creating accounts

There is no screen that creates a staff member, sets a password or sets a PIN. Accounts are SQL, written on your own computer by `deploy/accounts/make-accounts.php` from the staff list in `deploy/accounts/accounts.csv`. The script needs PHP 8.1 or newer and never runs on the server.

1. Add the person's row to `accounts.csv`: employee number, names, email if any, licence number where it applies, and their roles.
2. Generate only that row:

   ```bash
   php deploy/accounts/make-accounts.php RN-002
   ```

   It writes `deploy/accounts/out/add-accounts-<time>.sql` and a credentials sheet beside it, as `.txt` and as a printable `.html` with one cut-out slip per person. It refuses a malformed row and names the problem.
3. Import the `.sql` file in phpMyAdmin (Import tab). It creates an account only if no account already has that employee number or email, and never changes an existing one.
4. Hand each person their own slip, in person. Then delete the sheets. They are the most sensitive files in the deployment: never upload, email or copy them to a shared folder.

Staff sign in with their employee number, or their email if one is set. The bedside roles also get a six-digit PIN, which works on a tablet only after that person has signed in there once with their password.

### Roles

**Settings → Staff and roles** grants and removes roles on existing accounts; every change is written to the audit log. What each role may and may not do is in [DS·1 §3](design-spec.html#roles). Three things to hold on to:

- **A unit needs at least one `nephrologist` account.** Without one, no treatment record can be countersigned, so none locks, and nothing can be claimed.
- **Give no account both `nurse` and `nephrologist`.** The system separates the two signatures by role, not by person.
- **The system will not let the last administrator remove their own `admin` role**, because there would be no way back in short of the database.

`dietitian` and `readonly` exist in the list, but nothing checks them yet: an account holding only those can sign in and read.

### Renaming an account

Only before it has signed anything — records point at the account, so renaming it later rewrites the name on everything it already signed. In phpMyAdmin's SQL tab:

```sql
UPDATE staff
   SET first_name = 'Maria', last_name = 'Reyes', licence_no = 'PRC-0123456'
 WHERE employee_no = 'NEP-001';
```

### Resetting a password

Nobody can change their own password in the app yet. The administrator does it:

```bash
php deploy/accounts/make-accounts.php --reset RN-001
```

That writes `deploy/accounts/out/reset-<time>.sql` and a new sheet: a new password, a new PIN if the account had one, the lockout cleared, and every device the account was signed in on signed out — so whoever may have learned the old password is out too. Import it; phpMyAdmin should report one row affected by each `UPDATE`. Zero means no active account has that employee number.

### A locked-out account

Five failed sign-ins lock an account for 15 minutes, and the lock clears itself. To clear it sooner:

```sql
UPDATE staff SET failed_logins = 0, locked_until = NULL WHERE employee_no = 'RN-001';
```

### Someone leaves

Deactivate the account and sign it out everywhere. Never delete it — the records it signed point at it.

```sql
UPDATE staff SET is_active = 0 WHERE employee_no = 'RN-001';

DELETE t FROM personal_access_tokens t
  JOIN staff s ON s.id = t.tokenable_id
 WHERE s.employee_no = 'RN-001' AND t.tokenable_type LIKE '%Staff';
```

An inactive account cannot sign in or unlock with its PIN. Deleting its tokens ends the sessions already open on tablets and desks, which the deactivation alone would not.

## Settings {#settings}

Each setting on the Settings screen explains its own consequence beside it. These are the ones that change what the system allows.

| Setting | What it changes | Check it against |
|---|---|---|
| **Unit timezone** | Where the unit's day begins and ends: which day a water check clears, the date the board and the tablets open on, when stock passes its expiry. Stored times do not move; the day they fall on does | The wall clock |
| **Chair cohorts** | Which infection-control cohorts may sit in each chair. **A chair with no cohorts ticked is unrestricted, not unusable** — so the screen asks for a written reason before it clears the last one | The actual rooms |
| **High-alert medications** | Which drugs need a witness who is not the giver. Unflagging one removes that second check entirely | Your medication policy |
| **Staff roles** | What each person can reach ([§2](#people)) | Who does what this month |
| **Facility details** | Name, legal name, licence and accreditation numbers, country, contact details, the number of stations. The currency is display only; it converts nothing | Your licence |
| **Benefit programmes** | Shown, with the one in force. There is no form to add one — see below | The current payer circular |

### Changing the benefit rate {#benefit-rate}

The case rate and the session allotment are dated rows, read at the moment a claim is generated for the date of the treatment. A rate is **added, never edited**: adding one closes the one in force on the new start date and keeps it, so past claims still price against what applied then. Each version needs its own code.

The screen has no form for this yet, so it is an API call made by an administrator. From a computer with `curl`, sign in to get a token (use a made-up device name and the same one on both calls):

```bash
curl -s https://your-domain/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "ADM-001", "password": "…", "device_id": "admin-rate-change"}'
```

Type the password where the `…` is, and clear it from the shell's history afterwards.

Then add the programme with the `token` from the answer:

```bash
curl -s https://your-domain/api/v1/settings/benefit-programs \
  -H "Authorization: Bearer <token>" -H "X-Device-Id: admin-rate-change" \
  -H "Content-Type: application/json" \
  -d '{"payer_id": 1, "code": "PH_HD_2027", "name": "PhilHealth Haemodialysis Package (2027)",
       "modality": "hd", "case_rate": "7000.00", "sessions_per_period": 156,
       "period_kind": "calendar_year", "currency": "PHP", "no_balance_billing": true,
       "effective_from": "2027-01-01", "circular_ref": "PhilHealth Circular …"}'
```

The figures above only show the shape; take the real ones from the circular. `payer_id` is the payer's row id (`SELECT id, code FROM payers;`). The answer lists every programme, the old one now closed. A code already used, a start date not after the current programme's, or a `period_kind` other than `calendar_year` or `month` is refused with a sentence saying why. The change is written to the audit log. Afterwards, sign the token out with `POST /api/v1/auth/logout` using the same two headers.

### What cannot be changed on the screen, and why {#read-only}

| Thing | How it changes today | Why it is not on the screen |
|---|---|---|
| Shifts | SQL on `shifts` | Every standing pattern, board and session points at a shift; changing one's times rewrites the unit's day. Nobody has designed a safe change |
| Adding or retiring a chair | SQL on `stations` | Not built. An existing chair's cohorts are on the screen |
| Registering a machine | SQL on `machines` | Not built; the API records maintenance, disinfection and status for machines that exist |
| Water systems | SQL on `water_systems` | Not built. The seed adds one, *RO Unit A*; rename it to match yours. A unit with no active water system cannot record a check, so cannot start a treatment |
| Event codes, lab tests and reference ranges, the medication list | SQL on `event_refs`, `lab_test_refs`, `medication_refs` | Seeded reference data. Only the high-alert flag is on the screen. An event code must be on the list or charting it is refused |
| Payers | SQL on `payers` | Rarely changes; the seed carries PhilHealth, self-pay and a generic HMO |
| Serology, patient status, demographics, prescriptions, standing patterns | API only ([DS·1 §7](design-spec.html#api-only)) | Screens not built yet |

Any SQL change skips the services — so it skips their checks and the audit log. Write down what you changed, when and why, and run `dialysis:check-controls` (or open the Controls screen) afterwards.

## What runs on a timer {#jobs}

Two commands, wired into Laravel's scheduler. The host needs one cron entry that runs the scheduler every minute:

```bash
cd /home/cpuser/dialysis-api && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Use your own path and the PHP binary cPanel's Cron Jobs page shows — often a version-specific one such as `/usr/local/bin/ea-php84`.

| Command | When (UTC) | In Manila | What it does |
|---|---|---|---|
| `dialysis:check-controls` | 06:00 and 18:00 | 14:00 and 02:00 | Reads the three detective views — cohort violations, dialyzer exceptions, water exceptions — prints what it finds, logs a warning, and exits non-zero while anything is outstanding |
| `dialysis:summarise-quality` | 02:30 | 10:30 | Rebuilds this month's and last month's rows in `monthly_quality_summaries` from `v_monthly_quality` — last month too, because a late signature or an amendment can change a month after it ends |

:::caution The times are UTC, not the unit's
The application clock is fixed to UTC, so "06:00" is 06:00 UTC — 14:00 in Manila. The code's intention was a check before the morning shift is seated; as built it runs mid-afternoon and in the early hours. Until the schedule is moved to the unit's timezone ([TS·1 §5](technical-spec.html#known-defects)), treat the Controls screen as the morning check: someone opens it before the first patient is seated.
:::

**Make the failure reach a person.** A non-zero exit from `check-controls` means violations are outstanding, not that the job broke — and the scheduler's output goes nowhere by default. The warning lands in the Laravel log (`storage/logs/laravel-YYYY-MM-DD.log`, kept 14 days) as *Detective controls found violations*. On cPanel, the simplest route to a person is to set the Cron Jobs page's email address and add a second entry that runs `php artisan dialysis:check-controls` directly, at a time that suits the unit and without the `>> /dev/null`. Cron mails whatever the command prints: a one-line all-clear, or tables of what is outstanding.

**What "outstanding" means.** Each list has its own window. Cohort rows are sessions from today onward, so yesterday's drop off. Dialyzer rows stay until the unit is condemned. Water rows are every breach of the last 90 days, corrected or not — so after one failed chlorine test, `check-controls` exits non-zero at every run for 90 days. Look for new rows, not for a zero.

**Confirm they ran.** The summariser's last run is `SELECT MAX(summarised_at) FROM monthly_quality_summaries;` (UTC). The check-controls warning appears in the log only when something is outstanding; a quiet log means either nothing was found or the scheduler is not running — so check the summariser's timestamp to tell the two apart.

Nothing else runs unattended. There is no queue worker, no backup job and no email.

## Imports {#imports}

There is no import feature. Patients are registered one at a time on the Patients screen, and lab results are filed on the patient chart.

If you ever load data with SQL — a migration from a previous system, a batch of historical results — know what it skips. It bypasses every service, so none of the refusals in [DS·1 §6](design-spec.html#workflow) run and nothing reaches the audit log. Only the database backstops apply: the six triggers, the keys and CHECK constraints, and the three detective views. So:

1. Load into a copy of the database first, never straight into the live one.
2. Keep derived columns empty and let the system compute them. Never load a Kt/V, URR or invoice total calculated elsewhere.
3. Never insert into `stock_transactions` against lots whose balances you have already loaded: the trigger adds every movement to its lot again.
4. Afterwards, run `dialysis:check-controls` (or open the Controls screen) and resolve everything it lists before going live.
5. Record what was loaded, from where, by whom and when — the audit log will not.

## The audit trail {#audit}

Five places, all written by the system and none editable through it.

| Record | Holds | Written when |
|---|---|---|
| `audit_logs` | Actor, action, the record, the columns changed, before and after as JSON, address, browser | Any change made through the application to a patient, treatment session, prescription, medication dose, serology result, lab order or result, invoice or dialyzer unit; every settings change; every claim release on a void. Claims themselves are written by the billing ledger directly and do not appear here |
| `record_access_logs` | Who opened which patient's record, through which route, from where | Every successful request that names a patient or a session |
| `login_events` | Every sign-in and PIN attempt, success or failure, with the reason | Every attempt, and every token revoked for a device mismatch |
| `session_notes` | *AMENDMENT* notes with the reason and before-and-after, and *INFECTION CONTROL OVERRIDE* notes with the breach and reason | Every amendment and override |
| `claim_status_histories`, `sync_batches` | Every claim status with who and why — the claim's own trail; every tablet upload with its verdicts | Every claim move and remittance; every sync |

Times are UTC, except a few columns MySQL fills by default, which use the database server's own zone ([TG·1 §11](troubleshooting.html#times)). Useful questions, as SQL:

```sql
-- Who changed this patient's record, and what did they change?
SELECT a.occurred_at, a.actor_name, a.action, a.changed_cols, a.before_data, a.after_data
  FROM audit_logs a JOIN patients p ON p.id = a.auditable_id
 WHERE a.auditable_type LIKE '%Patient' AND p.mrn = 'MRN-000123'
 ORDER BY a.occurred_at;

-- Who opened this patient's chart?
SELECT r.accessed_at, s.employee_no, s.full_name, r.context, r.ip_address
  FROM record_access_logs r
  JOIN patients p ON p.id = r.patient_id
  LEFT JOIN staff s ON s.id = r.actor_id
 WHERE p.mrn = 'MRN-000123'
 ORDER BY r.accessed_at DESC;

-- Failed sign-ins in the last seven days
SELECT occurred_at, username, failure_reason, ip_address
  FROM login_events
 WHERE success = 0 AND occurred_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY
 ORDER BY occurred_at DESC;

-- Amendments and infection-control overrides
SELECT n.created_at, ts.public_id AS session, p.mrn, LEFT(n.body, 240) AS note
  FROM session_notes n
  JOIN treatment_sessions ts ON ts.id = n.session_id
  JOIN patients p ON p.id = ts.patient_id
 WHERE n.body LIKE 'AMENDMENT%' OR n.body LIKE 'INFECTION CONTROL OVERRIDE%'
 ORDER BY n.created_at DESC;

-- Settings changes, and claims released by a void
SELECT occurred_at, actor_name, action, auditable_type, before_data, after_data
  FROM audit_logs
 WHERE auditable_type LIKE 'table:%'
 ORDER BY occurred_at DESC;
```

The audit log is written by the application, so **a direct SQL change is invisible to it**. That is why no person should hold a database account that can write, and why a host that offers MySQL binary logging (`binlog_format=ROW`) should have it on — the binary log records what the database did, whoever did it. Most shared hosts do not offer it.

## Backups are yours {#backups}

The system makes no backups. cPanel's own are usually nightly and overwrite themselves, and these records are kept for 10 to 15 years. Arrange your own before the first real patient is charted.

**What to keep, together:** the database; the `.env` file (it holds `APP_KEY` and the database password); and `storage/` if anything has been uploaded there. Nothing is encrypted with `APP_KEY` today, so a lost key can be replaced without signing anyone out; keep it anyway, because anything added later that encrypts with it would depend on it.

**How, on cPanel:** Backup → *Download a MySQL Database Backup* gives a compressed dump of the database. phpMyAdmin's Export does the same. Whichever you use, the dump must include the views and the six triggers — check that a restored copy counts 69 tables, 12 views and 6 triggers.

**How often:** daily at least, kept off the server, encrypted before it leaves, with monthly copies kept for the retention period.

**Prove it restores:** once a month, import the latest backup into a scratch database, count the objects, count the rows in `patients`, `treatment_sessions` and `claims` against the live one, then drop the scratch database. A backup that has never been restored is a hope, not a backup.

**Tell the health check.** `GET /api/v1/health` reports the backup as `not_configured` until `BACKUP_STATUS_PATH` in `.env` names a file. Once it does, it reports that file's modification time as the last successful backup — so whatever makes your backup must touch that file only after the backup succeeded and was checked. Nothing in the application writes it.

## The routine {#routine}

| When | Do | Why |
|---|---|---|
| Every morning | Open **Controls** before the first patient is seated and look for anything new | The scheduled check runs at 14:00 and 02:00 Manila time, not before the shift. Water breaches stay listed for 90 days, so the list is not always empty |
| Every morning | Confirm the **Water** screen shows the unit cleared before the first start — the technician records it, you make sure it happened | No treatment can start without it |
| Every day | Confirm last night's backup exists | A missed night is found the day you need it |
| Every week | Read the failed sign-ins and lockouts ([§6](#audit)) | Repeated failures on one account are someone guessing |
| Every week | Read the week's overrides and amendments | Each is a clinical decision somebody should know about |
| Every week | Search the log for *sync operation failed* and *Detective controls found violations* | A rejected operation is charting that did not arrive |
| Every week | Confirm `MAX(summarised_at)` is within the last day | Tells you the scheduler is running |
| Every month | Restore the latest backup into a scratch database and compare counts | Proves the backup |
| Every month | Review **Settings → Staff and roles** against the roster; deactivate leavers | Access follows the job, not the history |
| Every month | Read the chart-access log for unexpected access | The question a privacy regulator asks |
| Every month | Check the benefit programme in force against the current circular | Rates change; claims price at whatever is on file |
| Every year | Re-check the clinical thresholds against the unit's SOPs: total chlorine 0.1 ppm, Kt/V ≥ 1.2, URR ≥ 65%, dialyzer volume floor 80%, UF-rate concern 13 mL/kg/h | They are cited, not chosen by the unit |
| Every year | Rotate the database password (update `.env` in the same minute) | The one credential with write access |
| Every year | In the first working week of January, check the Claims screen for December sessions | Calendar-year allotments restart on 1 January |
| Every year | Confirm the host still supports the PHP version in use, and that TLS renews | Hosts retire old PHP versions |

## Commands {#commands}

### On the server, if you have a shell

| Command | Does |
|---|---|
| `php artisan dialysis:check-controls` | Reads the three detective controls. Exit 0: nothing outstanding. Exit 1: violations, printed as tables |
| `php artisan dialysis:check-controls --json` | The same, as JSON for a monitoring agent |
| `php artisan dialysis:summarise-quality` | Rebuilds this month and last in the quality rollup |
| `php artisan dialysis:summarise-quality --month=2026-08-01` | Rebuilds the month containing that date |
| `php artisan schedule:list` | Shows the schedule and the next run times |
| `php artisan schedule:run` | Runs whatever is due now — what cron calls every minute |
| `php artisan route:list` | Every route with its middleware |
| `php artisan migrate` | On an empty database, loads the baseline schema, then the migrations |
| `php artisan db:seed` | Loads the reference data; safe to re-run |

### Without a shell

| Tool | Does |
|---|---|
| cron | Runs `php artisan schedule:run` every minute ([§4](#jobs)) |
| `make-accounts.php` (your computer) | `--force` rewrites the starter set; `RN-002` adds that row; `--reset RN-001` resets a password; `--render-sheet <file.txt>` reprints a sheet as HTML |
| phpMyAdmin | The SQL in [§2](#people), [§3](#read-only) and [§6](#audit); imports of account, reset and backup files |
| `curl` | The API calls for work with no screen, such as [adding a benefit programme](#benefit-rate) |
| `GET /api/v1/health` | Database, migrations, schema counts, Redis and backup status, as JSON, with no sign-in |
