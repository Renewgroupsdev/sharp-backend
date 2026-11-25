<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A mentor (or admin) opening a module early for one student, with the reason. Without a row here a module opens
     * only when the one before it is finished (all lessons done and its quizzes passed). Keyed by chapter id so the
     * unlock survives modules being reordered.
     */
    public function up(): void
    {
        Schema::create('module_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_chapter_id')->constrained('course_chapters')->cascadeOnDelete();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['student_id', 'course_chapter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_unlocks');
    }
};
