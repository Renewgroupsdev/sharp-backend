<?php

namespace App\Enums;

/** Where a lesson's content comes from (the "Source" of the lesson form). */
enum LessonHost: string
{
    case YouTube = 'YouTube';
    case VideoUpload = 'Video upload';
    case DocumentUpload = 'Document upload';
    case ImageUpload = 'Image upload';
    case Editor = 'Editor';
    case GoogleDrive = 'Google Drive';

    /** Sources whose lesson is a link, so a url is required. */
    public static function linkHosts(): array
    {
        return [self::YouTube->value, self::GoogleDrive->value];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
