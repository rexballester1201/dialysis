<?php

declare(strict_types=1);

/**
 * Standalone integration check for the offline sync protocol.
 *
 * Runs the same logic as App\Domain\Sync\SyncBatchProcessor against a live
 * MySQL, without Laravel, so the protocol can be proven independently of the
 * framework. Mirror these as Pest feature tests once the app is scaffolded.
 *
 *   php verify/verify_sync.php
 */

// Connection details come from apps/api/.env (overridable by real environment
// variables) instead of being hardcoded. This box runs an unrelated MySQL on the
// default port 3306, so a hardcoded localhost DSN silently pointed the whole
// verification at the wrong server. Defaults match the original literals.
$dotenv = [];
if (is_file($envPath = __DIR__.'/../.env')) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $dotenv[trim($key)] = trim(trim($value), "\"'");
    }
}
$cfg = static function (string $key, string $default) use ($dotenv): string {
    $fromEnv = getenv($key);

    return ($fromEnv !== false && $fromEnv !== '') ? $fromEnv : ($dotenv[$key] ?? $default);
};

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $cfg('DB_HOST', 'localhost'),
        $cfg('DB_PORT', '3306'),
        $cfg('DB_DATABASE', 'dialysis'),
    ),
    $cfg('DB_USERNAME', 'root'),
    $cfg('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}".($detail ? " -- {$detail}" : '')."\n";
    }
}

function ulid(int $n): string
{
    return strtoupper(substr(str_pad(base_convert((string) (1000000 + $n), 10, 32), 26, '0', STR_PAD_LEFT), 0, 26));
}

// ---------------------------------------------------------------------
// A minimal port of SyncBatchProcessor. The three properties under test
// are batch-level idempotency, operation-level idempotency, and partial
// success -- everything else is application detail.
// ---------------------------------------------------------------------
final class Processor
{
    public function __construct(private PDO $pdo, private int $actorId) {}

