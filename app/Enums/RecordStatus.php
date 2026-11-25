<?php

namespace App\Enums;

enum RecordStatus: string
{
    case Active = 'Active';
    case Inactive = 'Inactive';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
