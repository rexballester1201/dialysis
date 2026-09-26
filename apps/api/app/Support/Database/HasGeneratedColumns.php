<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Arr;

/**
 * The two halves of living with a MySQL generated column.
 *
 * 1. Never write one. MySQL rejects any attempt, and Eloquent will try as soon
 *    as a refresh() has hydrated the value. CLAUDE.md rule 2.
 *
 * 2. Always read one back. MySQL computes them during the write, so until they
 *    are re-read they do not exist on the model at all -- which means the API
 *    answers a POST with `full_name: null` or `map_mmhg: null` for the row it
 *    just created. That was a real bug in two places before this trait existed.
 *
 * Only the generated columns are re-read, not the whole row: one narrow SELECT
 * per write rather than a full hydrate.
 *
 * Using models declare the columns:
 *
 *     protected array $generated = ['full_name'];
 */
trait HasGeneratedColumns
{
    public static function bootHasGeneratedColumns(): void
    {
        static::saving(function (self $model): void {
            $model->setRawAttributes(
                Arr::except($model->getAttributes(), $model->generatedColumns()),
                sync: false,
            );
        });

        static::created(fn (self $model) => $model->readGeneratedColumns());
        static::updated(fn (self $model) => $model->readGeneratedColumns());
    }

    /**
     * Declared by the using model as `protected array $generated = [...]`.
     *
     * @return list<string>
     */
    public function generatedColumns(): array
    {
        /** @var list<string> */
        return $this->generated;
    }

    /** Re-read just the generated columns for this row and merge them in. */
    public function readGeneratedColumns(): void
    {
        $columns = $this->generatedColumns();

        if ($columns === [] || $this->getKey() === null) {
            return;
        }

        $row = $this->newQueryWithoutScopes()
            ->getQuery()
            ->where($this->getKeyName(), $this->getKey())
            ->first($columns);

        if ($row === null) {
            return;
        }

        foreach ((array) $row as $column => $value) {
            $this->attributes[$column] = $value;
            $this->original[$column] = $value;
        }
    }
}
