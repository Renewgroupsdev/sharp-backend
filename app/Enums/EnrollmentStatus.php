<?php

namespace App\Enums;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/** Where an enrollment (a course run for a batch) stands, derived from the batch's dates; a closed batch is Completed. */
enum EnrollmentStatus: string
{
    case Upcoming = 'Upcoming';
    case Ongoing = 'Ongoing';
    case Completed = 'Completed';

    public static function of(mixed $start, mixed $end, ?string $batchStatus, ?Carbon $today = null): self
    {
        $today ??= today();

        return match (true) {
            $batchStatus === BatchStatus::Closed->value || ($end && Carbon::parse($end)->lt($today)) => self::Completed,
            $start && Carbon::parse($start)->gt($today) => self::Upcoming,
            default => self::Ongoing,
        };
    }

    /** The same rule as of(), as a filter on a query that joins `batches as b`. */
    public function constrain(Builder $query): Builder
    {
        $closed = BatchStatus::Closed->value;
        $done = "(b.status = '{$closed}' OR b.end_date < CURRENT_DATE)";

        return match ($this) {
            self::Completed => $query->whereRaw($done),
            self::Upcoming => $query->whereRaw("NOT {$done} AND b.start_date > CURRENT_DATE"),
            self::Ongoing => $query->whereRaw("NOT {$done} AND (b.start_date IS NULL OR b.start_date <= CURRENT_DATE)"),
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
