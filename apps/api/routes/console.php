<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
*/

// Invariants 1, 8 and 9 are detective: views that find violations rather than
// block them. This is the job that reads them. Without it they are documentation.
//
// Twice daily rather than nightly, because a cohort violation found at 06:00 can
// still be acted on before the morning shift is seated, and a chlorine exception
// found at 06:00 stops the first session rather than being reviewed after it.
Schedule::command('dialysis:check-controls')
    ->twiceDaily(6, 18)
    ->timezone(config('app.timezone'))
    ->onFailure(function (): void {
        // A non-zero exit means violations are outstanding, not that the job
        // broke. Route this to whoever is on for the unit.
        logger()->warning('dialysis:check-controls reported outstanding violations');
    });

// The dashboard reads monthly_quality_summaries, not v_monthly_quality: the view
// materialises every session before it can answer anything (CLAUDE.md rule 7).
// Rebuilt nightly, and it rebuilds last month too -- a late signature or an
// amendment can change a month after it has ended.
Schedule::command('dialysis:summarise-quality')
    ->dailyAt('02:30')
    ->timezone(config('app.timezone'));
