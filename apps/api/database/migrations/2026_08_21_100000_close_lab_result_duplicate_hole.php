<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make "one result per patient, test, specimen date and timing" actually true.
 *
 * The baseline declares `lr_uq (patient_id, test_code, specimen_date, timing)`,
 * which reads like it guarantees that. It does not. `timing` is nullable and
 * MySQL ignores NULLs in a unique index, so the constraint bites only when a
 * timing is supplied -- and most tests on a monthly panel have no timing at all.
 * Filing the same haemoglobin twice was accepted without complaint, which is how
 * a patient ends up with two different values for the same specimen and nobody
 * knowing which the clinician read.
 *
 * The fix is CLAUDE.md's generated-column idiom used in reverse. Rule 6 uses a
 * STORED column that evaluates to NULL to *suppress* uniqueness; here the column
 * coalesces NULL to a sentinel so uniqueness can never be suppressed.
 *
 * `lr_uq` is dropped rather than left alongside: any pair that violated it also
 * violates the new key, so it is strictly redundant, and a redundant unique
 * index on nearly the same columns is a trap for whoever reads this next.
 * Lookups keep using `lr_patient_idx`, which is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A generated column cannot be added and indexed in one ALTER on MySQL,
        // so this is deliberately two statements.
        DB::statement(<<<'SQL'
            ALTER TABLE lab_results
              ADD COLUMN timing_key VARCHAR(12)
              GENERATED ALWAYS AS (COALESCE(timing, 'unspecified')) STORED
              AFTER timing
        SQL);

        DB::statement('ALTER TABLE lab_results DROP INDEX lr_uq');

        DB::statement(<<<'SQL'
            ALTER TABLE lab_results
              ADD UNIQUE KEY lr_result_uq (patient_id, test_code, specimen_date, timing_key)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lab_results DROP INDEX lr_result_uq');
        DB::statement('ALTER TABLE lab_results DROP COLUMN timing_key');

        DB::statement(<<<'SQL'
            ALTER TABLE lab_results
              ADD UNIQUE KEY lr_uq (patient_id, test_code, specimen_date, timing)
        SQL);
    }
};
