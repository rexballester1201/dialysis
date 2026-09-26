<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pre-flight check
|--------------------------------------------------------------------------
|
| A shell is the normal way to answer "will this host actually run the
| system?". Without one, this page answers the same questions from a browser.
|
| It is a temporary diagnostic, not part of the application:
|
|   1. Set SECRET below to something long and random.
|   2. Upload to the same directory as index.php (your document root).
|   3. Visit  https://your-domain/preflight.php?key=THE-SECRET
|   4. DELETE IT. It reports server internals and has no business
|      staying on a host that holds patient records.
|
| It never prints a password, a token, or a patient's data.
|
*/

const SECRET = 'CHANGE-ME-BEFORE-UPLOADING';

/*
 * Deliberately a length test rather than a comparison against the placeholder
 * text: setting the secret with a find-and-replace across the file is the
 * obvious way to do it, and that would rewrite the comparison too, leaving a
 * guard that can never pass. A short secret is worthless anyway.
 */
if (strlen(SECRET) < 20) {
    http_response_code(500);
    exit('Edit preflight.php and set SECRET to a random string of at least 20 characters.');
}

if (($_GET['key'] ?? '') !== SECRET) {
    http_response_code(404);
    exit('Not found.');
}

/** @var list<array{0:string,1:string,2:string,3:string}> $results  [state, area, finding, what it means] */
$results = [];

function check(string $area, bool|string $state, string $finding, string $meaning = ''): void
{
    global $results;

    $level = is_string($state) ? $state : ($state ? 'pass' : 'fail');
    $results[] = [$level, $area, $finding, $meaning];
}

/* ---------------------------------------------------------------- PHP -- */

$php = PHP_VERSION;
check(
    'PHP version',
    version_compare($php, '8.2.0', '>=') ? 'pass' : 'fail',
    $php,
    version_compare($php, '8.2.0', '>=')
        ? ''
        : 'composer.json requires PHP 8.2 or newer. Change it in the cPanel PHP Selector.',
);

/*
 * bcmath runs every money calculation in this system. It is not declared in
 * composer.json, so its absence is not caught at install time -- it surfaces as
 * a fatal error the first time somebody prices an invoice.
 */
foreach ([
    'bcmath' => 'Every invoice and claim total. Money is never touched with floats.',
    'pdo_mysql' => 'All database access.',
    'mbstring' => 'Required by Laravel.',
    'openssl' => 'APP_KEY encryption and hashing.',
    'json' => 'Required by Laravel.',
    'fileinfo' => 'Required by Laravel.',
    'ctype' => 'Required by Laravel.',
] as $extension => $why) {
    check("ext-{$extension}", extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'MISSING', extension_loaded($extension) ? '' : $why);
}

/* ------------------------------------------------------------ writable -- */

$root = __DIR__;
// The document root is usually public/, so the app root is one level up.
$appRoot = is_dir($root.'/../storage') ? realpath($root.'/..') : $root;

foreach ([
    'storage/framework/sessions',
    'storage/framework/cache',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
] as $path) {
    $full = $appRoot.'/'.$path;
    $ok = is_dir($full) && is_writable($full);

    check(
        $path,
        $ok,
        is_dir($full) ? ($ok ? 'writable' : 'NOT WRITABLE') : 'MISSING',
        $ok ? '' : 'Create it and set permissions to 0755 (or 0775). Sessions and cache both use the file driver.',
    );
}

/* ----------------------------------------------------------------- env -- */

$envPath = $appRoot.'/.env';
$env = [];

if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\"'");
    }
}

check('.env', is_readable($envPath), is_readable($envPath) ? 'found' : 'MISSING', is_readable($envPath) ? '' : 'Copy deploy/.env.production.example to .env and fill it in.');

$appKey = $env['APP_KEY'] ?? '';
check('APP_KEY', $appKey !== '', $appKey === '' ? 'EMPTY' : 'set', $appKey === '' ? 'Generate one -- see the deployment guide. The API answers without it today, but Laravel expects one, and anything that encrypts will fail without it.' : '');

$debug = strtolower($env['APP_DEBUG'] ?? '');
check(
    'APP_DEBUG',
    $debug === 'false' ? 'pass' : 'fail',
    $debug === '' ? 'not set' : $debug,
    $debug === 'false' ? '' : 'Must be false. With it on, any 500 renders a stack trace containing your database password.',
);

$appEnv = strtolower($env['APP_ENV'] ?? '');
check('APP_ENV', $appEnv === 'production' ? 'pass' : 'warn', $appEnv === '' ? 'not set' : $appEnv, $appEnv === 'production' ? '' : 'Should be production.');

/* ------------------------------------------------------------ database -- */

