## What it does {#what-it-does}

A clinical and billing system for **one in-centre haemodialysis unit**, built around the treatment day: the morning water test, the chairs, the machines, the flow sheet at the chair, the signed record, and the claim that follows it. It is seeded for a Philippine unit with PhilHealth as the main payer.

| For | It does | What that is worth |
|---|---|---|
| The technician | Records the morning total-chlorine test, and holds the day's first treatment until a passing one exists | Chlorine that passes the carbon beds is found before it reaches a patient, not on the monthly report |
| The head nurse | Builds each day's board from the patients' standing patterns; refuses a second patient in a chair already booked for that shift | Nobody rebuilds the roster by hand, and double-bookings are refused when they are made, not found at the chair |
| The nurse at the chair | Check-in, start and end on a tablet, with the infection-control, machine and dialyzer checks at each step; a flow sheet that keeps working when the network drops | A hepatitis-B patient is not seated in a clean chair by mistake; four hours of charting survive a dead Wi-Fi router |
| The nephrologist | Countersigns the record, which then locks; amends it only with a written reason; sees adequacy computed from the blood results, flagged when below target | The signed record is the account of what happened, and a Kt/V on it agrees with its own blood results |
| Billing | Generates claims from signed treatments only, counts each patient's annual allotment, follows the payer's decisions, and invoices what remains | No session is billed twice; nobody finds out in November that a patient's cover ran out in September |
| The administrator | Settings with their consequences spelled out; a twice-daily sweep for anything that got past the checks | The rules stay configured the way the room actually is |
| The unit, to a regulator | Every change to a clinical record, every chart opened and every sign-in attempt, kept | "Who looked at this patient's record?" has an answer |

## Why its records can be trusted {#trust}

One rule runs through all of it: **every rule that protects a patient or a bill is checked at the moment it can still prevent harm, by the one part of the system that owns it — and checked again by the database underneath. When a rule has to bend, it bends only through a written reason that stays on the record.**

What that looks like in use:

- **It refuses before the needle, not after.** The water, the chair, the machine and the dialyzer are checked when the patient is checked in and when treatment starts. That is why those steps need a network connection: a refusal that arrived hours later, when the tablet synced, would protect nobody.
- **It will not store a number it can work out itself** from the raw readings — adequacy, weight gain, treatment time, invoice totals, stock balances. A chart can only hold a figure that agrees with its own inputs.
- **A signed record cannot be edited.** It can be amended by a nephrologist with a reason; the original values, the reason and who made the change are all kept.
- **It has one override, and it shows.** Infection control can be overridden when there is genuinely no compliant chair — with a written reason that goes onto the chart and the audit log. Nothing else can be overridden.
- **It does not guess.** A record missing a weight cannot be signed; the refusal says which field. A blocked save costs a minute; a wrong record is believed for years.
- **Rates are dated, not built in.** The PhilHealth case rate moved ₱2,600 → ₱4,000 → ₱6,350 and the allotment 90 → 156 in two years. Rates are kept as dated entries, so an old claim still prices at the rate that applied then.

The evidence, from the development machine on 26 September 2026: **282 automated server tests (1,309 checks) passing on MySQL**, five database-level assertions passing on a fresh database, all sixteen checks of the offline-sync protocol passing, and no errors from static analysis (PHPStan level 8). Both apps were driven through their workflows by hand during development, as the repository's notes record; that was not repeated for this document.

What the evidence is not: the automated pipeline that would re-run those checks on every change has never run, there are no automated tests of the screens, and **no independent audit, security review or regulatory certification has been done.** The documents in this set were written by the people who built it.

## What it costs to run {#cost}

The software has no per-seat or per-patient fee built into it. What a unit pays for is the ground it stands on and the people who look after it.

