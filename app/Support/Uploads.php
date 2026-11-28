<?php

namespace App\Support;

/** One place for the rules every document upload shares (assignment briefs, student submissions). */
final class Uploads
{
    /** Document and image types accepted for assessment files. */
    public const DOCUMENT_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,jpg,jpeg,png';

    /** Video types, which get a larger limit than other files. */
    public const VIDEO_EXTENSIONS = 'mp4,webm,mov';

    /** Largest single document, in kilobytes (2 MB). */
    public const MAX_KB = 2048;

    /** Largest single video, in kilobytes (5 MB). */
    public const MAX_VIDEO_KB = 5120;

    /** @return array<int, mixed> validation rules for one uploaded file: documents up to 2 MB, videos up to 5 MB */
    public static function documentRules(): array
    {
        return [
            'file',
            'mimes:'.self::DOCUMENT_EXTENSIONS.','.self::VIDEO_EXTENSIONS,
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof \Illuminate\Http\UploadedFile) {
                    return;
                }
                $video = in_array(strtolower($value->getClientOriginalExtension()), explode(',', self::VIDEO_EXTENSIONS), true);
                $max = $video ? self::MAX_VIDEO_KB : self::MAX_KB;
                if ($value->getSize() > $max * 1024) {
                    $fail($video ? 'A video must be 5 MB or smaller.' : 'A file other than a video must be 2 MB or smaller.');
                }
            },
        ];
    }

    /** "845 KB", "2.4 MB": the short size shown beside a file name. */
    public static function humanSize(int $bytes): string
    {
        return $bytes < 1048576 ? max(1, (int) round($bytes / 1024)).' KB' : number_format($bytes / 1048576, 1).' MB';
    }
}
