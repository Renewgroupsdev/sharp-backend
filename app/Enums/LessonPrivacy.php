<?php

namespace App\Enums;

enum LessonPrivacy: string
{
    case Public = 'Public';
    case Enrolled = 'Enrolled students only';
    case Private = 'Private';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
