<?php

declare(strict_types=1);

namespace App\Domain\Ops\Console;

use App\Domain\Ops\Services\QualitySummariser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rebuilds the dashboard's monthly rollup from v_monthly_quality.
 *
 * Scheduled nightly. The view remains the definition of record; this is a cache
 * in front of it, because the view materialises every session before it can
 * answer anything (CLAUDE.md, MySQL rule 7).
 */
final class SummariseQuality extends Command
{
    protected $signature = 'dialysis:summarise-quality
                            {--month= : A date inside the month to rebuild (default: this month and last)}';

    protected $description = 'Rebuild monthly_quality_summaries from v_monthly_quality';

    public function handle(QualitySummariser $summariser): int
    {
        $option = $this->option('month');

        if (is_string($option) && $option !== '') {
            $month = Carbon::parse($option)->startOfMonth();
            $written = $summariser->summariseMonth($month);

            $this->info('Rebuilt '.$month->toDateString().": {$written} patient row(s).");

            return self::SUCCESS;
        }

        foreach ($summariser->summariseRecent() as $month => $written) {
            $this->info("Rebuilt {$month}: {$written} patient row(s).");
        }

        return self::SUCCESS;
    }
}
