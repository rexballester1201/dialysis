<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Staff accounts, as SQL you can import through phpMyAdmin
|--------------------------------------------------------------------------
|
| The application has no screen that creates a staff member, sets a password
| or sets a bedside PIN -- Settings can only grant roles to someone who is
| already on file. On a host with no shell, SQL is the only way an account
| comes into existence. This script writes that SQL.
|
| Run it on your own computer (anything with PHP 8.1+), never on the server:
|
|   php deploy/accounts/make-accounts.php
|       Every row of accounts.csv  ->  deploy/sql/04-accounts.sql
|                                      deploy/accounts/CREDENTIALS.txt
|
|   php deploy/accounts/make-accounts.php RN-002 RN-003
|       Only those rows (staff joining later)
|                                  ->  deploy/accounts/out/add-accounts-<time>.sql
|                                      deploy/accounts/out/CREDENTIALS-<time>.txt
|
|   php deploy/accounts/make-accounts.php --reset RN-001
|       A forgotten password, or one that may have been seen. New password,
|       new PIN if the account has one, lockout cleared, every device signed
|       out.                       ->  deploy/accounts/out/reset-<time>.sql
|                                      deploy/accounts/out/CREDENTIALS-reset-<time>.txt
|
| What the SQL does, and does not do:
|
|   - It creates an account only if no account has that employee number (or
|     email). It never updates one. Importing the same file twice is harmless,
|     and importing it over an existing installation can never reset anybody's
|     password to the one printed on a credentials sheet.
|   - Passwords and PINs are random, bcrypt-hashed here (cost 12, what Laravel
|     uses), and only the hashes go into the SQL. The plaintext goes into the
|     credentials sheet, which must never be uploaded anywhere.
|   - Everything runs in one transaction, so a failed import leaves nothing
|     half-created.
|
| Each account belongs to ONE person. The name on it is the name on every
| record it signs, and the high-alert witness check (invariant 7) only means
| something if two logins are two people.
|
*/

const KNOWN_ROLES = [
    // Mirrors the roles in deploy/sql/03-seed.sql. role_staff has a foreign key
    // to roles, so an unknown code would fail the import anyway -- this says so
    // before anything is written.
    'admin', 'nephrologist', 'head_nurse', 'nurse', 'technician', 'billing', 'records', 'dietitian', 'readonly',
];

/** Roles no policy in the API checks: an account holding only these can sign in and do nothing. */
const INERT_ROLES = ['dietitian', 'readonly'];

/** Roles that chart at the chair, so they need a PIN for the bedside tablet's unlock screen. */
const BEDSIDE_ROLES = ['nurse', 'head_nurse', 'nephrologist'];

const COLUMNS = ['employee_no', 'first_name', 'last_name', 'email', 'roles', 'licence_no'];

$here = __DIR__;
$args = array_values(array_filter(array_slice($argv, 1), fn (string $a): bool => ! in_array($a, ['--force', '--reset', '--render-sheet'], true)));
$force = in_array('--force', $argv, true);

// A printable HTML copy of a sheet that already exists, passwords untouched.
if (in_array('--render-sheet', $argv, true)) {
    if (count($args) !== 1 || ! is_file($args[0])) {
        fail(['Name the .txt sheet to render, e.g.  php deploy/accounts/make-accounts.php --render-sheet deploy/accounts/CREDENTIALS.txt']);
    }

    echo 'Printable copy:  '.relative(renderSheetHtml($args[0])).'   <- keep off the server'.PHP_EOL;

    exit(0);
}

if (in_array('--reset', $argv, true)) {
    resetPasswords($args, $here);

    exit(0);
}

$rows = readCsv("{$here}/accounts.csv");

