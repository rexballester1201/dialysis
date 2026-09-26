# Deploying to cPanel without a shell

For a single dialysis unit on shared hosting, where the only tools are the
cPanel control panel, File Manager and phpMyAdmin.

Work through the steps in order. **Step 0 can end the deployment**, so do it
before spending time on anything else.

---

## What is in this folder

| File | Purpose |
|---|---|
| `preflight.php` | Browser-run host check. Answers "will this host run it?" without a shell. |
| `generate-key.php` | Produces an `APP_KEY`, since `php artisan key:generate` needs a shell. |
| `.env.production.example` | Production configuration template. |
| `sql/01-schema.sql` | The baseline: 66 tables, 12 views, 6 triggers. |
| `sql/02-post-baseline.sql` | The two tables and the one schema fix added after the baseline, plus the migration ledger. |
| `sql/03-seed.sql` | Reference data: roles, shifts, stations, event and medication lists. |
| `sql/04-accounts.sql` | The first staff accounts, with bcrypt-hashed passwords. Generated in step 8: each installation makes its own, so the repository has none. |
| `accounts/accounts.csv` | The staff list `04` is generated from. Real names go here. |
| `accounts/make-accounts.php` | Writes `04`, adds staff later, resets a password. Runs on your computer. |
| `accounts/CREDENTIALS.txt` | The plaintext passwords and PINs that match `04`. **Never upload it.** |
| `accounts/CREDENTIALS.html` | The same, printable: a reference table and one cut-out slip per person. **Never upload it.** |
| `DEPLOYMENT.html` | The installation steps (0 to 12) on one page, with tick-boxes and copy buttons. No passwords in it. |

`preflight.php` and `generate-key.php` are **temporary diagnostics**. Both refuse
to run until you give them a secret, and both must be deleted once used.
`make-accounts.php` and the `accounts/` folder never go to the server at all.

---

## Requirements

| Needs | Minimum | Why | Checked by the pre-flight |
|---|---|---|---|
| PHP | 8.2 | Laravel 12 and `composer.json` require it | Yes |
| PHP extensions | `bcmath`, `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo`, `ctype` | `bcmath` runs every money calculation and is not declared in `composer.json`, so nothing else notices it missing until an invoice is priced | Yes |
| Database | MySQL 8.0.16 or newer. Never MariaDB, at any version | CHECK constraints are enforced from 8.0.16; the quality view needs `LATERAL` (8.0.14) | Yes — it tests CHECK and `LATERAL` directly rather than trusting the version string |
| Database privileges | ALL PRIVILEGES on the unit's own database | The import creates views and triggers; without the privilege the triggers are skipped with no error | Through the trigger count |
| Web server | Apache with `mod_rewrite`, honouring `.htaccess` | Laravel's routing, and both single-page apps | No — PHP cannot see it. A 404 on `/api/v1/health` is the sign |
| HTTPS | A certificate on the domain | Sign-in tokens travel in a header and are kept in the browser; the bedside app is a PWA | No |
| Cron | One entry, running every minute | The twice-daily detective check and the nightly quality rollup | No |
| One origin | The API and both apps served from the same domain | There is no CORS configuration | No |
| Disk | About 160 MB for the application, 132 MB of it `vendor/`; then the database and 14 days of logs | | No |
| Your own computer | Node.js 22 or newer to build the two apps; PHP 8.1 or newer to write staff accounts | Neither build nor account tool runs on the server | — |

## Sizing

There has been no load test. What exists is the original design's estimate and a few measurements:

- **The design's estimate** for a unit of 10 to 40 chairs: about 160 sessions a day and 40,000 a year; about nine observations per session, so 360,000 a year; one to two million audit rows a year; well under 20 million rows in the largest table after ten years. Its authors judged a 4-core, 16 GB server comfortable for that, with no partitioning needed before year five.
- **Measured on 26 September 2026:** the application is about 160 MB on disk before any data; each built app about 0.4 MB; the development database, holding the reference data and a few dozen test records, about 4 MB.
- **On shared hosting**, the job most likely to hit a resource limit is the nightly quality rebuild, which reads every session — see the CloudLinux note under *Known limitations*.

---

## Step 0 — Find out whether the host runs MySQL or MariaDB

**This decides whether the deployment is possible at all.**

In cPanel, open **phpMyAdmin**. The server version is shown on the front page,
or run:

```sql
SELECT VERSION();
```

- **MySQL 8.0.16 or newer** — continue to step 1. A build tag after the version is
  normal and harmless: `8.4.9-cll-lve` is CloudLinux's MySQL Governor build, still
  MySQL. What matters is the number and the absence of the word MariaDB.
