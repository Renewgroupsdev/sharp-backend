<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Quizzes and assessments keep separate question groups (and so separate question banks). Existing groups belong to quizzes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_groups', function (Blueprint $table) {
            $table->string('type', 20)->default('quiz')->after('title');
            $table->dropUnique(['title']);
            $table->unique(['type', 'title']);
        });
    }

    public function down(): void
    {
        Schema::table('question_groups', function (Blueprint $table) {
            $table->dropUnique(['type', 'title']);
            $table->dropColumn('type');
            $table->unique('title');
        });
    }
};