if ($args !== []) {
    $wanted = array_map('strtoupper', $args);
    $rows = array_values(array_filter($rows, fn (array $r): bool => in_array(strtoupper($r['employee_no']), $wanted, true)));
    $missing = array_diff($wanted, array_map(fn (array $r): string => strtoupper($r['employee_no']), $rows));

    if ($missing !== []) {
        fail(['Not in accounts.csv: '.implode(', ', $missing).'. Add the row first, then run this again.']);
    }

    $stamp = gmdate('Ymd-His');
    @mkdir("{$here}/out", 0700, true);
    $sqlPath = "{$here}/out/add-accounts-{$stamp}.sql";
    $sheetPath = "{$here}/out/CREDENTIALS-{$stamp}.txt";
} else {
    $sqlPath = dirname($here).'/sql/04-accounts.sql';
    $sheetPath = "{$here}/CREDENTIALS.txt";

    // The sheet is the only copy of these passwords. Overwriting it after the
    // SQL has been imported would leave a sheet that matches nothing on the
    // server, because the import skips accounts that already exist.
    if (! $force && (is_file($sqlPath) || is_file($sheetPath))) {
        fail([
            'deploy/sql/04-accounts.sql or deploy/accounts/CREDENTIALS.txt already exists.',
            'If that SQL has been imported anywhere, its passwords are in the existing sheet -- regenerating',
            'would print new ones the server never received. To add staff, name them instead:',
            '    php deploy/accounts/make-accounts.php RN-002',
            'To regenerate the starter set before anything was imported, add --force.',
        ]);
    }
}

$accounts = [];

foreach ($rows as $row) {
    $roles = explode('|', $row['roles']);
    $needsPin = array_intersect($roles, BEDSIDE_ROLES) !== [];

    $accounts[] = $row + [
        'role_list' => $roles,
        'public_id' => ulid(),
        'password' => password(),
        'pin' => $needsPin ? pin() : null,
    ];
}

file_put_contents($sqlPath, sql($accounts));
file_put_contents($sheetPath, sheet($accounts, basename($sqlPath)));
@chmod($sheetPath, 0600);
$printable = renderSheetHtml($sheetPath);

echo 'Wrote '.count($accounts).' account(s):'.PHP_EOL;
echo '  SQL:          '.relative($sqlPath).PHP_EOL;
echo '  Credentials:  '.relative($sheetPath).'   <- keep off the server; see the note inside'.PHP_EOL;
echo '  Printable:    '.relative($printable).'   <- one slip per person; same rules'.PHP_EOL;

foreach ($accounts as $a) {
    if (array_diff($a['role_list'], INERT_ROLES) === []) {
        echo "  Note: {$a['employee_no']} holds only ".implode(', ', $a['role_list'])
            .', which no screen or endpoint checks. It can sign in and do nothing.'.PHP_EOL;
    }
}

/* ---------------------------------------------------------------------- */

/**
 * New sign-in details for accounts that already exist.
 *
 * The only way a password changes until the app can do it itself. Sets a new
 * password; a new PIN only where the account already had one; clears any
 * lockout; and deletes the account's API tokens, so a tablet or browser that
 * was signed in -- possibly by whoever learned the old password -- is signed
 * out and has to use the new one.
 *
 * @param  list<string>  $employeeNumbers
 */