    public function process(string $batchUuid, string $deviceId, array $operations): array
    {
        $stmt = $this->pdo->prepare('SELECT result FROM sync_batches WHERE batch_uuid = ?');
        $stmt->execute([$batchUuid]);

        if ($row = $stmt->fetch()) {
            return json_decode($row['result'], true) + ['replayed' => true];
        }

        $results = array_map(fn ($op) => $this->apply($op), $operations);
        $summary = ['batch_uuid' => $batchUuid, 'results' => $results];

        $this->pdo->prepare(
            'INSERT INTO sync_batches (batch_uuid, device_id, staff_id, operation_count,
                                       applied_count, rejected_count, result)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $batchUuid, $deviceId, $this->actorId, count($operations),
            $this->countBy($results, 'applied'),
            $this->countBy($results, 'rejected') + $this->countBy($results, 'conflict'),
            json_encode($summary),
        ]);

        return $summary + ['replayed' => false];
    }

    private function apply(array $op): array
    {
        $opUuid = $op['op_uuid'];

        try {
            $this->pdo->beginTransaction();
            $out = match ($op['type']) {
                'vital.append' => $this->appendVital($op, $opUuid),
                'session.upsert' => $this->upsertSession($op, $opUuid),
                default => ['op_uuid' => $opUuid, 'status' => 'rejected',
                    'message' => "Unknown type: {$op['type']}"],
            };
            $this->pdo->commit();

            return $out;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return ['op_uuid' => $opUuid, 'status' => 'rejected', 'message' => $e->getMessage()];
        }
    }

    private function session(string $publicId): ?array
    {
        $s = $this->pdo->prepare('SELECT * FROM treatment_sessions WHERE public_id = ?');
        $s->execute([$publicId]);

        return $s->fetch() ?: null;
    }

    private function appendVital(array $op, string $opUuid): array
    {
        $p = $op['payload'];
        $session = $this->session($p['session_public_id']);

        if ($session === null) {
            return ['op_uuid' => $opUuid, 'status' => 'rejected', 'message' => 'Unknown session'];
        }
        if ($session['locked_at'] !== null) {
            return ['op_uuid' => $opUuid, 'status' => 'conflict',
                'message' => 'Session was signed and locked while this device was offline.'];
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO session_vitals
                   (session_id, client_uuid, recorded_at, minutes_elapsed, bp_sys, bp_dia,
                    pulse, uf_volume_ml, source, recorded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $session['id'], $opUuid, $p['recorded_at'], $p['minutes_elapsed'] ?? null,
                $p['bp_sys'] ?? null, $p['bp_dia'] ?? null, $p['pulse'] ?? null,
                $p['uf_volume_ml'] ?? null, 'manual', $this->actorId,
            ]);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] === 1062) {
                return ['op_uuid' => $opUuid, 'status' => 'duplicate'];
            }
            throw $e;
        }

        return ['op_uuid' => $opUuid, 'status' => 'applied',
            'server_id' => (int) $this->pdo->lastInsertId()];
    }

    private function upsertSession(array $op, string $opUuid): array
    {
        $p = $op['payload'];
        $session = $this->session($p['session_public_id']);

        if ($session === null) {
            return ['op_uuid' => $opUuid, 'status' => 'rejected', 'message' => 'Unknown session'];
        }
        if ($session['locked_at'] !== null) {
            return ['op_uuid' => $opUuid, 'status' => 'conflict',
                'message' => 'Session was signed on another device.'];
        }

        $allowed = ['pre_weight_kg', 'dry_weight_kg', 'pre_bp_sys', 'pre_bp_dia', 'pre_pulse', 'status'];
        $sets = $args = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $p)) {
                $sets[] = "{$col} = ?";
                $args[] = $p[$col];
            }
        }
        if ($sets === []) {
            return ['op_uuid' => $opUuid, 'status' => 'rejected', 'message' => 'Nothing to update'];
        }

        $args[] = $session['id'];
        $this->pdo->prepare('UPDATE treatment_sessions SET '.implode(', ', $sets)
            .', synced_at = NOW(3) WHERE id = ?')->execute($args);

        return ['op_uuid' => $opUuid, 'status' => 'applied', 'server_id' => (int) $session['id']];
    }

    private function countBy(array $results, string $status): int
    {
        return count(array_filter($results, fn ($r) => $r['status'] === $status));
    }
}

// ---------------------------------------------------------------------
// Fixture: one scheduled session for the existing demo patient
// ---------------------------------------------------------------------
$actorId = (int) $pdo->query("SELECT id FROM staff WHERE employee_no='RN-014'")->fetchColumn();
$patientId = (int) $pdo->query("SELECT id FROM patients WHERE mrn='MRN-0001'")->fetchColumn();
$shiftId = (int) $pdo->query("SELECT id FROM shifts WHERE code='PM'")->fetchColumn();
$stationId = (int) $pdo->query("SELECT id FROM stations WHERE code='ISO-B2'")->fetchColumn();

// A real ULID (CLAUDE.md, MySQL rule 10). It used to be SYNCTEST000...1,
// which no ULID can be -- the first character of one is 0-7 -- so the API
// could never name the row, and once billing screens listed it, it showed up
// as a claimable session that failed validation the moment it was picked.
$sessionPublicId = '01J0SYNCTEST00000000000001';
$legacyPublicId = 'SYNCTEST000000000000000001';

// Clear this script's own fixture from a previous run before recreating it.
//
// Only ever the synthetic SYNCTEST session and its children: no real record
// carries that public_id, and nothing else is touched. Without this the second
// run of the day dies on a duplicate public_id and the failure reads like a
// protocol regression rather than leftover state -- which cost real time twice
// before it was fixed.
$existingId = $pdo->prepare('SELECT id FROM treatment_sessions WHERE public_id IN (?, ?)');
$existingId->execute([$sessionPublicId, $legacyPublicId]);

