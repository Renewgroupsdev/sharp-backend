<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_groups', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100)->unique();
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_group_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->unsignedSmallInteger('marks')->default(1);
            $table->text('text');
            $table->longText('image')->nullable();
            $table->json('data')->nullable(); // options / correct / items / pairs / keywords ... per question type
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index(['question_group_id', 'type']);
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('title', 120);
            $table->string('course', 150); // courses are not stored by the API yet; matched by title
            $table->string('module', 150)->nullable();
            $table->string('topic', 150)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('due_date')->nullable(); // assignments and assessments only
            $table->unsignedInteger('max_marks')->default(0); // quizzes: derived from their question groups
            $table->unsignedTinyInteger('min_percent')->default(60);
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->text('description')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_size', 20)->nullable();
            $table->json('setup')->nullable(); // timing, review and answer-sheet options
            $table->timestamps();

            $table->index(['type', 'course']);
        });

        Schema::create('assessment_question_group', function (Blueprint $table) {
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['assessment_id', 'question_group_id']);
        });

        Schema::create('quiz_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('marks');
            $table->unsignedInteger('total');
            $table->unsignedTinyInteger('result_percent');
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamps();

            $table->unique(['assessment_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_results');
        Schema::dropIfExists('assessment_question_group');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_groups');
    }
};