- **MySQL older than 8.0.16** — stop. Ask the host to move you to a newer server.
  Before 8.0.16, MySQL accepts CHECK constraints and silently ignores them: the
  schema would import cleanly with all 64 of its constraints doing nothing.
- **Anything saying MariaDB** — **stop.**

### Why MariaDB is a full stop

The quality view `v_monthly_quality` uses `LEFT JOIN LATERAL`. MariaDB has never
supported `LATERAL`, at any version, so that view cannot be created and
`sql/01-schema.sql` will not import.

This is not a formatting preference that can be worked around in an afternoon.
Joining `session_events` directly into that aggregate instead — the obvious
rewrite — multiplies every session row by its event count and inflates every
number in the quality report. The `LATERAL` is there *because* that bug
happened once already, and there is a regression test pinning it.

Moving to MariaDB means rewriting and re-validating that view, plus re-checking
16 stored generated columns and 64 `CHECK` constraints against MariaDB's
different semantics. Treat it as a project, not a deployment step.

Most cPanel hosts offer a MySQL server on request. Ask before doing anything else.

---

## Step 1 — Create the database and its user

In cPanel → **MySQL Databases**:

1. Create a database, e.g. `dialysis`. cPanel prefixes it with your account
   name, so the real name becomes something like `cpuser_dialysis`.
2. Create a user, e.g. `dialysisapp`, with a long random password.
3. Add the user to the database with **ALL PRIVILEGES**.

Write down all three values — the prefixed database name, the prefixed username,
and the password. Step 5 needs them.

> **Why all privileges:** the import creates triggers and views. A user with only
> `SELECT`/`INSERT`/`UPDATE`/`DELETE` will import the tables and silently skip
> the six triggers, leaving the database-level invariant backstops missing while
> everything appears to have worked.

---

## Step 2 — Upload the application

Using **File Manager** or FTP, upload the contents of `apps/api/` to a directory
**outside** your public web root — for example `/home/cpuser/dialysis-api/`.

Include the `vendor/` directory. It is roughly 135 MB and it must be uploaded,
because `composer install` needs a shell. Upload it as a single `.zip` and
extract with File Manager rather than transferring 15,000 files individually.

Do **not** upload:

- `tests/`
- `verify/`
- `.env` (your local one — you will create a fresh one in step 5)
- `storage/framework/testing/`

### Where the web root points

Laravel serves from `public/`, not from the application root. Two ways to
arrange this:

**Preferred — repoint the document root.** In cPanel → **Domains**, set the
domain's document root to `/home/cpuser/dialysis-api/public`. Nothing else
changes and the application code stays outside the web root.

**Fallback — if your plan will not let you change the document root.** Copy the
contents of `public/` into `public_html/`, then edit `public_html/index.php` and
change the two `require` paths to point at the application directory:

```php
require __DIR__.'/../dialysis-api/vendor/autoload.php';
$app = require_once __DIR__.'/../dialysis-api/bootstrap/app.php';
```

The fallback works, but everything except `public/` then sits one directory
above a publicly served folder. Prefer the first option.

`public/.htaccess` is already present and correct — do not delete it. Without it
every URL except `/` returns 404.

---

## Step 3 — Set folder permissions

In File Manager, set these to **0755** (some hosts need 0775):

```
storage/framework/sessions
storage/framework/cache
storage/framework/views
storage/logs
bootstrap/cache
```

Sessions, cache and compiled views all use the file driver, so the application
cannot boot without these being writable.

---

## Step 4 — Import the database

In cPanel → **phpMyAdmin**, select your database in the left sidebar, then use
the **Import** tab. Import the three files **in this order**:

1. `sql/01-schema.sql`
2. `sql/02-post-baseline.sql`
3. `sql/03-seed.sql`

Wait for each to finish before starting the next.

### If phpMyAdmin refuses the file for being too large

`01-schema.sql` is about 88 KB, well under any normal limit. If your host caps
uploads lower, use **File Manager** to upload the file into your home directory,
then in phpMyAdmin's Import tab choose "web server upload directory" if offered.
Failing that, open the file in a text editor and paste it into the **SQL** tab in
two or three parts, splitting only between complete statements.

### What each file does

- **01** builds the schema: 66 tables, 12 views, 6 triggers, 15 generated
  columns, 64 `CHECK` constraints. The `CREATE DATABASE` and `USE` statements
  that the developer copy carries have already been removed, because a cPanel
  MySQL user cannot create a database and yours already exists.
