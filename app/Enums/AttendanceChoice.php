<?php

namespace App\Enums;

/** What a student reports for the day, and what the admin finally decides. */
enum AttendanceChoice: string
{
    case Present = 'Present';
    case Absent = 'Absent';

    /** An absence has to be explained. */
    public function needsReason(): bool
    {
        return $this === self::Absent;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