function resetPasswords(array $employeeNumbers, string $here): void
{
    if ($employeeNumbers === []) {
        fail(['Name the account(s) to reset, e.g.  php deploy/accounts/make-accounts.php --reset RN-001']);
    }

    $bad = array_filter($employeeNumbers, fn (string $no): bool => ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $no));

    if ($bad !== []) {
        fail(['Not an employee number: '.implode(', ', $bad)]);
    }

    $stamp = gmdate('Ymd-His');
    @mkdir("{$here}/out", 0700, true);
    $sqlPath = "{$here}/out/reset-{$stamp}.sql";
    $sheetPath = "{$here}/out/CREDENTIALS-reset-{$stamp}.txt";

    $sql = [
        '-- ============================================================',
        '--  PASSWORD RESET',
        '-- ============================================================',
        '--  Generated by deploy/accounts/make-accounts.php --reset on '.gmdate('Y-m-d H:i').' UTC.',
        '--  For each account: a new password; a new PIN only if it already',
        '--  had one; the lockout cleared; every device signed out.',
        '--',
        '--  phpMyAdmin reports rows affected. Each UPDATE should affect 1.',
        '--  0 means no active account has that employee number -- nothing',
        '--  was changed for it, and its line on the sheet is meaningless.',
        '-- ============================================================',
        '',
        'START TRANSACTION;',
    ];

    $sheet = [
        '==================================================================',
        ' RESET SIGN-IN DETAILS -- CONFIDENTIAL',
        '==================================================================',
        ' Generated '.gmdate('Y-m-d H:i').' UTC with '.basename($sqlPath).'. These replace',
        ' the old details the moment that file is imported. Same rules as',
        ' the original sheet: never upload, email or share this file; give',
        ' each person only their own entry; then delete it.',
        '==================================================================',
    ];

    // Roles from accounts.csv where the account is listed there, so the sheet
    // only prints a PIN that will actually work. An account not in the file
    // (created some other way) gets a PIN that applies only if it had one.
    $roles = [];

    if (is_readable("{$here}/accounts.csv")) {
        foreach (readCsv("{$here}/accounts.csv") as $row) {
            $roles[strtoupper($row['employee_no'])] = explode('|', $row['roles']);
        }
    }

    foreach ($employeeNumbers as $no) {
        $password = password();
        $listed = $roles[strtoupper($no)] ?? null;
        $bedside = $listed !== null && array_intersect($listed, BEDSIDE_ROLES) !== [];
        $pin = $listed !== null && ! $bedside ? null : pin();

        $pinSql = match (true) {
            $pin === null => null,
            $bedside => quote(password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12])),
            default => 'IF(clinical_pin_hash IS NULL, NULL, '.quote(password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12])).')',
        };

        $sql[] = '';
        $sql[] = "-- {$no}";
        $sql[] = 'UPDATE staff';
        $sql[] = '   SET password = '.quote(password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])).',';

        if ($pinSql !== null) {
            $sql[] = "       clinical_pin_hash = {$pinSql},";
        }

        $sql[] = '       failed_logins = 0, locked_until = NULL, updated_at = UTC_TIMESTAMP(3)';
        $sql[] = ' WHERE employee_no = '.quote($no).' AND deleted_at IS NULL;';
        // Matched on the class name's tail rather than spelled out: the stored
        // value contains backslashes, and how a literal backslash is read
        // depends on the server's sql_mode.
        $sql[] = 'DELETE t FROM personal_access_tokens t JOIN staff s ON s.id = t.tokenable_id';
        $sql[] = ' WHERE s.employee_no = '.quote($no)." AND t.tokenable_type LIKE '%Staff';";

        $sheet[] = '';
        $sheet[] = " {$no}";
        $sheet[] = '   Sign in:   '.$no;
        $sheet[] = '   Password:  '.$password;
        $sheet[] = '   PIN:       '.match (true) {
            $pin === null => '-  (not a bedside role)',
            $bedside => $pin,
            default => $pin.'  (not in accounts.csv: replaces the old PIN only if it had one)',
        };
    }

    $sql[] = '';
    $sql[] = 'COMMIT;';
    $sql[] = '';
    $sheet[] = '';

    file_put_contents($sqlPath, implode("\n", $sql));
    file_put_contents($sheetPath, implode("\n", $sheet));
    @chmod($sheetPath, 0600);
    $printable = renderSheetHtml($sheetPath);

    echo 'Wrote a reset for '.implode(', ', $employeeNumbers).':'.PHP_EOL;
    echo '  SQL:          '.relative($sqlPath).PHP_EOL;
    echo '  Credentials:  '.relative($sheetPath).'   <- keep off the server'.PHP_EOL;
    echo '  Printable:    '.relative($printable).PHP_EOL;
}

/**
 * @return list<array<string, string>>
 */