$pdo = null;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s', $env['DB_HOST'] ?? 'localhost', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? ''),
        $env['DB_USERNAME'] ?? '',
        $env['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    check('Database connection', true, 'connected');
} catch (Throwable $e) {
    // The message can contain the host and user but never the password.
    check('Database connection', false, 'FAILED', 'Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env. cPanel prefixes both the database and the user with your account name.');
}

if ($pdo instanceof PDO) {
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $isMariaDb = stripos($version, 'mariadb') !== false;

    /*
     * This is the one that decides whether the deployment is possible at all.
     *
     * v_monthly_quality uses LEFT JOIN LATERAL, which MariaDB does not support
     * at any version. Joining session_events directly into that aggregate
     * instead multiplies every session row by its event count and inflates
     * every metric on the quality dashboard -- which is why the LATERAL is
     * there and why it cannot simply be removed.
     */
    check(
        'MySQL, not MariaDB',
        $isMariaDb ? 'fail' : 'pass',
        $version,
        $isMariaDb
            ? 'STOP. MariaDB has no LATERAL support, so v_monthly_quality cannot be created. This schema needs MySQL 8.0.16 or newer. Ask the host to move you to a MySQL server.'
            : '',
    );

    /*
     * 8.0.16, not 8.0.14. LATERAL arrived in 8.0.14, but before 8.0.16 MySQL
     * parses CHECK constraints and silently ignores them -- the schema would
     * import cleanly with all 64 of them doing nothing.
     */
    if (! $isMariaDb) {
        check(
            'MySQL >= 8.0.16',
            version_compare($version, '8.0.16', '>=') ? 'pass' : 'fail',
            $version,
            version_compare($version, '8.0.16', '>=') ? '' : 'Before 8.0.16 MySQL ignores CHECK constraints, and LATERAL needs 8.0.14. Ask the host for a newer server.',
        );
    }

    // Prove it rather than infer it from a version string. A temporary table
    // lives only for this connection and disappears when the page finishes.
    try {
        $pdo->exec('CREATE TEMPORARY TABLE preflight_check_probe (n INT, CONSTRAINT preflight_ck CHECK (n > 0))');

        try {
            $pdo->exec('INSERT INTO preflight_check_probe (n) VALUES (0)');
            check('CHECK constraints enforced', false, 'IGNORED', 'This server accepts rows that break a CHECK constraint, so the schema\'s 64 constraints would do nothing.');
        } catch (Throwable $e) {
            check('CHECK constraints enforced', true, 'enforced');
        }

        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS preflight_check_probe');
    } catch (Throwable $e) {
        check('CHECK constraints enforced', 'warn', 'not tested', 'The database user could not create a temporary table to test with.');
    }

    // Prove it rather than infer it from a version string.
    try {
        $pdo->query('SELECT x.n FROM (SELECT 1 AS a) t, LATERAL (SELECT t.a AS n) x')->fetchColumn();
        check('LATERAL join', true, 'supported');
    } catch (Throwable $e) {
        check('LATERAL join', false, 'NOT SUPPORTED', 'v_monthly_quality will not import. This is the blocker described above.');
    }

    try {
        // Order by a named expression: MySQL rejects a positional ORDER BY
        // inside a window, so `OVER (ORDER BY 1)` fails on a server that
        // supports window functions perfectly well.
        $pdo->query('SELECT ROW_NUMBER() OVER (ORDER BY t.n) FROM (SELECT 1 AS n) t')->fetchColumn();
        check('Window functions', true, 'supported');
    } catch (Throwable $e) {
        check('Window functions', false, 'NOT SUPPORTED', 'Several views need them.');
    }

    // What is actually in the database right now.
    $schema = $env['DB_DATABASE'] ?? '';
    $count = static function (PDO $pdo, string $sql, string $schema): int {
        $statement = $pdo->prepare($sql);
        $statement->execute([$schema]);

        return (int) $statement->fetchColumn();
    };

    $tables = $count($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'", $schema);
    $views = $count($pdo, 'SELECT COUNT(*) FROM information_schema.views WHERE table_schema = ?', $schema);
    $triggers = $count($pdo, 'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = ?', $schema);

    check('Tables', $tables >= 69 ? 'pass' : ($tables === 0 ? 'warn' : 'fail'), (string) $tables, $tables === 0 ? 'Nothing imported yet. Import the three SQL files.' : ($tables >= 69 ? '' : 'Expected 69. An import may have stopped part-way.'));
    check('Views', $views === 12 ? 'pass' : ($views === 0 ? 'warn' : 'fail'), (string) $views, $views === 12 || $views === 0 ? '' : 'Expected 12.');
    check('Triggers', $triggers === 6 ? 'pass' : ($triggers === 0 ? 'warn' : 'fail'), (string) $triggers, $triggers === 6 || $triggers === 0 ? '' : 'Expected 6. Without them the database-level invariant backstops are gone.');

    $sanctum = $count($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'personal_access_tokens'", $schema);
    check('Sanctum token table', $sanctum === 1, $sanctum === 1 ? 'present' : 'MISSING', $sanctum === 1 ? '' : 'Import 02-post-baseline.sql. Without this table nobody can sign in.');

    /*
     * Two things that make the difference between an installed system and a
     * usable one. Both are easy to forget, and both fail in a way that looks
     * like a broken deployment rather than an unfinished one.
     */
    if ($tables > 0) {
        try {
            $admins = (int) $pdo->query(
                "SELECT COUNT(*) FROM role_staff WHERE role_code = 'admin'"
            )->fetchColumn();

            check(
                'Administrator exists',
                $admins > 0,
                $admins > 0 ? "{$admins} on file" : 'NONE',
                $admins > 0 ? '' : 'Nobody can open Settings or grant roles. Import sql/04-accounts.sql -- step 8 of the deployment guide.',
            );

            $withPassword = (int) $pdo->query(
                'SELECT COUNT(*) FROM staff WHERE password IS NOT NULL AND deleted_at IS NULL'
            )->fetchColumn();

            check(
                'Staff who can sign in',
                $withPassword > 0,
                (string) $withPassword,
                $withPassword > 0 ? '' : 'No staff row has a password, so nobody can log in to either client. Import sql/04-accounts.sql (step 8).',
            );

            /*
             * The starter accounts ship with role names in place of people's
             * names. The name on an account is the name printed on every record
             * it signs, so a countersignature by "Attending Nephrologist" is a
             * record nobody can attribute. Matched on the exact pair, so an
             * account renamed to its real holder stops matching.
             */
            $placeholders = [
                'ADM-001' => 'Site Administrator', 'ENC-001' => 'Records Encoder', 'BIL-001' => 'Billing Officer',
                'TEC-001' => 'Renal Technician', 'HN-001' => 'Head Nurse', 'RN-001' => 'Dialysis Nurse',
                'NEP-001' => 'Attending Nephrologist',
            ];
            $still = [];

            foreach ($pdo->query('SELECT employee_no, full_name FROM staff WHERE deleted_at IS NULL AND is_active = 1') as $row) {
                if (($placeholders[$row['employee_no']] ?? null) === $row['full_name']) {
                    $still[] = $row['employee_no'];
                }
            }

            check(
                'Starter accounts renamed',
                $still === [] ? 'pass' : 'warn',
                $still === [] ? 'none left' : implode(', ', $still),
                $still === [] ? '' : 'Still carry a role for a name. Every record these sign shows that name -- rename each to the one person who holds it (step 8) before anyone is treated.',
            );

            /*
             * The unit's day is counted in this zone: which day a water check
             * clears, the date the board and tablets open on, when stock
             * expires. With no facility row the app refuses to guess and every
             * one of those fails. The finding shows the unit's own clock, so
             * whoever runs this can see at a glance that it matches the wall.
             */
            $zone = $pdo->query('SELECT timezone FROM facilities WHERE id = 1')->fetchColumn();
            $zoneOk = false;
            $zoneNow = '';

            if (is_string($zone) && $zone !== '') {
                try {
                    $zoneNow = (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('H:i \o\n Y-m-d');
                    $zoneOk = true;
                } catch (Throwable $e) {
                    $zoneOk = false;
                }
            }

            check(
                'Unit timezone',
                $zoneOk,
                $zoneOk ? "{$zone} -- {$zoneNow} there" : (is_string($zone) && $zone !== '' ? "'{$zone}' is not a timezone" : 'NOT SET'),
                $zoneOk
                    ? 'Check that time against the unit\'s wall clock. If it is wrong, set the timezone in Settings -- it decides when the unit\'s day starts.'
                    : 'The facility row is missing or its timezone is invalid, so the system cannot tell which day it is. Import 03-seed.sql, then set it in Settings.',
            );

            // A water check is recorded against a system. With none on file the
            // Water screen cannot record one, and invariant 9 refuses the day's
            // first treatment for ever.
            $systems = (int) $pdo->query('SELECT COUNT(*) FROM water_systems WHERE is_active = 1')->fetchColumn();

            check(
                'Water system on file',
                $systems > 0,
                $systems > 0 ? "{$systems} active" : 'NONE',
                $systems > 0 ? '' : 'Without one, no water check can be recorded and no treatment can start. Import 03-seed.sql, which adds one.',
            );
        } catch (Throwable $e) {
            check('Administrator exists', 'warn', 'could not check', 'The staff or role_staff table is missing — check the import.');
        }
    }
}

/* ---------------------------------------------------- credentials sheet -- */

/*
 * The sheets printed by deploy/accounts/make-accounts.php -- .txt and the
 * printable .html -- hold plaintext passwords and PINs. It is meant to stay on the computer that generated it,
 * so finding one on the server is a failure, not a warning.
 */
$sheets = array_merge(
    glob($root.'/CREDENTIALS*') ?: [],
    glob($appRoot.'/CREDENTIALS*') ?: [],
    glob($appRoot.'/deploy/accounts/CREDENTIALS*') ?: [],
    glob($appRoot.'/deploy/accounts/out/CREDENTIALS*') ?: [],
);

check(
    'Credentials sheet not on server',
    $sheets === [],
    $sheets === [] ? 'none found' : count($sheets).' FOUND',
    $sheets === [] ? '' : 'A file of plaintext passwords is on this server. Delete it now, and treat every password on it as exposed.',
);

/* ------------------------------------------------------------- rewrite -- */

check(
    'mod_rewrite',
    function_exists('apache_get_modules')
        ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'pass' : 'fail')
        : 'warn',
    function_exists('apache_get_modules')
        ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'enabled' : 'NOT ENABLED')
        : 'cannot tell from PHP',
    function_exists('apache_get_modules') && ! in_array('mod_rewrite', apache_get_modules(), true)
        ? 'Every URL except / will 404. public/.htaccess depends on it.'
        : 'If /api/v1/health returns 404 but / works, this is why.',
);

$failed = count(array_filter($results, static fn (array $r): bool => $r[0] === 'fail'));
$warned = count(array_filter($results, static fn (array $r): bool => $r[0] === 'warn'));

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pre-flight — Dialysis Centre</title>
<style>
  :root { --ink:#16221f; --muted:#5c6b67; --rule:#d7dfdc; --paper:#f4f6f5; --surface:#fff;
          --ok:#2f6b3a; --okbg:#e7f0e8; --bad:#9c2f26; --badbg:#f7e9e7; --warn:#9a6410; --warnbg:#f8efde; }
  * { box-sizing:border-box }
  body { margin:0; background:var(--paper); color:var(--ink);
         font:15px/1.55 system-ui,-apple-system,'Segoe UI',sans-serif; }
  .wrap { max-width:900px; margin:0 auto; padding:32px 20px 60px }
  h1 { font-size:1.5rem; margin:0 0 4px }
  p.sub { color:var(--muted); margin:0 0 22px }
  .verdict { padding:14px 16px; border-radius:5px; font-weight:600; margin-bottom:20px }
  .verdict.ok { background:var(--okbg); color:var(--ok) }
  .verdict.bad { background:var(--badbg); color:var(--bad) }
  .verdict.warn { background:var(--warnbg); color:var(--warn) }
  table { width:100%; border-collapse:collapse; background:var(--surface);
          border:1px solid var(--rule); border-radius:5px; overflow:hidden }
  th,td { text-align:left; padding:9px 13px; border-bottom:1px solid var(--rule); vertical-align:top }
  th { font-size:.7rem; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); background:var(--paper) }
  tr:last-child td { border-bottom:none }
  .tag { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.72rem; font-weight:700 }
  .pass { background:var(--okbg); color:var(--ok) }
  .fail { background:var(--badbg); color:var(--bad) }
  .warn { background:var(--warnbg); color:var(--warn) }
  td.mean { color:var(--muted); font-size:.9rem }
  footer { margin-top:24px; padding-top:16px; border-top:1px solid var(--rule); color:var(--muted); font-size:.88rem }
</style>
</head>
<body>
<div class="wrap">
  <h1>Pre-flight</h1>
  <p class="sub">Dialysis Centre Management System — host readiness.</p>

  <?php if ($failed > 0): ?>
    <div class="verdict bad"><?= $failed ?> blocker<?= $failed === 1 ? '' : 's' ?>. Do not go live until each is resolved.</div>
  <?php elseif ($warned > 0): ?>
    <div class="verdict warn">No blockers. <?= $warned ?> item<?= $warned === 1 ? '' : 's' ?> to look at.</div>
  <?php else: ?>
    <div class="verdict ok">All checks passed.</div>
  <?php endif; ?>

  <table>
    <thead><tr><th>Check</th><th>Result</th><th>Found</th><th>What it means</th></tr></thead>
    <tbody>
    <?php foreach ($results as [$level, $area, $finding, $meaning]): ?>
      <tr>
        <td><strong><?= htmlspecialchars($area, ENT_QUOTES) ?></strong></td>
        <td><span class="tag <?= $level ?>"><?= strtoupper($level) ?></span></td>
        <td><?= htmlspecialchars($finding, ENT_QUOTES) ?></td>
        <td class="mean"><?= htmlspecialchars($meaning, ENT_QUOTES) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <footer>
    Delete this file once you are done. It reports server internals and should not
    remain on a host holding patient records.
  </footer>
</div>
</body>
</html>