foreach ($existingId->fetchAll(PDO::FETCH_COLUMN) as $staleId) {
    foreach (['session_vitals', 'session_events', 'session_notes', 'medication_administrations'] as $child) {
        $pdo->prepare("DELETE FROM {$child} WHERE session_id = ?")->execute([$staleId]);
    }

    $pdo->prepare('DELETE FROM treatment_sessions WHERE id = ?')->execute([$staleId]);
}

// The batch ids below are deterministic, so last run's sync_batches rows would
// make every batch look like a replay -- the processor would return the stored
// result and write nothing, and the protocol tests would fail for a reason that
// has nothing to do with the protocol.
$pdo->prepare('DELETE FROM sync_batches WHERE device_id = ?')->execute(['TABLET-01']);

// Not billable: this treatment never happened, and the run leaves it signed in
// whatever database it was pointed at. Billing lists signed, billable sessions,
// so without this a verifier run put a phantom treatment on the claims screen.
$pdo->prepare(
    'INSERT INTO treatment_sessions (public_id, patient_id, session_date, shift_id, station_id,
                                     status, primary_nurse_id, started_at, is_billable)
     VALUES (?,?,CURDATE(),?,?,?,?,NOW(3) - INTERVAL 260 MINUTE,0)'
)->execute([$sessionPublicId, $patientId, $shiftId, $stationId, 'in_progress', $actorId]);

$processor = new Processor($pdo, $actorId);
$countVitals = fn () => (int) $pdo->query(
    "SELECT COUNT(*) FROM session_vitals v JOIN treatment_sessions s ON s.id=v.session_id
     WHERE s.public_id='{$sessionPublicId}'"
)->fetchColumn();

// A realistic offline shift: header + 9 half-hourly observations
$ops = [[
    'op_uuid' => ulid(0),
    'type' => 'session.upsert',
    'payload' => ['session_public_id' => $sessionPublicId, 'pre_weight_kg' => 61.2,
        'dry_weight_kg' => 57.5, 'pre_bp_sys' => 148, 'pre_bp_dia' => 84, 'pre_pulse' => 80],
]];
for ($i = 0; $i < 9; $i++) {
    $ops[] = [
        'op_uuid' => ulid($i + 1),
        'type' => 'vital.append',
        'payload' => [
            'session_public_id' => $sessionPublicId,
            'recorded_at' => date('Y-m-d H:i:s', time() - (255 - $i * 30) * 60),
            'minutes_elapsed' => $i * 30,
            'bp_sys' => 148 - $i * 5, 'bp_dia' => 84 - $i * 2,
            'pulse' => 80 + $i, 'uf_volume_ml' => $i * 370,
        ],
    ];
}

echo "\n=== 1. First delivery of the batch ===\n";
$batchA = ulid(900);
$r1 = $processor->process($batchA, 'TABLET-01', $ops);
$applied = count(array_filter($r1['results'], fn ($r) => $r['status'] === 'applied'));
check('all 10 operations applied', $applied === 10, "applied={$applied}");
check('9 vitals persisted', $countVitals() === 9, 'count='.$countVitals());
check('not flagged as a replay', $r1['replayed'] === false);

echo "\n=== 2. Same batch replayed (network retry) ===\n";
$before = $countVitals();
$r2 = $processor->process($batchA, 'TABLET-01', $ops);
check('flagged as a replay', $r2['replayed'] === true);
check('no duplicate rows written', $countVitals() === $before, 'count='.$countVitals());
$same = json_encode($r1['results']) === json_encode($r2['results']);
check('identical verdicts returned', $same,
    $same ? '' : "\n        live:   ".json_encode($r1['results'][0])
                ."\n        replay: ".json_encode($r2['results'][0]));