function readCsv(string $path): array
{
    $handle = @fopen($path, 'r');

    if ($handle === false) {
        fail(["Cannot read {$path}."]);
    }

    $errors = [];
    $rows = [];
    $header = null;
    $line = 0;
    $seenNo = [];
    $seenEmail = [];

    while (($raw = fgets($handle)) !== false) {
        $line++;
        $text = trim($raw);

        if ($text === '' || str_starts_with($text, '#')) {
            continue;
        }

        // An empty escape character is plain RFC 4180, and PHP 8.4 wants it stated.
        $cells = array_map('trim', str_getcsv($text, ',', '"', ''));

        if ($header === null) {
            if ($cells !== COLUMNS) {
                fail(["accounts.csv line {$line}: the header must be exactly: ".implode(',', COLUMNS)]);
            }

            $header = $cells;

            continue;
        }

        if (count($cells) !== count(COLUMNS)) {
            $errors[] = "line {$line}: expected ".count(COLUMNS).' columns, found '.count($cells).'.';

            continue;
        }

        $row = array_combine(COLUMNS, $cells);
        $where = "line {$line} ({$row['employee_no']})";

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/', $row['employee_no'])) {
            $errors[] = "{$where}: employee_no must be 1-30 letters, digits, dots, dashes or underscores. It is the sign-in name.";
        }

        foreach (['first_name' => 80, 'last_name' => 80, 'licence_no' => 40, 'email' => 160] as $field => $max) {
            if ((function_exists('mb_strlen') ? mb_strlen($row[$field]) : strlen($row[$field])) > $max) {
                $errors[] = "{$where}: {$field} is longer than {$max} characters.";
            }

            // Refused rather than escaped: a backslash means something different
            // depending on the server's sql_mode, and no name needs one.
            if (preg_match('/[\\\\\x00-\x1F\x7F]/', $row[$field])) {
                $errors[] = "{$where}: {$field} contains a backslash or a control character.";
            }
        }

        if ($row['first_name'] === '' || $row['last_name'] === '') {
            $errors[] = "{$where}: first_name and last_name are both required -- they are the name on every record this account signs.";
        }

        if ($row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = "{$where}: '{$row['email']}' is not an email address. Leave it empty if there is none.";
        }

        $roles = array_filter(explode('|', $row['roles']), fn (string $r): bool => $r !== '');
        $unknown = array_diff($roles, KNOWN_ROLES);

        if ($roles === []) {
            $errors[] = "{$where}: at least one role is required. Separate several with |, e.g. nurse|head_nurse.";
        } elseif ($unknown !== []) {
            $errors[] = "{$where}: unknown role(s) ".implode(', ', $unknown).'. Known: '.implode(', ', KNOWN_ROLES).'.';
        }

        $row['roles'] = implode('|', array_values(array_unique($roles)));

        $no = strtoupper($row['employee_no']);
        if (isset($seenNo[$no])) {
            $errors[] = "{$where}: employee_no repeats line {$seenNo[$no]}.";
        }
        $seenNo[$no] = $line;

        if ($row['email'] !== '') {
            $email = strtolower($row['email']);
            if (isset($seenEmail[$email])) {
                $errors[] = "{$where}: email repeats line {$seenEmail[$email]}.";
            }
            $seenEmail[$email] = $line;
        }

        $rows[] = $row;
    }

    fclose($handle);

    if ($header === null) {
        $errors[] = 'accounts.csv has no header row.';
    }

    if ($errors !== []) {
        fail(array_merge(['accounts.csv has problems; nothing was written:'], array_map(fn ($e) => "  {$e}", $errors)));
    }

    return $rows;
}

/**
 * @param  list<array<string, mixed>>  $accounts
 */
