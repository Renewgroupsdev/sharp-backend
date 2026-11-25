<?php

namespace App\Support;

/** One place for the rules every document upload shares (assignment briefs, student submissions). */
final class Uploads
{
    /** Document and image types accepted for assessment files. */
    public const DOCUMENT_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,jpg,jpeg,png';

    /** Largest single file, in kilobytes (20 MB). */
    public const MAX_KB = 20480;

    /** @return array<int, string> validation rules for one uploaded document */
    public static function documentRules(): array
    {
        return ['file', 'max:'.self::MAX_KB, 'mimes:'.self::DOCUMENT_EXTENSIONS];
    }

    /** "845 KB", "2.4 MB": the short size shown beside a file name. */
    public static function humanSize(int $bytes): string
    {
        return $bytes < 1048576 ? max(1, (int) round($bytes / 1024)).' KB' : number_format($bytes / 1048576, 1).' MB';
    }
}
