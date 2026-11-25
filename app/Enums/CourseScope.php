<?php

namespace App\Enums;

enum CourseScope: string
{
    case Public = 'Public';
    case Private = 'Private';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
