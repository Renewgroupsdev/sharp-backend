<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The instructor's assignment brief: attachment_name / attachment_size stay as the display values, the file itself is located by disk + path. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->string('attachment_path', 500)->nullable()->after('attachment_size');
            $table->string('attachment_disk', 30)->nullable()->after('attachment_path');
            $table->string('attachment_mime', 120)->nullable()->after('attachment_disk');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_disk', 'attachment_mime']);
        });
    }
};