- **02** adds three tables the baseline predates, and one schema fix. **`personal_access_tokens` is
  not optional** — Sanctum stores every issued token there, so without it nobody
  can sign in at all. It also creates the `migrations` ledger and records all three
  migrations as applied, so that if anyone later gets shell access, `php artisan
  migrate` does not try to create tables that already exist. The schema fix closes a
  hole in `lab_results`: the baseline`s unique key used a nullable `timing` column,
  which MySQL ignores, so the same result could be filed twice.
- **03** loads reference data: roles, the four shifts, the 20 stations with their
  infection-control designations, and the event and medication reference lists.
  Event codes are a fixed list and the API refuses an unrecognised one, so this
  file is required, not decorative.

Safe to re-run: **02**, **03**, and **04** from step 8 — each adds only what is
missing and changes nothing already there. Re-running **01** stops at its first
statement (*Table 'facilities' already exists*) and changes nothing either.

### Confirm it worked

Run this in the phpMyAdmin **SQL** tab:

```sql
SELECT
  (SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE') AS tables_,
  (SELECT COUNT(*) FROM information_schema.views
     WHERE table_schema = DATABASE())                               AS views_,
  (SELECT COUNT(*) FROM information_schema.triggers
     WHERE trigger_schema = DATABASE())                             AS triggers_;
```

Expect **69 tables, 12 views, 6 triggers**. Anything less means an import
stopped part-way — check phpMyAdmin's error output rather than continuing.

Six triggers matters especially: they are the database-level half of the
invariants that stop a locked session being edited, a high-alert drug going
unwitnessed, and a chair being double-booked. If the count is 0, your MySQL user
lacked the privilege to create them (step 1).

---

## Step 5 — Configure the application

1. Copy `.env.production.example` to the application root as `.env`.
2. Fill in `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` from step 1.
3. Set `APP_URL` to your real https address.
4. Generate `APP_KEY`:
   - Edit `generate-key.php` and replace `CHANGE-ME-BEFORE-UPLOADING` with a
     long random string.
   - Upload it next to `index.php`.
   - Visit `https://your-domain/generate-key.php?key=YOUR-SECRET`.
   - Paste the value, including the `base64:` prefix, into `APP_KEY`.
   - **Delete `generate-key.php` from the server.**

Generate the key once and keep a copy. Nothing in the system encrypts data with
it today — passwords are bcrypt hashes and sign-in tokens are stored as SHA-256
hashes — so changing it signs nobody out. But anything added later that encrypts
with it would become unreadable under a new key.

Leave `APP_DEBUG=false`. With debug on, any 500 response renders a stack trace
containing this file's contents — database password included — to whoever
triggered it.

---

## Step 6 — Run the pre-flight check

1. Edit `preflight.php` and set `SECRET` to a long random string.
2. Upload it next to `index.php`.
3. Visit `https://your-domain/preflight.php?key=YOUR-SECRET`.

It checks the PHP version, the extensions, folder permissions, the `.env`
values, the database connection, MySQL-versus-MariaDB, that CHECK constraints are
actually enforced, `LATERAL` and window function support, the table/view/trigger
counts, whether anyone can actually sign in yet, and that a water system is on
file for the morning check.

**Read the *Unit timezone* line against the wall clock.** It shows the time on
the unit's own clock. The system counts the unit's day in that zone — which day a
water check clears, the date the board opens on, when stock expires — so if the
time shown is wrong, the day boundary is wrong. Fix it in Settings (step 11).

**`bcmath` deserves attention.** It runs every money calculation in the system
and is not declared in `composer.json`, so its absence is not caught at install
time — it surfaces as a fatal error the first time somebody prices an invoice.
If the pre-flight reports it missing, enable it in cPanel → **Select PHP
Version** → *Extensions*.

Resolve every **FAIL** before continuing. The two about administrators and
passwords will still fail at this point — step 8 fixes them. After step 8,
*Starter accounts renamed* stays a warning until every placeholder name has been
replaced by a real person's. Then **delete `preflight.php` from the server.**

---

## Step 7 — Check the application answers

Visit:

```
https://your-domain/api/v1/health
```

You should get JSON reporting `database: ok`, the table/view/trigger counts, and
`redis: not_configured`. That last one is expected and correct — Redis is not
installed, and the endpoint says so rather than pretending.

If you get a 404 here but the domain's root works, `mod_rewrite` is not active
or `public/.htaccess` did not upload. If you get a 500, look in
`storage/logs/laravel.log` through File Manager.

---

## Step 8 — Create the staff accounts

There is no registration screen, by design — and, until one is built, **no
screen that creates a staff member, sets a password or sets a bedside PIN.** The
console's **Settings** page can grant and remove roles, but only on accounts that
already exist. So accounts go in as SQL, and `accounts/make-accounts.php` writes
that SQL on your own computer. It never runs on the server.

### 8a. Put the real people's names on the accounts first

`accounts/accounts.csv` starts with seven placeholder accounts, one for each
role the system checks:

| Sign in | Name on the account | Role | Bedside PIN |
|---|---|---|---|
| `ADM-001` | Site Administrator | `admin` | — |
| `ENC-001` | Records Encoder | `records` | — |
| `BIL-001` | Billing Officer | `billing` | — |
| `TEC-001` | Renal Technician | `technician` | — |
| `HN-001` | Head Nurse | `head_nurse` | yes |
| `RN-001` | Dialysis Nurse | `nurse` | yes |
| `NEP-001` | Attending Nephrologist | `nephrologist` | yes |

**Each account belongs to one person, never to a role.** The name on it is the
name printed on every record it signs — a countersignature by "Attending
Nephrologist" attributes a legal attestation to nobody — and the high-alert
witness check means nothing if two nurses share one sign-in.

So first open `accounts/accounts.csv`, replace each placeholder with the person
who will hold that account (and their PRC licence number where it applies), add
a row for anyone else who needs one, and on a computer with PHP 8.1 or newer run:

```bash
php deploy/accounts/make-accounts.php
```

It writes `sql/04-accounts.sql` and the two credentials sheets. The repository
ships no `04-accounts.sql`: every installation generates its own, so nobody else
holds its passwords. To regenerate, add `--force`, and only while nothing has
been imported anywhere — it prints new passwords. The script refuses a bad row
and names the problem.

### 8b. Import it

In phpMyAdmin, import `sql/04-accounts.sql` exactly as in step 4. It creates an
account only if no account already has that employee number or email, and never
changes an existing one, so importing it twice is harmless and it can never
reset a live password.

### 8c. Hand out the sign-in details, then destroy the sheet

`accounts/CREDENTIALS.txt` holds each account's password and, for the bedside
roles, a six-digit PIN; `accounts/CREDENTIALS.html` is the same, laid out to print
with one cut-out slip per person. **Neither goes to the server**, into email or
into a shared folder. Give each person only their own slip, in person; keep the
reference table somewhere locked or in a password manager; then delete both
files. The pre-flight fails if it finds either on the server.

Staff sign in with their employee number (or email, if one is set). On a bedside
tablet the PIN works once that person has signed in on that tablet with their
password.

Re-run the pre-flight: *Administrator exists* and *Staff who can sign in* now
pass. Then delete it.

### Renaming an account after import

In phpMyAdmin's SQL tab:

```sql
UPDATE staff
   SET first_name = 'Maria', last_name = 'Reyes', licence_no = 'PRC-0123456'
 WHERE employee_no = 'NEP-001';
```

**Only before the account has signed anything.** Records point at the account,
not at a copy of the name, so renaming it later rewrites the name on everything
it already signed. For the same reason an account is never passed on to someone
else — give the new person their own.

### Adding staff later

Append their row to `accounts/accounts.csv` and generate only that row:

```bash
php deploy/accounts/make-accounts.php RN-002
```

That writes `accounts/out/add-accounts-<time>.sql` and a matching credentials
sheet. Import, hand out, destroy — as above. Roles can then be adjusted from
**Settings → Staff and roles**.

### Resetting a password

Nobody can change their own password in the app yet. An administrator does it:

```bash
php deploy/accounts/make-accounts.php --reset RN-001
```

That writes `accounts/out/reset-<time>.sql`: a new password (and a new PIN for a
bedside role), the lockout cleared, and every device the account was signed in
on signed out — so whoever may have learned the old password is out too.
phpMyAdmin should report one row affected by each `UPDATE`; zero means no active
account has that employee number.

### What each role is for

Roles are not seniority tiers:

| Role | For |
|---|---|
| `admin` | Opening Settings and granting roles; can also do most of what the others do |
| `records` | Registering patients and correcting demographics — the unit's encoder |
| `billing` | Claims, remittances, invoices and payments |
| `technician` | Water tests and dialyzer reprocessing |
| `head_nurse` | Everything a nurse does, plus registering patients and ordering labs |
| `nurse` | Running treatments at the chair and charting them; signing as the delivering nurse |
| `nephrologist` | Countersigning and amending treatment records; prescribing; ordering labs |

`dietitian` and `readonly` exist in the role list, but nothing checks them yet —
an account holding only those can sign in and do nothing.

**A unit with no nephrologist cannot close a single treatment record** — sessions
will run and never lock, and nothing can be billed.

The system will stop the last administrator removing their own admin role, since
there would be no way back in short of database access.

---

## Step 9 — Schedule the background work

Two commands must run on a schedule:

| Command | When (UTC) | In Manila | Why |
|---|---|---|---|
| `dialysis:check-controls` | 06:00 and 18:00 | 14:00 and 02:00 | Reads the three detective views and exits non-zero while anything is outstanding. |
| `dialysis:summarise-quality` | 02:30 | 10:30 | Rebuilds the monthly quality rollup the quality report reads. |

The times are UTC because the application's clock is fixed to UTC — the unit's
timezone setting moves the unit's *day*, not the schedule. The twice-daily check
was meant to run before the morning shift is seated; as built it does not, so
until the schedule follows the unit's timezone, someone opens the console's
**Controls** screen each morning before the first patient.

Both are wired into Laravel's scheduler, so cPanel only needs one cron entry. In
cPanel → **Cron Jobs**, add a job running **every minute**:

```bash
cd /home/cpuser/dialysis-api && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Adjust the path to PHP — cPanel's Cron Jobs page usually shows the correct
binary, and it is often a version-specific path like `/usr/local/bin/ea-php84`.

**Do not skip this.** `check-controls` is what makes the detective controls into
controls: cohort breaches, water exceptions and dialyzer failures that got in
through an import or a direct edit are found here and nowhere else. Wire its
failure output to something that reaches a person — a cron email address is
enough to start with.

If the plan genuinely offers no cron at all, the unit must read the console's
**Controls** screen twice a day by standing instruction, and the quality
report will stay empty. Write that down as a known operational gap rather
than discovering it later.

---

## Step 10 — Deploy the two clients

Both are static builds. On a machine with Node:

```bash
npm install
npm run build -w @dialysis/bedside
npm run build -w @dialysis/console
```

Upload the contents of each `dist/` folder:

- `apps/bedside/dist/` → the bedside tablets' URL
- `apps/console/dist/` → the console's URL

**Both must be served from the same origin as the API.** They call `/api/v1`
with relative URLs and there is no CORS configuration, so serving them from a
different subdomain breaks every request with no useful error. Put them in
subfolders of the same domain — for example `/bedside` and `/console` — or
configure CORS before splitting them.

Each folder needs its own `.htaccess` so the SPA loads:

```apache
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /bedside/
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule . index.html [L]
</IfModule>
```

Serve everything over **HTTPS**. Both clients hold their API token in
`localStorage`, and the bedside app is a PWA — service workers do not run over
plain HTTP.

### What each client currently covers

Worth knowing before training anyone, so nobody goes hunting for a screen that
is not there.

**Bedside** — sign in (password, then PIN for the rest of the shift), today's
board from the local cache, check-in, start and end (online only, because that is
where the water, chair, machine and dialyzer checks run), and the flow sheet:
observations with prefill from the previous row, intra-dialytic events, offline
queueing with a visible outbox count, and conflict reporting. It does **not** do
signing or medication administration.

**Console** — daily board with generate-from-standing-patterns, the **Water**
screen (the morning chlorine check the first treatment waits for), patient
registry and chart with bloods, session view with countersignature, claims
(generated per benefit period, moved through the payer's statuses, remittances
keyed), invoices (drafted, issued, payments and refunds taken, voided), the
Controls screen, and settings. It does **not** yet do machine logs, stock,
staff accounts, or the quality and utilisation reports.

**The Water screen is the first thing each morning.** Until a passing
total-chlorine reading is recorded there, the day's first **Start** at the chair
is refused — by design. The technician, a head nurse or an administrator can
record it; everyone else can see whether the unit is cleared. It needs a water
system on file (`03-seed.sql` adds one, *RO Unit A*; rename it in the database
to match yours).

---

## Step 11 — Configure the unit before letting anyone treat a patient

Open the console's **Settings** page as the administrator. Every setting there
explains its own consequence inline; these are the ones that must be right
before go-live.

### Chairs and infection control

The seed ships 20 stations with designations that match a plausible unit, not
yours. **Check every one against the actual room.**

A chair with **no cohorts ticked is unrestricted, not unusable** — the system
will seat any patient in it, including an HBV-positive patient in a chair staff
think of as clean. That is the opposite of what the empty state reads like, so
the page asks for a written reason before it will let you clear the last one.

### High-alert medications

A drug flagged high-alert cannot be given without a witness, and the witness may
not be the person giving it. Check the flagged list against your own policy —
unflagging one removes that second check entirely.

### Benefit programme

**Verify the case rate and the annual session allotment against the current
PhilHealth circular.** The seed carries ₱6,350 and 156 sessions per calendar
year; both have changed before — the rate went ₱2,600 → ₱4,000 → ₱6,350 and the
allotment 90 → 156 — and both will change again.

Rates are added, never edited. Adding one closes the current one and leaves it on
file so past claims still price against what applied then. Each version needs its
own code. The Settings page shows the programmes in force but has no form to add
one yet: a new rate is an API call by an administrator, set out step by step in
the administrator's guide (`docs/admin-guide.html`, "Changing the benefit rate").

The seed carries only the 2023 rate (to 1 July 2024) and the current one (from
9 October 2024). A session dated in between cannot be claimed until a programme
covering those dates is added.

### Facility

**Set the timezone to where the unit actually is** (the seed has `Asia/Manila`).
It is not only for display: it is where the unit's day begins and ends. It
decides which day a water check counts for — so whether the morning's first
treatment may start — which date the board and the bedside tablets open on, and
the moment stock passes its expiry date. Every timestamp is still stored in UTC,
so changing it moves no recorded time; it changes which calendar day that time
falls on.

The currency is display only. It converts nothing.

---

## Step 12 — Final checks

Work through this against the live installation:

- [ ] The technician can record a water check on the **Water** screen, the
      screen then shows *Cleared to dialyse today*, and a nurse can start the
      day's first treatment — times on that screen match the wall clock
- [ ] `/api/v1/health` reports `database: ok`, 69 tables, 12 views, 6 triggers
- [ ] `preflight.php` and `generate-key.php` are **deleted from the server**
- [ ] No credentials sheet is on the server, and the local copy has been destroyed
      once everyone had their entry
- [ ] Every account carries the name of the one person who holds it (the
      pre-flight showed no *Starter accounts renamed* warning)
- [ ] `APP_DEBUG=false`, confirmed by triggering a 404 and seeing no stack trace
- [ ] The administrator can sign in to the console
- [ ] At least one nephrologist exists, or no record can ever be signed
- [ ] Chair cohorts in Settings match the actual rooms
- [ ] The benefit case rate and allotment match the current circular
- [ ] The daily board loads and **Generate from standing patterns** works
- [ ] A nurse account can sign in on a bedside tablet, lock it, unlock it with the
      PIN, and open a flow sheet
- [ ] Charting an observation offline, then reconnecting, lands the row on the server
- [ ] The **Controls** screen loads and reads 0 outstanding
- [ ] Cron has fired at least once (check `storage/logs/`)
- [ ] A database backup has been taken **and restored somewhere** to prove it works
- [ ] The clinical thresholds have been checked against your own SOPs:
      total chlorine 0.1 ppm, Kt/V ≥ 1.2, URR ≥ 65%, dialyzer TCV floor 80%,
      UF rate concern ~13 ml/kg/h

---

## Five-minute check before handover

Run this against the live installation after step 12, and again after every
upgrade. Tick each line; anything that does not match stops the handover.

| # | Do | Expect | Done |
|---|---|---|---|
| 1 | Open `https://your-domain/api/v1/health` | `"status": "ok"`; database `ok`; schema 69 tables, 12 views, 6 triggers; migrations with 0 pending; Redis `not_configured` | |
| 2 | Open `https://your-domain/api/v1/no-such-page` | A plain *Not Found* — no stack trace, no file paths. Proves `APP_DEBUG=false` | |
| 3 | Open `/preflight.php` and `/generate-key.php` on the domain | Both 404: the files are gone | |
| 4 | Open `https://your-domain/.env` | 404 or 403, never the file | |
| 5 | Sign in to the console as an administrator and open **Settings** | The unit's timezone; chair cohorts that match the rooms; the current benefit rate | |
| 6 | Open **Water** | A water system is offered; times on the page match the wall clock | |
| 7 | Open **Controls** | It loads; on a new installation it lists nothing | |
| 8 | On a tablet: sign in as a nurse, press Lock, unlock with the PIN | It unlocks, and the board shows today | |
| 9 | In phpMyAdmin: `SELECT employee_no, first_name, last_name FROM staff;` | Every account carries one real person's name | |
| 10 | In cPanel → **Cron Jobs** | One entry running `schedule:run` every minute, with the right paths | |

## Hardening

Most of this is already in the steps above; this is the list to audit against.

- **Debug off, production on.** `APP_DEBUG=false` and `APP_ENV=production`. The pre-flight fails without the first.
- **Code outside the web root.** Point the document root at `public/` (step 2). The fallback layout works but leaves everything else one folder above a public one.
- **No diagnostics left behind.** `preflight.php` and `generate-key.php` deleted; neither runs without its secret, but neither should exist.
- **HTTPS only.** Most cPanel versions offer a *Force HTTPS Redirect* switch under **Domains**; turn it on. The apps keep their sign-in token in the browser, so plain HTTP would expose it on the unit's Wi-Fi.
- **The database account is the application's alone.** It has privileges on its own database and nothing else. No person uses it day to day, and nobody holds a separate account that can write. The audit log is written by the application, so a direct SQL change never appears in it. If the host offers binary logging with `binlog_format=ROW`, turn it on: it is the independent record.
- **One account per person.** Credentials sheets destroyed after hand-out; leavers deactivated the same day; roles reviewed monthly (the administrator's guide has the SQL).
- **Tablets locked down.** A device passcode, the Lock button whenever the nurse leaves the chair, kept on chargers, and not used for anything else.
- **Rate limits are on**: five password sign-ins a minute, ten PIN attempts, sixty sync batches. Five failures lock an account for 15 minutes.
- **Directory listing is off** through Laravel's `public/.htaccess`, wherever Apache's `mod_negotiation` is loaded, as it usually is. Keep that file.
- **Backups encrypted before they leave the server**, and restore-tested monthly.
- **Patch the ground.** Keep the host's PHP on a supported version, and take dependency updates through a tested release rather than on the live server.

## Upgrading

A release is a new copy of `apps/api` with its `vendor/`, freshly built apps, and any
new SQL files in `deploy/sql`. On a host with no shell:

1. **Read the release notes** for schema changes, new settings and anything to do after.
2. **Choose a quiet time** — after the last shift, never during a treatment. Charting on
   the tablets queues while the server is unavailable, but check-in, start and end do not.
3. **Take a backup** of the database, download the live `.env`, and write down which
   release is running.
4. **Upload the new release beside the old one**, for example
   `/home/cpuser/dialysis-api-2026-10-15/` next to `/home/cpuser/dialysis-api/`, as a
   zip extracted with File Manager. Copy the live `.env` into it, and the contents of
   `storage/` if anything was ever uploaded there. Set the folder permissions (step 3).
5. **Remove any cached configuration that came with the upload:** delete
   `bootstrap/cache/config.php` and any `bootstrap/cache/routes-*.php` in the new
   release. A configuration cache built on another machine carries that machine's
   settings — its database, its debug flag — and makes the server ignore `.env`.
6. **Apply the release's new SQL files** in phpMyAdmin, in order. They are written to be
   safe to re-run. Never re-import `01` on a live database.
7. **Switch:** point the document root at the new release's `public/` (cPanel →
   **Domains**). In the fallback layout, change the two `require` paths in
   `public_html/index.php` instead.
8. **Replace the apps:** upload each new `dist/` over its folder, keeping the
   `.htaccess` there.
9. **Run the five-minute check**, then the release's own checks.
10. **Keep the old release** until the unit has worked a full day on the new one.

## Rolling back

Decide before upgrading how you would undo it. The answer depends on whether the
release changed the database.

- **The release changed no database objects.** Point the document root back at the
  previous release's `public/` and put the previous apps back. Nothing is lost:
  whatever was charted on the new release is in the same database the old code reads.
- **The release changed the database, and nothing clinical has been recorded since.**
  Restore the pre-upgrade backup into a new, empty database, point the old release's
  `.env` at it, switch the document root back, and run the five-minute check.
- **The release changed the database, and staff have charted since.** Do not restore
  over it: the backup predates those treatments, and restoring discards them. If the
  change only *added* things the old code ignores — a new table, a new nullable column —
  switch the code back and leave the database as it is. Otherwise keep the new release
  and fix forward. Every release that changes the schema must say which of the two it is.

Either way, anything still queued on a tablet replays after the switch and is not
duplicated, and whatever you did is written down.

## Moving to another host

1. **Qualify the new host first** (step 0), and run the pre-flight there against an
   empty database before moving anything.
2. **Freeze writes.** Pick a quiet window after the last shift, and confirm every
   tablet's sync bar shows nothing waiting.
3. **Export the whole database** from the old host — structure, data, views and
   triggers — with cPanel's database backup or phpMyAdmin's Export.
4. **Import it into an empty database** on the new host. Do not import `01-schema.sql`
   first and the data on top: the stock trigger would add every stock movement to its
   lot a second time.
5. **If the import stops on a `DEFINER` or `SUPER` error** — views and triggers remember
   the database user that created them — remove the `DEFINER=…` clauses from the dump
   and import again into a fresh, empty database.
6. **Verify:** 69 tables, 12 views and 6 triggers; the same row counts in `patients`,
   `treatment_sessions`, `claims` and `audit_logs` as on the old host; the same balances
   in `stock_lots`.
7. **Move the application** as in steps 2 and 3, with the same `.env` apart from the new
   `DB_*` values, then steps 9 and 10 for cron and the apps.
8. **Switch the domain**, then run the five-minute check.
9. **Keep the old host read-only** for a week, then decommission it and securely delete
   its copy of the database.

This procedure has not been rehearsed on a real move. Rehearse it on a copy first.

## If you have a shell

On a host with SSH, the same bundle installs faster. This route has not been
exercised on a production server; the steps above are the tested one.

```bash
cd /home/cpuser/dialysis-api                 # apps/api, uploaded as in step 2
mysql -u cpuser_dialysisapp -p cpuser_dialysis < deploy-sql/01-schema.sql
mysql -u cpuser_dialysisapp -p cpuser_dialysis < deploy-sql/02-post-baseline.sql
mysql -u cpuser_dialysisapp -p cpuser_dialysis < deploy-sql/03-seed.sql
mysql -u cpuser_dialysisapp -p cpuser_dialysis < deploy-sql/04-accounts.sql
php artisan key:generate                      # instead of generate-key.php
php artisan dialysis:check-controls           # expect: no outstanding violations
```

Here `deploy-sql/` stands for wherever you uploaded the four files; they do not belong
inside the application folder afterwards.

**Do not build the production database with `php artisan migrate`** unless the database
is literally named `dialysis`. The baseline schema carries its own `CREATE DATABASE`
and `USE dialysis;`, which the application strips only when running tests — so on a
server, `migrate` would try to build the schema in a database called `dialysis`, not
the one in `.env`. The SQL files above have those statements removed.

For later upgrades with a shell, `php artisan down` before switching releases and
`php artisan up` after; apply new SQL files the same way.

---

## Known limitations of a shared-hosting deployment

Say these out loud before go-live rather than discovering them afterwards.

- **No staff management in the app.** Accounts, passwords and PINs exist only
  through SQL written by `accounts/make-accounts.php`. Nobody can change their own
  password; a reset is an administrator importing a reset file. Until a screen
  exists, the credentials sheets are the most sensitive documents in the
  deployment — treat them that way.
- **No queue worker.** `QUEUE_CONNECTION=sync` means any queued job runs inside
  the web request. Nothing queues work today, so this is honest rather than a
  compromise — but it stops being true the moment someone adds a job.
- **No Redis.** Cache and sessions are files on disk. Fine for one unit; it will
  not survive being load-balanced across servers.
- **The bedside service worker is not wired into the Vite build.** The outbox
  still works — it is IndexedDB and survives offline — but a *cold* load with no
  network shows nothing, because the app shell itself is not cached.
- **No automated UI tests.** Both clients have been driven by hand in a browser;
  neither re-verifies itself, so a regression will be found by a user.
- **CI has never run.** The workflow exists but this is not a git repository.
- **Some settings are not editable in the app.** Shifts, machines, and adding or
  retiring a chair all need direct database access for now. You can set an
  existing chair's cohorts from Settings, but not create one.
- **CloudLinux resource limits apply.** A version tagged `-cll-lve` runs under
  CloudLinux LVE, which caps CPU, memory and I/O per account and can have MySQL
  Governor throttle or kill a query that exceeds them. Nothing here is heavy for one
  unit, with one exception: `dialysis:summarise-quality` rebuilds the monthly rollup
  from `v_monthly_quality`, a materialised view, and that is the job most likely to
  trip a limit as years of sessions accumulate. If the quality report ever goes stale,
  check the LVE faults in cPanel before suspecting the code.
- **The scheduled check runs on UTC.** 06:00 and 18:00 UTC are 14:00 and 02:00 in Manila, so the check does not run before the morning shift. Open the **Controls** screen each morning instead.
- **One failed chlorine test keeps the check red for 90 days.** The water list keeps every breach of the last 90 days, corrected or not, and `check-controls` exits non-zero while anything is listed. Look for new rows, not for a zero.
- **A new benefit rate has no form.** Settings shows the programmes; adding one is an API call (the administrator's guide has it).
- **The Controls screen dates a water breach by the UTC day.** A reading above
  the limit logged at 05:30 in Manila is listed there under the day before. Only
  the date shown is affected — the breach is listed either way, and the check
  that blocks treatment uses the unit's own day.
- **Backups are yours to arrange.** cPanel's own backups are usually nightly and
  overwrite. These records are retained 10–15 years. Set up something with real
  retention before the first real patient is charted.
