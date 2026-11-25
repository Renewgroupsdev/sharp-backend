<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every attempt a student makes at a quiz. quiz_results keeps only the best score per student, so this is the
     * history behind it. An attempt is created when the student starts (which fixes the question order and the
     * deadline) and finished when they submit or the time runs out.
     */
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_no');
            $table->json('question_order');                  // question ids, in the order served
            $table->timestamp('started_at');
            $table->timestamp('expires_at')->nullable();     // null = untimed
            $table->timestamp('submitted_at')->nullable();   // null = still in progress
            $table->unsignedInteger('marks')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedTinyInteger('result_percent')->default(0);
            $table->boolean('passed')->default(false);
            $table->unsignedInteger('time_taken_sec')->default(0);
            $table->boolean('needs_manual_grading')->default(false); // long answers wait for a mentor
            $table->json('answers')->nullable();             // [{question_id, answer, marks, correct}]
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id', 'attempt_no']);
            $table->index(['student_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
