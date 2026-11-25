<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'Present';
    case Manual = 'Manual';
    case Absent = 'Absent';

    public function needsReason(): bool
    {
        return $this !== self::Present;
    }

    public function countsAsAttended(): bool
    {
        return $this !== self::Absent;
    }
}
