<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The lesson "host" becomes a "source": the four document hosts merge into Document upload, Images and Video URL are renamed. */
return new class extends Migration
{
    private const MAP = [
        'Video URL' => 'Video upload',
        'Images' => 'Image upload',
        'PPT file' => 'Document upload',
        'PDF file' => 'Document upload',
        'Word file' => 'Document upload',
        'Text file' => 'Document upload',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('course_lessons')->where('host', $old)->update(['host' => $new]);
        }
    }

    public function down(): void
    {
        DB::table('course_lessons')->where('host', 'Video upload')->update(['host' => 'Video URL']);
        DB::table('course_lessons')->where('host', 'Image upload')->update(['host' => 'Images']);
        // The document type is not recoverable; pick it from the file name.
        foreach (['pptx?' => 'PPT file', 'pdf' => 'PDF file', 'docx?' => 'Word file'] as $ext => $old) {
            DB::table('course_lessons')->where('host', 'Document upload')->whereRaw("file_name ~* ?", ["\\.({$ext})$"])->update(['host' => $old]);
        }
        DB::table('course_lessons')->where('host', 'Document upload')->update(['host' => 'Text file']);
    }
};
