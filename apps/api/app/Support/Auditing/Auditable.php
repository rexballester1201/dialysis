<?php

declare(strict_types=1);

namespace App\Support\Auditing;

/**
 * Attach to any model whose changes must be reconstructable.
 *
 * MySQL cannot serialise a row to JSON inside a trigger, so unlike the
 * PostgreSQL design the audit trail is written in PHP. The consequence is
 * important enough to state plainly: a direct SQL UPDATE bypasses this.
 * The application account must be the only credential with write access,
 * and DBA access must be break-glass and logged outside the application.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(AuditObserver::class);
    }

    /**
     * Never write these into the audit payload.
     *
     * @return list<string>
     */
    public function auditExclude(): array
    {
        return ['password', 'clinical_pin_hash', 'two_factor_secret', 'remember_token', 'updated_at'];
    }
}