function sql(array $accounts): string
{
    $out = [];
    $out[] = '-- ============================================================';
    $out[] = '--  STAFF ACCOUNTS';
    $out[] = '-- ============================================================';
    $out[] = '--  Generated by deploy/accounts/make-accounts.php on '.gmdate('Y-m-d H:i').' UTC.';
    $out[] = '--  Passwords and PINs are bcrypt hashes; the plaintext is on the';
    $out[] = '--  credentials sheet generated with this file, not in here.';
    $out[] = '--';
    $out[] = '--  Creates each account only if nobody already has its employee';
    $out[] = '--  number or email, and never updates an existing one -- so';
    $out[] = '--  importing this twice, or over a live installation, can never';
    $out[] = '--  reset a password. One transaction: a failure creates nothing.';
    $out[] = '--';
    $out[] = '--  Import into the database cPanel already created for you, after';
    $out[] = '--  01-03. Do NOT prepend CREATE DATABASE or USE.';
    $out[] = '--';
    $out[] = '--  Accounts:';

    foreach ($accounts as $a) {
        $out[] = sprintf('--    %-10s %s %s%s', $a['employee_no'], pad($a['first_name'].' '.$a['last_name'], 28), $a['roles'], $a['pin'] === null ? '' : '  (+ bedside PIN)');
    }

    $out[] = '-- ============================================================';
    $out[] = '';
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = '';
    $out[] = 'START TRANSACTION;';

    foreach ($accounts as $a) {
        $id = quote($a['public_id']);
        $email = $a['email'] === '' ? 'NULL' : quote($a['email']);
        $emailGuard = $a['email'] === '' ? '' : "\n  AND NOT EXISTS (SELECT 1 FROM staff WHERE email = {$email})";

        $out[] = '';
        $out[] = "-- {$a['employee_no']} -- {$a['first_name']} {$a['last_name']} -- {$a['roles']}";
        // Times are written as UTC explicitly. The columns default to
        // CURRENT_TIMESTAMP, which is the MySQL server's zone -- not what the
        // rest of this system stores.
        $out[] = 'INSERT INTO staff (public_id, employee_no, first_name, last_name, email, licence_no,';
        $out[] = '                   password, clinical_pin_hash, is_active, created_at, updated_at)';
        $out[] = sprintf(
            'SELECT %s, %s, %s, %s, %s, %s,'."\n".'       %s, %s, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)',
            $id,
            quote($a['employee_no']),
            quote($a['first_name']),
            quote($a['last_name']),
            $email,
            $a['licence_no'] === '' ? 'NULL' : quote($a['licence_no']),
            quote(password_hash($a['password'], PASSWORD_BCRYPT, ['cost' => 12])),
            $a['pin'] === null ? 'NULL' : quote(password_hash($a['pin'], PASSWORD_BCRYPT, ['cost' => 12])),
        );
        $out[] = 'FROM DUAL';
        $out[] = 'WHERE NOT EXISTS (SELECT 1 FROM staff WHERE employee_no = '.quote($a['employee_no']).')'.$emailGuard.';';

        foreach ($a['role_list'] as $role) {
            // Keyed on the public_id this file just minted, not the employee
            // number: if the account above was skipped because someone already
            // holds that number, this grants nothing to them.
            $out[] = 'INSERT INTO role_staff (staff_id, role_code, granted_at)';
            $out[] = 'SELECT s.id, '.quote($role).', UTC_TIMESTAMP(3) FROM staff s';
            $out[] = "WHERE s.public_id = {$id}";
            $out[] = '  AND NOT EXISTS (SELECT 1 FROM role_staff r WHERE r.staff_id = s.id AND r.role_code = '.quote($role).');';
        }
    }

    $out[] = '';
    $out[] = 'COMMIT;';
    $out[] = '';

    return implode("\n", $out);
}

/**
 * @param  list<array<string, mixed>>  $accounts
 */
function sheet(array $accounts, string $sqlFile): string
{
    $out = [];
    $out[] = '==================================================================';
    $out[] = ' INITIAL SIGN-IN DETAILS -- CONFIDENTIAL';
    $out[] = '==================================================================';
    $out[] = ' Generated '.gmdate('Y-m-d H:i').' UTC with '.$sqlFile.'. These work only';
    $out[] = ' once that file has been imported, and only for accounts it';
    $out[] = ' created -- it skips any employee number already on file.';
    $out[] = '';
    $out[] = ' NEVER upload this file to the server, email it, or put it in a';
    $out[] = ' shared folder. Give each person only their own entry, in person';
    $out[] = ' or on paper. Then move it into a password manager, or print it';
    $out[] = ' and lock the print away, and DELETE this file.';
    $out[] = '';
    $out[] = ' The app cannot change a password or a PIN yet. Until it can, a';
    $out[] = ' password is changed by an administrator with SQL -- see step 8';
    $out[] = ' of DEPLOYMENT.md. Anyone who has seen this sheet knows these.';
    $out[] = '';
    $out[] = ' Sign in with the employee number (or the email, if one is set).';
    $out[] = ' The PIN unlocks a bedside tablet after that person has signed in';
    $out[] = ' on it once with their password.';
    $out[] = '==================================================================';

    foreach ($accounts as $a) {
        $out[] = '';
        $out[] = sprintf(' %s  %s %s', $a['employee_no'], $a['first_name'], $a['last_name']);
        $out[] = '   Roles:     '.str_replace('|', ', ', $a['roles']);
        $out[] = '   Sign in:   '.$a['employee_no'];
        $out[] = '   Password:  '.$a['password'];
        $out[] = '   PIN:       '.($a['pin'] ?? '-  (not a bedside role)');
    }

    $out[] = '';

    return implode("\n", $out);
}