| Item | What is needed | Notes |
|---|---|---|
| Software licences | None to buy. Built on Laravel and React (MIT licence) and MySQL Community Edition | The system itself is published under the MIT licence (`LICENSE`); `THIRD-PARTY-NOTICES.md` lists the parts that are other people's work |
| Hosting | A web host with PHP 8.2 or newer, **MySQL 8.0.16 or newer (not MariaDB)**, HTTPS, cron, and about 160 MB of disk for the application before any data | Runs on ordinary cPanel shared hosting with no shell access; the full requirements are in [DG·1](deployment-guide.html#requirements). Many cheap plans offer only MariaDB — check first |
| Backups | Somewhere off the server to keep database copies for 10–15 years | The system makes none itself ([AG·1 §7](admin-guide.html#backups)) |
| Tablets | One per group of chairs a nurse charts, plus spares; a modern browser | Today every tablet syncs only while the app is open. When the background worker is built, Android browsers will be able to finish syncing in the background and iPads will not — so prefer Android |
| Desk computers | Any machine with a current browser, for the console | |
| People | An administrator a few hours a week, who is comfortable running SQL in phpMyAdmin; someone who can make an API call for the rare jobs without a screen | Staff accounts, password resets and some settings are SQL today |
| Training | The user's guide ([UG·1](users-guide.html)) is the curriculum | Run paper alongside until the unit trusts it. The original design asked for a week of paperless shifts, including a deliberate network cut, before relying on the tablets |

## What it does not do {#limits}

Said plainly, so it is not discovered after go-live.

- **One unit only.** No multi-site, no group reporting.
- **Haemodialysis only.** No peritoneal dialysis workflow.
- **No staff management screens.** Accounts, passwords and PINs are created with SQL; nobody can change their own password in the app.
- **Some work the system does has no screen yet:** recording serology, changing a patient's status or details, writing prescriptions, setting standing patterns, giving medications, amending a signed record, machine and dialyzer logs, stock, and the quality and utilisation reports. Each exists behind the API and needs a technical person until its screen is built.
- **No one-off scheduling.** Add-on sessions, reschedules and cancelled days have no screen or endpoint.
- **No appeals.** A denied claim cannot be corrected and resubmitted as a new one inside the system.
- **No senior-citizen or PWD discount** yet.
- **No integrations.** No electronic claim submission, lab interface, scales, dialysis-machine link or patient portal.
- **The tablet must be online** to check in, start or end a treatment, and it cannot start from cold without a network. Charting itself works offline.
- **The twice-daily safety sweep runs on the server's UTC clock** — 14:00 and 02:00 in Manila — so someone checks the Controls screen each morning instead.
- **No independent assurance** of any kind, as above.

## Who it suits, and who it does not {#fit}

| It suits | It does not suit |
|---|---|
| A single in-centre haemodialysis unit of roughly 10 to 40 chairs | A group of units wanting one system across sites |
| A Philippine unit billing PhilHealth, or one prepared to enter its own payers and rates | A unit that needs Malaysian, Indonesian or Singaporean payer workflows ready on day one — the data model allows them, the reference data does not carry them |
| A unit that dialyses on ordinary Wi-Fi and wants charting to survive outages | A hospital that needs HL7 interfaces to an existing electronic record |
| A unit that can run a full day on paper when it must, and back-enter afterwards | A unit that cannot operate at all without the system |
| A unit with an administrator willing to run SQL for accounts and settings | A unit with no technical person at all |
| A unit that wants its rules enforced and its exceptions written down | A unit that wants a peritoneal dialysis programme in the same system |
| A host offering MySQL 8 | A host offering only MariaDB |

## What adopting it involves {#adopting}

1. **Check the host** — MySQL, not MariaDB, before anything else ([DG·1](deployment-guide.html)).
2. **Install it** from the deployment bundle, with no shell needed.
3. **Name every account** after the one person who will hold it, and hand out sign-in slips in person.
4. **Configure the room**: chair cohorts, high-alert drugs, the timezone, the current benefit rate — and check the clinical thresholds against your own SOPs.
5. **Train on the day** with [UG·1](users-guide.html), and run paper alongside until the unit trusts it.
6. **Arrange backups** and prove one restores before the first real patient.
