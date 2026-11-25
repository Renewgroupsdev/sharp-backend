<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('submission_no');
            $table->longText('content')->nullable();
            $table->string('status', 24)->default('submitted');
            $table->decimal('marks_obtained', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at');
            $table->timestampTz('graded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['assessment_id', 'student_id', 'submission_no'], 'assessment_submission_version_unique');
            $table->index(['assessment_id', 'status', 'submitted_at']);
            $table->index(['student_id', 'assessment_id', 'submission_no']);
        });

        Schema::create('submission_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('assessment_submissions')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 1024);
            $table->string('disk', 40)->default('local');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->timestampsTz();
            $table->index('submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_attachments');
        Schema::dropIfExists('assessment_submissions');
    }
};
