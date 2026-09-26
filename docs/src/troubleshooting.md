## Where the answers are {#where-answers}

Almost every problem in this system has already been written down somewhere by the system itself. Look in these four places before guessing.

1. **The message on the screen.** A refusal is the system working, and it says which rule fired and what clears it — for example *"Total chlorine is 0.15 ppm, above the 0.1 ppm action limit. Do not dialyse until the carbon beds are corrected and a passing check is logged."* The screens show the server's message word for word. Read it to the end before doing anything else; the sections below are organised by these messages.
2. **The Controls screen** on the console (the same list `php artisan dialysis:check-controls` prints): infection-control breaches on today's and later sessions, dialyzers that must not be issued again, and water exceptions from the last 90 days.
3. **The Laravel log**, `storage/logs/laravel-YYYY-MM-DD.log` on the server (File Manager on cPanel), one file a day, kept 14 days. A 500 error leaves its cause here. So do *sync operation failed* (charting from a tablet that was refused, with the reason) and *Detective controls found violations*.
4. **The database's own records** ([AG·1 §6](admin-guide.html#audit)): `audit_logs` for who changed what, `session_notes` for amendments and overrides, `login_events` for every sign-in attempt, `sync_batches` for every tablet upload and its verdicts, and `GET /api/v1/health` for the state of the installation itself.

Each table below reads **symptom → cause → action**. Quoted text is the system's own message; *{braces}* mark the parts that change.

## Signing in and tablets {#sign-in}

