<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stored progress only. Who is enrolled comes from batch_course (a student is enrolled in every course
     * mapped to their batch), and the status label is derived from the percentage, so neither is stored.
     */
    public function up(): void
    {
        Schema::create('learning_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('progress')->default(0); // 0-100, overall across the course's modules
            $table->unsignedSmallInteger('materials_completed')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            $table->index(['course_id', 'progress']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_progress');
    }
};
