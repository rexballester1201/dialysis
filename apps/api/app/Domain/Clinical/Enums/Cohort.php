<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Enums;

/**
 * Infection-control cohort derived from serology. Determines which chair and
 * which machine a patient may use. HBV takes precedence over HCV.
 */
enum Cohort: string
{
    case Clean = 'clean';
    case Hbv = 'hbv';
    case Hcv = 'hcv';

    public function label(): string
    {
        return match ($this) {
            self::Clean => 'General',
            self::Hbv => 'Hepatitis B isolation',
            self::Hcv => 'Hepatitis C isolation',
        };
    }
}