| Symptom | Cause | Action |
|---|---|---|
| "These credentials do not match our records." | The employee number (or email) or the password is wrong. The message does not say which, on purpose | Check the employee number on the person's slip. After five failures the account locks |
| "This account is temporarily locked." | Five failed sign-ins or PINs in a row | It clears itself after 15 minutes, or the administrator clears it ([AG·1 §2](admin-guide.html#people)) |
| "This account is not active." | The account was deactivated | The administrator reactivates it only if the person is back; otherwise they use their own account |
| "This PIN is not valid on this device." | Wrong PIN — or the account is inactive or unknown | Re-enter it. Failures count toward the lock |
| "This device has not been signed in with a password for this user." | A PIN works only on a tablet where that person has signed in with their password at least once | Sign in with the password on this tablet, then use the PIN |
| "This token was issued to a different device and has been revoked." | A sign-in token arrived from a device other than the one it was issued to — copied, cloned, or sent by a client that left off the device header | Sign in again with the password. If it recurs on one tablet, its app data was restored from another device |
| Signed out after a long shift | Tokens last 12 hours | Unlock with the PIN; nothing queued is lost |
| "Too Many Attempts" | More than five password sign-ins, or ten PIN attempts, in one minute | Wait a minute |
| The tablet shows nothing after a restart with no network | The app cannot start from cold offline: its service worker is not built yet | Keep the app open during the shift. It needs the network once to load |
| Nobody can change their own password | Not built | The administrator resets it ([AG·1 §2](admin-guide.html#people)) |

## The morning water check {#water}

| Symptom | Cause | Action |
|---|---|---|
| "No water check has been logged for *{date}*. Record a total-chlorine reading on the Water screen before the first treatment." | No check for the unit's day — or the unit's timezone is wrong, so "today" is a different day | Record the check on the Water screen. If one was recorded and this still appears, check the timezone ([§11](#times)) |
| "The water check for *{date}* did not record total chlorine." | The latest check left chlorine blank | Record a check with total chlorine |
| "Total chlorine is *{x}* ppm, above the 0.1 ppm action limit. Do not dialyse until the carbon beds are corrected and a passing check is logged." | The latest check failed | Correct the carbon beds, re-test, record the passing check. There is no override |
| "This reading is above the 0.1 ppm action limit. Say what was done about it…" | A failing reading must carry the action taken | Write what was done, even "stopped, carbon tank being changed" |
| "A water check cannot be recorded for a time that has not happened yet (…)." | The time given is more than two minutes ahead of the server's clock — usually the device's clock, or a time entered in the wrong zone | Correct the device's clock, or leave the time empty to use now |
| The Water screen offers no water system | No active water system is on file | Add or re-activate one with SQL ([AG·1 §3](admin-guide.html#read-only)) |
| A re-test failed at noon, yet the 13:00 start was allowed | By design for now: once a passing check exists for the day, later starts are allowed. The Water screen says so in words | Decide clinically whether to proceed. Whether the system should stop it is an open question ([DS·1 §9](design-spec.html#open-questions)) |
| Only some staff can record a check | Recording is limited to technician, head nurse and admin; everyone can see the clearance | Ask one of them |

## Check-in and start {#check-in-start}

| Symptom | Cause | Action |
|---|---|---|
| "Station *{S-01}* is not designated for the *{HBV}* cohort." | The chair's cohorts do not include the patient's | Move the patient to a suitable chair. If none is usable, override with a reason of at least ten characters |
| "Machine *{M-0042}* is dedicated to a different cohort." | The machine is reserved for another cohort | Use another machine, or override with a reason |
| "That machine last treated a *{hbv}* patient and has had no disinfection cycle since." | Cohort carry-over: no disinfection recorded after a patient of another cohort | Disinfect the machine and record it — which today is an API call (`POST /api/v1/machines/{machine}/disinfection`), not a screen. Otherwise use another machine or override |
| "Machine *{M-0042}* is *{under_repair}* and cannot be used." | Only machines in service or on standby can be used | Use another. Returning it to service is a maintenance record through the API |
| "Record the pre-dialysis weight before starting; the UF goal depends on it." | No pre-dialysis weight on the session | Weigh the patient and enter it at check-in |
| "A session that is *{scheduled}* cannot become *{in_progress}*. Expected one of: *{checked_in}*." | Steps out of order | Do the missing step. The order is check-in, start, end |
| "Dialyzer *{DZ-0041}* belongs to *{MRN}* and must never be used on another patient." | The label scanned is another patient's unit | Use this patient's own unit |
| "Dialyzer *{DZ-0041}* is at *{74.5}*% of its original total cell volume, below the 80% minimum. Discard it." | Volume below the floor | Discard it and issue another |
| "Dialyzer *{DZ-0041}* has been used *{6}* times and its limit is *{6}*. Discard it." | Reuse count spent | Discard it and issue another |
| The same infection-control breach needs a second reason at the start | The start re-checks deliberately; an override at check-in does not carry forward | Give the reason again; it leaves a second note. Whether it should carry is an open question |
| The chair turns out to be wrong at the start | A chair cannot be changed after check-in; the start takes no chair | Override with the reason, or have the session corrected outside the app |
| Check-in, start and end are greyed out on the tablet | The tablet is offline; these steps need the network | Reconnect. Charting still works offline |
| A number of runs "since last disinfection" appears | A hygiene note, not a refusal; the interval is the unit's policy | Follow the unit's SOP |

## Charting and ending {#charting}

| Symptom | Cause | Action |
|---|---|---|
| "An observation for this session at that time is already recorded." | One observation per session per time; a second at the same minute is a duplicate | To correct a reading, add a new one at the real time. Both stay on the record |
| "This event is already recorded." | The same event was sent twice | Nothing — it is on the record once |
| An event code is rejected | Codes come from a fixed list (`event_refs`) | Pick from the list; adding a code is SQL ([AG·1 §3](admin-guide.html#read-only)) |
| "Session *{id}* was signed and locked at *{time}*. Corrections go through the amendment path." | The record locked while this device was working on it | A nephrologist amends it ([§6](#signing)) |
| "*{Drug}* is a high-alert medication and requires a witness." / "…the nurse giving it cannot witness their own dose." | Witness rule — reachable through the API only, since doses have no screen yet | Record a witness who is not the giver |
| The Kt/V shown after the end differs from the tablet's own estimate | The stored figure is the server's, from the BUN samples | None; the server's is the one of record |
| No Kt/V or URR after the end | Both BUN samples were not recorded, or the start time, post weight or UF was missing | Adequacy needs pre- and post-BUN; Kt/V also needs the weights, UF and times |
| "The planned ultrafiltration rate is above 13 ml/kg/h…" on the flow sheet | A marker for review, not a refusal | Clinical judgement |

## Signing and amending {#signing}

| Symptom | Cause | Action |
|---|---|---|
| "Cannot sign, missing: *{post_weight_kg, ended_at}*." | A record cannot be signed incomplete | Fill what it names. Once a session has ended there is no edit before signing — see the last row |
| "Session *{id}* is already locked." | Both signatures are in | Corrections are amendments |
| The countersign button is missing or refused | Only a `nephrologist` may countersign, and only once | Check the account's roles in Settings |
| No record in the unit ever locks | There is no nephrologist account | Create one ([AG·1 §2](admin-guide.html#people)) |
| "Session is not locked; edit it normally." | An amendment was sent for an unlocked session | Use the normal step |
| "An amendment requires a reason." | Blank reason | Give one that will mean something in a year |
| "Cannot amend: *{patient_id}*" | Identity fields and the lock time are never amendable | A session charted against the wrong patient needs clinical and records review, not an amendment |
| A value on an ended but unsigned session is wrong | The system has no edit between the end and the signatures | Complete both signatures, then a nephrologist amends it with a reason — today through the API (`POST /api/v1/sessions/{session}/amend`) |

## Billing {#billing}

| Symptom | Cause | Action |
|---|---|---|
| "Session *{id}* is not signed. Only a locked record may be claimed." | Unsigned | Get both signatures |
| "Session *{id}* is marked not billable." | The session was flagged not billable | Check why before changing it |
| "Session *{id}* is *{aborted}*; only a completed treatment is claimable." | Aborted runs are not claimed | None |
| "Session *{id}* is already on claim *{CLM-…}*." | Invariant 6 | Open that claim |
| "No benefit program is effective on *{date}* for modality hd." | No programme covers that date | Add the programme in force then ([AG·1 §3](admin-guide.html#benefit-rate)) |
| "This claim mixes benefit programs…" / "This claim spans two benefit periods…" | One claim covers one programme and one period | Split it: one claim per programme and period |
| "This claim covers *{n}* session(s) but only *{m}* of the *{k}* allotted remain in the period ending *{date}*." | Allotment | Claim no more than remain; plan the rest with the patient |
| "A claim approved at zero is a denial, and a denial needs a code to resubmit against." | Zero approved, no denial code | Enter the payer's denial code from the advice |
| "Claim *{no}* has gone to the payer, so it cannot go back to draft or ready, or be voided…" | Voiding a submitted claim would free its sessions to be billed twice | Record what the payer does instead |
| "A claim becomes *{paid}* when the payer's remittance is recorded…" | Approved, partially paid and paid follow the money | Record the remittance |
| "Claim *{no}* was denied. An appeal is not something this system records yet…" | No appeal path | Handle the appeal outside the system; the denial code is on the claim |
| "Invoice *{no}* is *{void}* and takes no payments." | The invoice is void or not issued | Issue it first, or work on the current invoice |
| "Session *{id}* is already on invoice *{no}*." | One invoice per session | Open that invoice |
| The invoice says the patient owes nothing | The programme forbids balance billing | Correct; nothing to do |
| An invoice charged the full list price | It was drafted before the session was claimed; the screen warns | Claim first, then invoice |

## Offline and sync {#sync}

| Symptom | Cause | Action |
|---|---|---|
| The outbox count on the tablet does not fall | No network, an expired sign-in waiting for the PIN, or the server is down | Reconnect; unlock with the PIN if asked; check `/api/v1/health`. The tablet retries every 30 seconds and when the network returns |
| An entry comes back **conflict**: "Session was signed on another device…" | The record locked while the tablet was offline | If the entry is still right, a nephrologist amends the record |
| An entry comes back **conflict**: "This treatment was ended while this device was offline…" | Adequacy was computed from the values present at the end | Sign, then amend if the entry was right |
| An entry comes back **rejected**: "Not applied. An offline update cannot change *{…}*." | A lifecycle, chair, machine, dialyzer, adequacy, dry-weight or signature field was sent offline | Those go through check-in, start and end online. If the tablet did this on its own, it is a client bug — report it |
| **Rejected**: "Unknown operation type: *{…}*" | The tablet's app is newer or older than the server | Reload the app from the server; update both together |
| **Rejected**: "Unknown session" | The session is not on this server — the tablet was set up against another installation | Sign in to the right server |
| A tablet's entries are missing on the console | The outbox has not drained | Check the tablet's sync bar before it is put away. Charting still on the device is charting nobody else can see |

## Settings and accounts {#settings}

| Symptom | Cause | Action |
|---|---|---|
| "You are the only administrator. Give someone else the admin role before removing your own." | The last admin cannot remove their own role | Grant `admin` to another person first |
| Clearing a chair's last cohort asks for a reason | A chair with no cohorts accepts every patient, including HBV | Only clear it if that is what you mean |
| A setting you need is not on the screen | Shifts, chairs, machines, water systems, reference lists and payers are SQL for now | [AG·1 §3](admin-guide.html#read-only) |
| "The code *{X}* is already used by another programme…" | Each programme version needs its own code | Use a new code, such as *{X}_2027* |
| "The hd programme in force already starts on *{date}*. A new one must start after it." | Programmes are added forward in time | Correct the start date |

## After installing or upgrading {#install}

| Symptom | Cause | Action |
|---|---|---|
| `/api/v1/health` returns 404, but the domain's root works | `mod_rewrite` is off or `public/.htaccess` did not upload | Upload `.htaccess`; ask the host to enable `mod_rewrite` |
| A 500 on every request | Usually folder permissions or a wrong database password; the log names which | Read `storage/logs/`. Make `storage/*` and `bootstrap/cache` writable; check the `DB_*` values in `.env` ([DG·1](deployment-guide.html)) |
| A stack trace appears in the browser | `APP_DEBUG=true` in production — it exposes the `.env`, database password included | Set `APP_DEBUG=false` now, then change the database password |
| Health reports the schema as `fail` | An import stopped part-way; expect at least 66 tables, and exactly 12 views and 6 triggers | Re-import the files in order; check phpMyAdmin's error output |
| Health shows 0 triggers | The database user lacked the privilege to create them | Grant the user ALL PRIVILEGES on its database and re-import `01-schema.sql` into an empty database |
| Health reports migrations `fail` with some pending | `02-post-baseline.sql` was not imported | Import it; it is safe to re-run |
| Nobody can sign in at all | `personal_access_tokens` is missing (from `02`), or no account exists (`04`) | Import `02`, then the accounts |
| The console or tablet loads, but every request fails | The client is served from a different origin than the API; there is no CORS configuration | Serve both apps from the API's own domain, in sub-folders |
| A page works from its link but 404s when reloaded | The client folder has no `.htaccess` sending unknown paths to `index.html` | Add it ([DG·1](deployment-guide.html)) |
| The first invoice or claim fails with a fatal error | The PHP `bcmath` extension is missing | Enable it in cPanel's PHP extensions |

## Times and dates look wrong {#times}

| Symptom | Cause | Action |
|---|---|---|
| Times on screens are hours off the wall clock | The unit's timezone in Settings is wrong | Set it to where the unit is. Stored times do not move; the day they fall on does |
| A water check counted for the wrong day, or the board opened on yesterday | Same cause | Same action |
| The Controls screen lists a water breach under the previous day | That list dates a breach by the UTC day. A 05:30 reading in Manila is 21:30 UTC the day before | Display only: the breach is listed either way, and the check that blocks treatment uses the unit's own day |
| `created_at` values in the database are hours ahead of UTC | A few columns are filled by MySQL itself, in the database server's zone, not the application's UTC | Known; read those columns with care |
| The twice-daily check ran at 14:00 and 02:00 | The schedule runs on UTC ([AG·1 §4](admin-guide.html#jobs)) | Open the Controls screen each morning |

## Reports and scheduled jobs {#jobs}

| Symptom | Cause | Action |
|---|---|---|
| The Controls screen is never at zero after a water breach | Water exceptions stay on the list for 90 days whether or not they were corrected; it is a record of exceptions, not a to-do list | Look for new rows, not a zero. A breach with an action written was dealt with |
| A cohort row on the Controls screen | A session today or later sits in a chair or on a machine against the patient's cohort — an override, an import, or a direct edit | Reseat the patient if it is not deliberate; if it is an override, it stays listed for that day |
| A dialyzer row on the Controls screen | An active unit is below 80% of its volume or at its reuse count | Condemn it with a reprocessing record through the API; it leaves the list when it is no longer active |
| `dialysis:check-controls` fails every run | It exits non-zero while anything is listed — including 90 days of water exceptions | Read what it lists; see the first row |
| The quality report is empty or stale | The scheduler is not running, or the host killed the rebuild for exceeding its resource limits | Check `MAX(summarised_at)` and the cron entry; on a CloudLinux host check the LVE faults in cPanel; run `dialysis:summarise-quality` by hand if you have a shell |
| Nothing in the log from the scheduled check | Either nothing was outstanding or the scheduler is not running | The summariser's timestamp tells the two apart ([AG·1 §4](admin-guide.html#jobs)) |

## When the data is wrong {#wrong-data}

:::stop Stop, and take a backup
Before you change anything, take a backup of the database as it is now — wrong data included — and keep it. It is the evidence of what happened, and your way back if the fix makes it worse.
:::

Then, in this order:

1. **Find out what happened before deciding what is true.** `audit_logs` shows who changed the record and what it was before; `session_notes` shows amendments and overrides; `sync_batches` shows what each tablet sent and what the server answered; `record_access_logs` shows who had the record open. A wrong number with an audit trail is a correction to make; a wrong number without one was written around the application, and that is a bigger problem.
2. **Use the path the system provides**, so the correction carries a reason and an audit record:

   | What is wrong | The sanctioned path |
   |---|---|
   | A value on a signed session | A nephrologist amends it with a reason (API) |
   | A value on an ended, unsigned session | Sign both, then amend |
   | A dry weight | Set a new one from the correct date. Sessions already run keep the target they were run against; amend a session only if its snapshot itself was wrong |
   | Serology, and so the cohort | Record the correct result (API); the cohort follows the latest result |
   | A patient's demographics or status | Records or admin through the API (`PATCH` or `POST …/status`) |
   | A claim still in draft or ready | Void it and generate a new one |
   | A claim the payer already has | No path yet; record what the payer does, and handle the correction outside the system |
   | An invoice | Void it with a reason and draft a new one |
   | A lab result | No amend path yet (a known gap); a correction is a direct change — see step 3 |
   | Stock | Receipts and issues go through the API. A write-off or a quarantine exists in the service but has no endpoint yet, so it needs a developer. Never edit a lot's balance directly — the trigger owns it |

3. **If there is no path and the change must be made in SQL**, it will bypass the services and the audit log. So write down beforehand what you will change, why, who authorised it and the old values; change as little as possible; never touch a locked session's clinical columns (the trigger refuses, and `@allow_amendment` belongs to the amendment path alone); never delete a patient, a session or an audit row; then run `dialysis:check-controls` and keep your note with the backup.
4. **Tell the clinical lead** about anything that changed a clinical record. A corrected number that somebody acted on is a clinical incident, not only a data fix.

## The worst case {#worst-case}

**The server or the database is gone.** The unit carries on dialysing on paper: every session field can be back-entered later, and the system was designed for a full day on paper. Then:

1. Restore the most recent good backup into an empty database — on the same host or a new one ([DG·1](deployment-guide.html)).
2. Check it: 69 tables, 12 views, 6 triggers; `/api/v1/health` reports `ok` for the database and schema.
3. Point `.env` at it. Keep the same `APP_KEY` if you have it; if it is lost, generate a new one. Nothing is encrypted with it today, so passwords and sign-in tokens carry on working.
4. Open the Controls screen and resolve what it lists.
5. Reconnect the tablets. Anything still in their outboxes replays and is not duplicated. Anything they had already sent before the loss, but after the backup, is not on the tablets any more — back-enter it from paper.
6. Back-enter the paper records from the outage, oldest first.

**Nobody can sign in as an administrator.** Grant the role with SQL: `INSERT IGNORE INTO role_staff (staff_id, role_code) SELECT id, 'admin' FROM staff WHERE employee_no = 'ADM-001' AND is_active = 1;` — or reset that account's password with `make-accounts.php --reset`.

**A credentials sheet was left on the server or shared.** Reset every account on it (`make-accounts.php --reset` with each employee number), import the reset file, and delete every copy of the old sheet. The reset signs those accounts out everywhere.
