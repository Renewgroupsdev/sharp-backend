<?php

namespace App\Enums;

enum CourseStatus: string
{
    case Draft = 'Draft';
    case Published = 'Published';
    case Archived = 'Archived';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