/**
 * A printable HTML copy of a credentials sheet, written beside it.
 *
 * Built by reading the .txt sheet rather than from accounts in memory, so a
 * sheet that already exists can be rendered later (--render-sheet) with its
 * passwords untouched, and the two copies can never disagree.
 *
 * @return string the path of the HTML file
 */
function renderSheetHtml(string $txtPath): string
{
    $text = (string) file_get_contents($txtPath);

    preg_match_all(
        '/^ (\S+)(?:  ([^\n]+))?\n(?:   Roles:\s+([^\n]+)\n)?   Sign in:\s+(\S+)\n   Password:\s+(\S+)\n   PIN:\s+([^\n]+)$/m',
        $text,
        $blocks,
        PREG_SET_ORDER,
    );

    if ($blocks === []) {
        fail(["No sign-in entries found in {$txtPath}."]);
    }

    preg_match('/Generated ([0-9-]+ [0-9:]+ UTC) with (\S+?)\./', $text, $generated);

    $entries = [];

    foreach ($blocks as $block) {
        $pinText = trim($block[6]);
        $hasPin = preg_match('/^(\d{6})\s*(.*)$/', $pinText, $pin) === 1;

        $entries[] = [
            'employee_no' => $block[1],
            'name' => trim($block[2] ?? ''),
            'roles' => trim($block[3] ?? ''),
            'password' => $block[5],
            'pin' => $hasPin ? $pin[1] : null,
            'pin_note' => $hasPin ? trim($pin[2], ' ()') : '',
        ];
    }

    $htmlPath = (string) preg_replace('/\.txt$/', '.html', $txtPath);

    file_put_contents($htmlPath, sheetHtml(
        $entries,
        str_contains($text, 'RESET SIGN-IN DETAILS'),
        $generated[1] ?? '',
        $generated[2] ?? '',
    ));
    @chmod($htmlPath, 0600);

    return $htmlPath;
}

/**
 * The printable sheet: a reference table for the administrator, then one
 * cut-out slip per person so each is handed only their own.
 *
 * Self-contained on purpose -- no fonts, scripts or images from anywhere --
 * so opening it makes no network request, and paper-first: it is meant to be
 * printed, handed out and then deleted.
 *
 * @param  list<array{employee_no: string, name: string, roles: string, password: string, pin: string|null, pin_note: string}>  $entries
 */
