<?php

namespace App\Enums;

enum CourseLevel: string
{
    case Beginner = 'Beginner';
    case Intermediate = 'Intermediate';
    case Advanced = 'Advanced';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
