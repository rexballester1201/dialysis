<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

final class AuditObserver
{
    public function created(Model $model): void
    {
        $this->write('INSERT', $model, null, $this->payload($model, $model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();

        // changed_cols is filtered through the same exclusion list as the
        // before/after payloads, so an audit row can never name a column it does
        // not carry. It also makes the row deterministic: `updated_at` is dirty
        // or not depending on whether the write landed in the same millisecond
        // as the last one, which is not something the audit trail should record
        // differently from one run to the next.
        $changed = array_values(array_diff(array_keys($dirty), $this->excluded($model)));

        // Nothing of substance changed -- a touch, or timestamps only.
        if ($changed === []) {
            return;
        }

        $this->write(
            'UPDATE',
            $model,
            $this->payload($model, array_intersect_key($model->getOriginal(), $dirty)),
            $this->payload($model, $dirty),
            $changed,
        );
    }

    public function deleted(Model $model): void
    {
        $this->write('DELETE', $model, $this->payload($model, $model->getOriginal()), null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function payload(Model $model, array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->excluded($model)));
    }

    /**
     * Columns that never reach the audit trail: credentials, and the timestamp
     * that changes on every write regardless.
     *
     * @return list<string>
     */
    private function excluded(Model $model): array
    {
        /** @var list<string> */
        return method_exists($model, 'auditExclude') ? $model->auditExclude() : [];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  list<string>  $changed
     */
    private function write(
        string $action,
        Model $model,
        ?array $before,
        ?array $after,
        array $changed = [],
    ): void {
        $actor = Auth::user();

        DB::table('audit_logs')->insert([
            'occurred_at' => now(),
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->full_name,
            'action' => $action,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'changed_cols' => $changed === [] ? null : json_encode($changed, JSON_THROW_ON_ERROR),
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
            'reason' => Request::input('_audit_reason'),
        ]);
    }
}