function sheetHtml(array $entries, bool $reset, string $generatedAt, string $sqlFile): string
{
    $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = $reset ? 'Reset Sign-in Details' : 'Initial Sign-in Details';

    $rows = '';
    $slips = '';

    foreach ($entries as $a) {
        $pinCell = $a['pin'] === null
            ? '<span class="none">none &mdash; not a bedside role</span>'
            : '<span class="secret">'.$e($a['pin']).'</span>'.($a['pin_note'] === '' ? '' : '<div class="note">'.$e($a['pin_note']).'</div>');

        $rows .= '<tr><td class="mono">'.$e($a['employee_no']).'</td><td>'.$e($a['name'] === '' ? '(existing account)' : $a['name'])
            .'</td><td>'.$e(str_replace('|', ', ', $a['roles'])).'</td><td class="secret">'.$e($a['password']).'</td><td>'.$pinCell.'</td></tr>'."\n";

        $slips .= '<section class="slip"><p class="slip-kind">'.$e($title).' &middot; confidential</p>'
            .'<h3>'.$e($a['name'] === '' ? $a['employee_no'] : $a['name']).'</h3>'
            .($a['roles'] === '' ? '' : '<p class="slip-role">'.$e(str_replace('|', ', ', $a['roles'])).'</p>')
            .'<dl><div><dt>Sign in as</dt><dd class="mono">'.$e($a['employee_no']).'</dd></div>'
            .'<div><dt>Password</dt><dd class="secret big">'.$e($a['password']).'</dd></div>'
            .($a['pin'] === null ? '' : '<div><dt>Bedside PIN</dt><dd class="secret big">'.$e($a['pin']).'</dd></div>')
            .'</dl><p class="slip-rule">Yours alone &mdash; never share it or write it where others can see it. The PIN unlocks a bedside tablet once you have signed in on it with your password. If anyone may have seen these, ask the administrator for a reset.</p></section>'."\n";
    }

    $meta = $generatedAt === '' ? '' : 'Generated '.$e($generatedAt).($sqlFile === '' ? '' : ' with <span class="mono">'.$e($sqlFile).'</span>').'.';
    $applies = $reset
        ? 'These replace the old details the moment that file is imported.'
        : 'They work once that file has been imported, and only for accounts it created &mdash; it skips any employee number already on file.';

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title}</title>
<style>
  /* Paper-first and deliberately single-theme: this is printed, handed out and deleted. */
  :root { --ink:#16221f; --muted:#56655f; --rule:#cfd8d4; --paper:#ffffff; --wash:#f4f6f5;
          --alarm:#9c2f26; --alarm-wash:#f7e9e7; --accent:#0e6b5e; }
  * { box-sizing:border-box; }
  html { background:var(--wash); }
  body { margin:0; background:var(--wash); color:var(--ink);
         font:15px/1.5 "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif; }
  .page { max-width:980px; margin:0 auto; padding:28px 16px 48px; }
  header { display:flex; flex-wrap:wrap; align-items:baseline; gap:10px 14px; margin-bottom:6px; }
  h1 { margin:0; font-size:1.6rem; letter-spacing:-0.01em; text-wrap:balance; }
  .stamp { border:2px solid var(--alarm); color:var(--alarm); border-radius:4px; padding:2px 10px;
           font-weight:700; font-size:0.8rem; letter-spacing:0.12em; text-transform:uppercase; }
  .meta { color:var(--muted); margin:0 0 18px; font-size:0.9rem; }
  .rules { background:var(--alarm-wash); color:var(--alarm); border-radius:6px; padding:14px 18px; margin-bottom:22px; }
  .rules strong { display:block; margin-bottom:4px; }
  .rules ul { margin:6px 0 0; padding-left:20px; }
  h2 { font-size:0.8rem; letter-spacing:0.1em; text-transform:uppercase; color:var(--muted); margin:26px 0 10px; }
  .tablewrap { overflow-x:auto; background:var(--paper); border:1px solid var(--rule); border-radius:6px; }
  table { width:100%; border-collapse:collapse; font-variant-numeric:tabular-nums; }
  th, td { text-align:left; padding:9px 12px; border-bottom:1px solid var(--rule); vertical-align:top; }
  th { font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--muted); background:var(--wash); }
  tr:last-child td { border-bottom:none; }
  .mono, .secret { font-family:ui-monospace, "Cascadia Mono", Consolas, "SFMono-Regular", Menlo, monospace; }
  .secret { font-weight:600; letter-spacing:0.03em; white-space:nowrap; }
  .none { color:var(--muted); }
  .note { color:var(--muted); font-size:0.8rem; white-space:normal; }
  .howto { color:var(--muted); font-size:0.9rem; margin:0 0 12px; }
  .slips { display:grid; grid-template-columns:repeat(auto-fit, minmax(290px, 1fr)); gap:14px; }
  .slip { background:var(--paper); border:2px dashed var(--muted); border-radius:6px; padding:14px 16px; break-inside:avoid; }
  .slip-kind { margin:0; font-size:0.7rem; letter-spacing:0.1em; text-transform:uppercase; color:var(--alarm); font-weight:700; }
  .slip h3 { margin:4px 0 0; font-size:1.15rem; }
  .slip-role { margin:0; color:var(--muted); font-size:0.88rem; }
  .slip dl { margin:12px 0 10px; display:grid; gap:8px; }
  .slip dt { font-size:0.68rem; letter-spacing:0.08em; text-transform:uppercase; color:var(--muted); }
  .slip dd { margin:0; }
  .big { font-size:1.2rem; }
  .slip-rule { margin:0; font-size:0.78rem; color:var(--muted); border-top:1px solid var(--rule); padding-top:8px; }
  .printbar { margin:22px 0 0; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  button { font:inherit; font-weight:600; padding:8px 16px; border-radius:4px; border:1px solid var(--accent);
           background:var(--accent); color:#fff; cursor:pointer; }
  button:focus-visible { outline:3px solid var(--ink); outline-offset:2px; }
  @media print {
    html, body { background:#fff; }
    .page { padding:0; max-width:none; }
    .printbar { display:none; }
    .reference { break-after:page; }
    .tablewrap, .slip { border-color:#999; }
  }
</style>
</head>
<body>
<main class="page">
  <header>
    <h1>{$title}</h1>
    <span class="stamp">Confidential</span>
  </header>
  <p class="meta">{$meta} {$applies}</p>

  <div class="rules" role="note">
    <strong>These are live passwords for a system that holds patient records.</strong>
    <ul>
      <li>Never upload this file to the server, email it, or put it in a shared folder or chat.</li>
      <li>Hand each person only their own slip, in person.</li>
      <li>Then keep the reference table somewhere locked (or in a password manager) and delete this file and the .txt beside it.</li>
      <li>Nobody can change their own password in the app yet &mdash; a reset is <span class="mono">make-accounts.php --reset</span>.</li>
    </ul>
  </div>

  <section class="reference">
    <h2>For the administrator</h2>
    <div class="tablewrap">
      <table>
        <thead><tr><th>Sign in</th><th>Name</th><th>Roles</th><th>Password</th><th>Bedside PIN</th></tr></thead>
        <tbody>
{$rows}        </tbody>
      </table>
    </div>
  </section>

  <h2>One slip per person</h2>
  <p class="howto">Cut along the dashed lines. Each slip carries one person's details and nobody else's.</p>
  <div class="slips">
{$slips}  </div>

  <div class="printbar">
    <button type="button" onclick="window.print()">Print</button>
    <span class="howto" style="margin:0">The reference table prints on its own page; slips follow.</span>
  </div>
</main>
</body>
</html>

HTML;
}

/** Single quotes doubled; backslashes are refused earlier, so nothing else needs escaping. */
function quote(string $value): string
{
    return "'".str_replace("'", "''", $value)."'";
}

/**
 * 16 characters in four groups, from an alphabet with nothing that can be
 * misread off a printout -- no 0/O, no 1/l/I. About 93 bits.
 */
function password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $chars = '';

    for ($i = 0; $i < 16; $i++) {
        $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return implode('-', str_split($chars, 4));
}

/** Six digits, as the unlock endpoint requires, never a run or a near-repeat. */
function pin(): string
{
    do {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(fn ($a, $b) => $b - $a, array_slice($digits, 0, 5), array_slice($digits, 1)));
        $weak = count(array_unique($digits)) <= 2 || (count($steps) === 1 && in_array(abs((int) reset($steps)), [0, 1], true));
    } while ($weak);

    return $pin;
}

/**
 * A ULID: 48-bit millisecond time and 80 random bits in Crockford base32.
 * Lowercase, as Laravel's own HasUlids writes them. The first character is
 * always 0-7, which is what route binding checks before it will query.
 */
function ulid(): string
{
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $ms = (int) floor(microtime(true) * 1000);
    $time = '';

    for ($i = 0; $i < 10; $i++) {
        $time = $alphabet[$ms % 32].$time;
        $ms = intdiv($ms, 32);
    }

    $random = '';

    foreach (str_split(random_bytes(16)) as $byte) {
        $random .= $alphabet[ord($byte) % 32];
    }

    return strtolower($time.$random);
}

function relative(string $path): string
{
    $root = dirname(__DIR__, 2);

    return str_starts_with($path, $root) ? ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/') : $path;
}

/**
 * @param  list<string>  $lines
 */
function fail(array $lines): never
{
    fwrite(STDERR, implode(PHP_EOL, $lines).PHP_EOL);
    exit(1);
}

/** Pads by characters, not bytes, so a name with an ñ lines up with the rest. */
function pad(string $text, int $width): string
{
    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

    return $text.str_repeat(' ', max(0, $width - $length));
}
