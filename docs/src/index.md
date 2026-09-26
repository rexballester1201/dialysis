## The rule everything follows {#the-rule}

:::rule The central rule
Every rule that protects a patient or a bill is checked at the moment it can still prevent harm, by the one service that owns it — and checked again by the database underneath. When a rule has to bend, it bends only through a written reason that stays on the record.
:::

Real patients are dialysed against this data. A refused save with a clear reason costs a nurse a minute; a saved record with a quietly wrong number is believed for years. Every design choice in these documents comes back to that trade. In practice the rule means five things:

- **Before, not after.** The water, the chair and machine cohort, and the dialyzer are checked at check-in and at the start of treatment — before the needle goes in. Those steps need a network connection for exactly that reason: a refusal that arrived at sync time, after the patient was dialysed, would protect nobody.
- **One owner per rule.** Each rule lives in one service on the server. The console, the bedside tablet and its offline sync all pass through that service, so there is no second copy of the rule to drift. [DS·1](design-spec.html#principles) defines the terms; [TS·1](technical-spec.html#engine) lists every rule with its owner.
- **Checked twice.** Behind each service sits a database backstop — a trigger, a key, or a view that is read twice a day — so a console command or a stray script meets the same rule the screen does.
- **The server works out what it can.** Kt/V, URR, weight gain, treatment duration, invoice totals and stock balances are computed on the server from their inputs. A number sent by a device is not trusted. The one gap in this is recorded in [TS·1](technical-spec.html#known-defects).
- **One exception, written down.** Infection control is the only rule with an override, because a unit whose only hepatitis-B chair is broken still has to dialyse the patient in front of it. The override needs a reason of at least ten characters, is written onto the chart as an *INFECTION CONTROL OVERRIDE* note and into the audit log, and is echoed back so that no screen can succeed quietly.

## The nine documents {#documents}

| Ref | Document | Written for | Open it when |
|---|---|---|---|
| Index | This page | Everyone | You need to know where something is written |
| [DS·1](design-spec.html) | Design specification | Clinical lead, product owner, developers | You want to know what the system is meant to do, or you plan to change what it does |
| [TS·1](technical-spec.html) | Technical specification | Developers | You are about to read or change the code |
| [UG·1](users-guide.html) | User's guide — *The dialysis day* | Ward staff and their trainers | You are learning the treatment day, or teaching it |
| [AG·1](admin-guide.html) | Administrator's guide | The system administrator | You look after accounts, settings, scheduled jobs and backups |
| [TG·1](troubleshooting.html) | Troubleshooting guide | Whoever is on call | Something is refused, broken or wrong |
| [DG·1](deployment-guide.html) | Deployment guide | IT support with cPanel access | You install, upgrade, roll back or move the system |
| [MK·1](marketing.html) | Product overview | Owners, medical directors, managers | You are deciding whether it suits your unit |
| [HO·1](handover.html) | Handover | The next maintainer | You are taking the system over |

## Where to start {#where-to-start}

- **On the ward:** [UG·1](users-guide.html) end to end — it is one page, in the order of the day. Keep [TG·1 §2](troubleshooting.html#sign-in) to hand for the messages you will meet.
- **Administering it:** [AG·1](admin-guide.html) first, then [DG·1](deployment-guide.html) if you installed it, then [TG·1](troubleshooting.html).
- **Developing it:** [HO·1](handover.html), then [TS·1](technical-spec.html), then [DS·1](design-spec.html). In the repository, `CLAUDE.md` holds the rules for changing the code and `technical-design.md` the original reasoning.
- **Deciding about it:** [MK·1](marketing.html), then [DS·1 §2](design-spec.html#scope) for exactly what is and is not built.

## What the set does not contain {#not-in-the-set}

- **An audit.** The set was specified with room for an existing independent review of the code. None exists, so none is included — and nothing in these documents is one. They were written from the code by the people who built it; read them as a description, not as assurance.
- **The earlier FAQ.** [Why It Stopped You](faq.html) was written on 20 August 2026, before the screens existed. It is kept as it was written and sits outside the set; [TG·1](troubleshooting.html) covers the same messages as the system behaves today.
- **The repository's own notes.** `CLAUDE.md`, `technical-design.md`, `README.md` and `deploy/DEPLOYMENT.html` (a one-page install runbook with tick-boxes) stay where they are. The set draws on them and does not replace them.

## The numbers in these documents {#numbers}

Counted from the code, the development database and test runs on 26 September 2026. Where a figure appears in another document, it came from here.

| What | Count | Counted with |
|---|---|---|
| API routes | 80: 37 GET, 37 POST, 3 PATCH, 2 PUT, 1 DELETE | `php artisan route:list --json` |
| Tables | 69: 66 in the baseline schema, 3 added after it | `information_schema` |
| Views and triggers | 12 views, 6 triggers | `information_schema` |
| Constraints | 117 foreign keys, 64 CHECK constraints | `information_schema` |
| Indexes and generated columns | 238 indexes, 16 stored generated columns | `information_schema` |
| Server code | 127 PHP files, 12,501 lines in `apps/api/app` | file count |
| Client code | 44 TypeScript files, 10,448 lines across five workspaces | file count |
| Server tests | 282 Pest tests, 1,309 assertions, 18 files — all passing on MySQL 8.4.9 | `php artisan test` |
| Client tests | 9 Vitest tests (6 clinical maths, 3 for the confirm-dialog sweep) — all passing | `npm test` |
| Database checks | smoke test 5 PASS / 0 FAIL; sync verifier 16 passed / 0 failed | a fresh scratch database |
| Static checks | PHPStan level 8: no errors. Pint: passed. `tsc --noEmit`: five workspaces clean | the tools themselves |

These runs were on the development machine. The continuous-integration workflow in `.github/workflows/ci.yml` has never run, because the project is not yet a git repository.

## How to rebuild the set {#rebuild}

The pages are built, not written. After changing any source, rebuild from the repository root:

```bash
php docs/build.php
```

It needs PHP 8.2 or newer and `apps/api/vendor` in place — run `composer install` in `apps/api` once — because it uses the Markdown library the API already carries. It writes all nine pages, then checks the whole set: every tag closed, every id unique, every link inside a page and between pages pointing at something that exists, no double-escaped characters, a title and doctype on every page. Any failure is listed and the command exits non-zero. To run the checks alone:

```bash
php docs/build.php --check
```

| What | Where it lives |
|---|---|
| The set's order, groups and every control block | `docs/src/manifest.php` |
| The stylesheet, inlined into every page | `docs/src/style.css` |
| Index, DS·1, TS·1, AG·1, TG·1, MK·1, HO·1 | `docs/src/<name>.md` |
| UG·1 | `docs/src/users-guide.html` — the original guide's own HTML |
| DG·1 | `deploy/DEPLOYMENT.md` — the same file the installer reads |

:::stop Do not edit the HTML by hand
A change made to a built page is overwritten the next time anyone builds. Until then, the page and its source disagree, and nobody can tell which one is right. Change the source, rebuild, and move the document to a new issue in the manifest if its substance changed.
:::

Two conventions in the Markdown sources: a callout is a block that opens with three colons and a kind — `:::stop`, `:::caution`, `:::note` or `:::rule`, optionally followed by a label — and closes with three colons on a line of their own; and `{#name}` at the end of a heading gives it a fixed anchor that other documents can link to.

## About this set {#colophon}

- **Issues.** Each document carries its own issue number and date. The date is the day its text was last checked against the code. A change of substance means a new issue, recorded in the manifest and described in the document.
- **Design.** The set uses the palette the console and the bedside app already share: ward paper `#F7F8F7`, chart ink `#16221F`, circuit teal `#0E6B5E` as the one accent, hairlines `#DDE3E0`, and stop red `#9C2F26` and caution amber `#9A6410` kept for their meanings — *refused* and *tells you*. Titles are set in Newsreader, text in Libre Franklin, references and code in IBM Plex Mono. Pages follow the device's light or dark setting.
- **Offline.** Every page opens from disk with no server and no scripts. The typefaces load from Google Fonts when there is a connection; without one, the pages fall back to the system's own faces and read the same.
- **Print.** Pages print on A4. The spine and the contents are left out, tables and callouts avoid breaking across pages, and each page carries the document reference, issue and page number in its footer — Chrome and Edge print these running footers; other browsers print the document without them, and the reference still heads the first page and closes the last.
