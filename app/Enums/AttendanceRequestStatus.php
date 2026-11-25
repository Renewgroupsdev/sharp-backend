<?php

namespace App\Enums;

enum AttendanceRequestStatus: string
{
    case Pending = 'Pending';
    case Resolved = 'Resolved';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
