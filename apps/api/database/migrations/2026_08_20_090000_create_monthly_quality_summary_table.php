<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly rollup the dashboard reads instead of v_monthly_quality.
 *
 * CLAUDE.md, MySQL rule 7: "Views with aggregates or window functions are
 * materialised, so predicates do not push down. v_monthly_quality is the
 * definition of record but the dashboard reads a nightly summary table, not the
 * view."
 *
 * The view aggregates every treatment_sessions row and joins session_events
 * through a LATERAL subquery. Asking it for one patient's month still builds the
 * whole thing first -- fine at a hundred sessions, not fine at four years of
 * them, and the dashboard is the one screen that gets opened all day.
 *
 * This table is a cache, never a source. `dialysis:summarise-quality` rebuilds a
 * month from the view, so the numbers can only ever be the view's own. Anything
 * that must be right reads the view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_quality_summaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->date('month');

            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->unsignedInteger('missed')->default(0);
            $table->unsignedInteger('shortened')->default(0);
            $table->unsignedInteger('sessions_with_hypotension')->default(0);
            $table->unsignedInteger('sessions_with_reportable_event')->default(0);

            $table->decimal('avg_ktv', 4, 2)->nullable();
            $table->decimal('avg_urr', 4, 1)->nullable();
            $table->decimal('avg_idwg_kg', 7, 2)->nullable();
            $table->unsignedInteger('avg_duration_min')->nullable();
            $table->unsignedInteger('avg_pre_sbp')->nullable();

            // How stale the dashboard's numbers are. A screen that cannot say
            // when it was last refreshed invites someone to trust it too far.
            $table->dateTime('summarised_at', precision: 3);

            $table->unique(['patient_id', 'month'], 'mqs_patient_month_uq');
            $table->index('month', 'mqs_month_idx');

            // No cascade: this is a cache keyed on a patient, and a patient is
            // never hard-deleted anyway.
            $table->foreign('patient_id', 'mqs_patient_fk')->references('id')->on('patients');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_quality_summaries');
    }
};