echo "\n=== 3. Same operations, NEW batch id (tablet lost the ack) ===\n";
$before = $countVitals();
$r3 = $processor->process(ulid(901), 'TABLET-01', $ops);
$dupes = count(array_filter($r3['results'], fn ($r) => $r['status'] === 'duplicate'));
check('9 vitals reported as duplicates', $dupes === 9, "duplicates={$dupes}");
check('still no duplicate rows', $countVitals() === $before, 'count='.$countVitals());

echo "\n=== 4. Partial success: one bad op must not sink the batch ===\n";
$before = $countVitals();
$mixed = [
    ['op_uuid' => ulid(600), 'type' => 'vital.append', 'payload' => [
        'session_public_id' => $sessionPublicId,
        'recorded_at' => date('Y-m-d H:i:s', time() - 5 * 60),
        'minutes_elapsed' => 255, 'bp_sys' => 118, 'bp_dia' => 70, 'pulse' => 88,
    ]],
    ['op_uuid' => ulid(601), 'type' => 'vital.append', 'payload' => [
        'session_public_id' => 'DOES-NOT-EXIST-000000000', 'recorded_at' => date('Y-m-d H:i:s'),
    ]],
    ['op_uuid' => ulid(602), 'type' => 'nonsense.op', 'payload' => []],
];
$r4 = $processor->process(ulid(902), 'TABLET-01', $mixed);
$byStatus = array_column($r4['results'], 'status', 'op_uuid');
check('good operation applied', $byStatus[ulid(600)] === 'applied');
check('unknown session rejected', $byStatus[ulid(601)] === 'rejected');
check('unknown type rejected', $byStatus[ulid(602)] === 'rejected');
check('exactly one new vital stored', $countVitals() === $before + 1, 'count='.$countVitals());

echo "\n=== 5. Session signed while the tablet was offline ===\n";
$pdo->prepare(
    "UPDATE treatment_sessions SET status='completed', ended_at=NOW(3),
        post_weight_kg=57.8, net_uf_ml=3400, nurse_signed_by=?, nurse_signed_at=NOW(3),
        physician_signed_by=?, physician_signed_at=NOW(3), locked_at=NOW(3)
     WHERE public_id=?"
)->execute([
    $actorId,
    (int) $pdo->query("SELECT id FROM staff WHERE employee_no='MD-001'")->fetchColumn(),
    $sessionPublicId,
]);

$before = $countVitals();
$late = [['op_uuid' => ulid(700), 'type' => 'vital.append', 'payload' => [
    'session_public_id' => $sessionPublicId,
    'recorded_at' => date('Y-m-d H:i:s', time() - 2 * 60),
    'minutes_elapsed' => 258, 'bp_sys' => 120, 'bp_dia' => 72,
]]];
$r5 = $processor->process(ulid(903), 'TABLET-01', $late);
check('late write reported as a conflict', $r5['results'][0]['status'] === 'conflict',
    $r5['results'][0]['status']);
check('locked record untouched', $countVitals() === $before);

echo "\n=== 6. Database is the backstop, not just the service layer ===\n";
try {
    $pdo->exec("UPDATE treatment_sessions SET ktv = 9.99 WHERE public_id='{$sessionPublicId}'");
    check('direct SQL edit of a locked record blocked', false, 'the UPDATE succeeded');
} catch (PDOException $e) {
    check('direct SQL edit of a locked record blocked', str_contains($e->getMessage(), 'locked'));
}

$pdo->exec('SET @allow_amendment = 1');
$pdo->exec("UPDATE treatment_sessions SET ktv = 1.31 WHERE public_id='{$sessionPublicId}'");
$pdo->exec('SET @allow_amendment = 0');
$ktv = $pdo->query("SELECT ktv FROM treatment_sessions WHERE public_id='{$sessionPublicId}'")->fetchColumn();
check('amendment workflow still succeeds', (float) $ktv === 1.31, "ktv={$ktv}");

echo "\n".str_repeat('-', 52)."\n";
echo sprintf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
