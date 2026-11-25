<?php

namespace App\Enums;

/** How a course is delivered. */
enum CourseMode: string
{
    case OnCampus = 'On Campus';
    case OffCampus = 'Off Campus';
    case IndustrialVisit = 'Industrial Visit';
    case FieldTrekking = 'Field Trekking';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
