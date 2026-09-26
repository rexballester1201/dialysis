<?php

declare(strict_types=1);

/*
 * The documentation set: what is in it, in what order, and each document's
 * control block. docs/build.php reads this; nothing else does.
 *
 * Changing a document's substance means a new issue: bump `version`, set
 * `issued`, and say what changed in the document itself.
 *
 * `source` is relative to the repository root. `h1` and `standfirst` default
 * to what the source itself carries (a Markdown source's `# ` heading, an HTML
 * source's <h1> and <p class="standfirst">). `numbered` numbers the h2
 * sections; `depth` 3 puts h3s in the contents too. `link_base` rewrites a
 * source's relative links when it lives outside docs/. `lift_standfirst` makes a
 * Markdown source's opening paragraph the page's standfirst.
 */

$issued = '2026-09-26';

return [
    'set' => [
        'system' => 'Dialysis Centre System',
        'label' => 'Documentation · Issue 1',
        'fonts' => 'https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Libre+Franklin:ital,wght@0,400;0,500;0,600;1,400&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;0,6..72,600;1,6..72,400&display=swap',
    ],

    'groups' => [
        ['label' => 'Start', 'docs' => ['index.html']],
        ['label' => 'Understand it', 'docs' => ['design-spec.html', 'technical-spec.html']],
        ['label' => 'Run it', 'docs' => ['users-guide.html', 'admin-guide.html', 'troubleshooting.html']],
        ['label' => 'Install it', 'docs' => ['deployment-guide.html']],
        ['label' => 'Decide and take over', 'docs' => ['marketing.html', 'handover.html']],
    ],

    'docs' => [
        [
            'file' => 'index.html',
            'ref' => 'Index',
            'short' => 'Start here',
            'title' => 'Documentation',
            'h1' => 'How this system is documented',
            'standfirst' => 'Nine documents for one haemodialysis unit\'s clinical system: what it is for, how it is built, how to use it, run it, install it, fix it, judge it and take it over. <strong>Start with the rule below; everything else follows from it.</strong>',
            'description' => 'Index of the Dialysis Centre System documentation set, the one rule the system is built around, and how to rebuild the set.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'Everyone who opens the set: staff, administrators, developers, owners.',
            'applies_to' => 'The system as it stood on 26 September 2026.',
            'source' => 'docs/src/index.md',
            'numbered' => true,
        ],
        [
            'file' => 'design-spec.html',
            'ref' => 'DS·1',
            'short' => 'Design specification',
            'title' => 'Design specification',
            'h1' => 'What the system is for, and what it refuses',
            'standfirst' => 'The behaviour the system was designed to have and has today: its scope, the people it serves, the objects it keeps, the day it follows and the decisions that shaped it.',
            'description' => 'Scope, roles, principles, domain objects, workflow, screens and design decisions of the Dialysis Centre System.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'The clinical lead, whoever decides what the system should do next, and developers before they change behaviour.',
            'applies_to' => 'Behaviour as built on 26 September 2026: the API, the bedside app and the console.',
            'source' => 'docs/src/design-spec.md',
            'numbered' => true,
            'depth' => 3,
        ],
        [
            'file' => 'technical-spec.html',
            'ref' => 'TS·1',
            'short' => 'Technical specification',
            'title' => 'Technical specification',
            'h1' => 'How it is built',
            'standfirst' => 'The stack, a request followed from the network to the database and back, the API contract, the rule engine and its order of checks, the data model and its constraints, the clients, security, tests, and the traps.',
            'description' => 'Stack, request path, API contract, rule engine, data model, modules, front end, security, tests and conventions of the Dialysis Centre System.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'Developers maintaining or extending the code.',
            'applies_to' => 'apps/api (Laravel 12.67), apps/bedside, apps/console and packages/*, as of 26 September 2026.',
            'source' => 'docs/src/technical-spec.md',
            'numbered' => true,
            'depth' => 3,
        ],
        [
            'file' => 'users-guide.html',
            'ref' => 'UG·1',
            'short' => 'User\'s guide',
            'title' => 'User\'s guide',
            'description' => 'The dialysis day: what the system expects at each step, where it stops you and why. For ward staff.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'Ward staff — nurses, head nurses, technicians, nephrologists, billing — and whoever trains them.',
            'applies_to' => 'Ward use of the bedside tablet and the console, as built on 26 September 2026.',
            'source' => 'docs/src/users-guide.html',
            'numbered' => false,
        ],
        [
            'file' => 'admin-guide.html',
            'ref' => 'AG·1',
            'short' => 'Administrator\'s guide',
            'title' => 'Administrator\'s guide',
            'h1' => 'Running the system day to day',
            'standfirst' => 'People and roles, the settings and why some cannot be changed from the screen, what runs on a timer, the audit trail, backups, the routine, and every command an administrator needs.',
            'description' => 'Accounts and roles, settings, scheduled jobs, imports, audit trail, backups, routine and command reference for the Dialysis Centre System.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'The unit\'s system administrator.',
            'applies_to' => 'A live installation, deployed from deploy/ on shared hosting or from source.',
            'source' => 'docs/src/admin-guide.md',
            'numbered' => true,
            'depth' => 3,
        ],
        [
            'file' => 'troubleshooting.html',
            'ref' => 'TG·1',
            'short' => 'Troubleshooting',
            'title' => 'Troubleshooting guide',
            'h1' => 'When something is wrong',
            'standfirst' => 'Where the answers are kept, what each symptom means and what to do about it — area by area — and what to do when the data itself is wrong.',
            'description' => 'Symptoms, causes and actions by area, the four places answers are kept, wrong-data procedure and worst-case recovery for the Dialysis Centre System.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'The administrator or developer on call.',
            'applies_to' => 'A live installation, and a development copy.',
            'source' => 'docs/src/troubleshooting.md',
            'numbered' => true,
            'depth' => 3,
        ],
        [
            'file' => 'deployment-guide.html',
            'ref' => 'DG·1',
            'short' => 'Deployment guide',
            'title' => 'Deployment guide',
            'lift_standfirst' => true,
            'description' => 'Requirements, sizing, installation on cPanel without a shell, pre-handover verification, hardening, upgrade, rollback and moving hosts.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'Whoever installs, upgrades or moves the system — IT support with cPanel access.',
            'applies_to' => 'Shared hosting with cPanel and no shell, MySQL 8.0.16 or newer; notes for hosts with a shell.',
            'source' => 'deploy/DEPLOYMENT.md',
            'link_base' => '../deploy/',
            'numbered' => false,
            'depth' => 2,
        ],
        [
            'file' => 'marketing.html',
            'ref' => 'MK·1',
            'short' => 'Product overview',
            'title' => 'Product overview',
            'h1' => 'A clinical system that refuses before it records',
            'standfirst' => 'What it does for a haemodialysis unit, why its records can be trusted, what it costs to run, what it does not do, and who it suits.',
            'description' => 'What the Dialysis Centre System does, why it can be trusted, running costs, honest limits and who it suits.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'Clinic owners, medical directors and managers deciding whether to adopt it.',
            'applies_to' => 'What exists on 26 September 2026 — not a roadmap.',
            'source' => 'docs/src/marketing.md',
            'numbered' => true,
        ],
        [
            'file' => 'handover.html',
            'ref' => 'HO·1',
            'short' => 'Handover',
            'title' => 'Handover',
            'h1' => 'Taking over this system',
            'standfirst' => 'What to read first, where everything is, the four rules not to break and what breaks if you do, what runs without you, your first week, and how to change it safely.',
            'description' => 'Reading order, map, rules not to break, unattended jobs, week-one checklist, safe change process, surprises, known limits and handover checklist.',
            'version' => 'Issue 1',
            'issued' => $issued,
            'written_for' => 'The developer or team taking over maintenance.',
            'applies_to' => 'The repository as handed over on 26 September 2026 — not yet under version control.',
            'source' => 'docs/src/handover.md',
            'numbered' => true,
            'depth' => 3,
        ],
    ],
];
