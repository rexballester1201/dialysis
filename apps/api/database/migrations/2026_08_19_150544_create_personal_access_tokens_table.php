<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum's token table, with two deliberate departures from the stock migration.
 *
 * 1. DATETIME(3) instead of TIMESTAMP. CLAUDE.md rule 3: MySQL TIMESTAMP cannot
 *    represent a date past 2038-01-19. Every other datetime in this database is
 *    DATETIME(3) and there is no reason for this table to be the exception.
 *
 * 2. `device_id`. A bedside token is bound to the tablet it was issued to, so a
 *    token lifted from one device and replayed from another can be detected,
 *    revoked and logged. The baseline schema predates Sanctum and has nowhere
 *    else to put this, and it belongs with the token rather than with the staff row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->string('device_id', 64)->nullable()->index();
            $table->dateTime('last_used_at', precision: 3)->nullable();
            $table->dateTime('expires_at', precision: 3)->nullable()->index();
            $table->dateTime('created_at', precision: 3)->nullable();
            $table->dateTime('updated_at', precision: 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
