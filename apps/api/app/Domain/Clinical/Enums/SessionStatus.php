<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Enums;

enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Aborted = 'aborted';
    case Missed = 'missed';
    case Cancelled = 'cancelled';
    case Refused = 'refused';

    /** Statuses in which the bedside tablet may still write clinical data. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::CheckedIn, self::InProgress], true);
    }

    public function occupiesChair(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Missed, self::Refused], true);
    }
}
