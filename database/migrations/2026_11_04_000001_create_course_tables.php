<?php

use App\Enums\CourseLevel;
use App\Enums\CourseMode;
use App\Enums\CourseScope;
use App\Enums\CourseStatus;
use App\Enums\RecordStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default(RecordStatus::Active->value);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->foreignId('course_category_id')->constrained('course_categories')->restrictOnDelete();
            $table->unsignedSmallInteger('hours')->default(1);
            $table->json('mentors')->nullable(); // mentor display names, as picked in the course form
            $table->string('mode', 30)->default('Online');
            $table->string('level', 20)->default(CourseLevel::Beginner->value);
            $table->string('scope', 20)->default(CourseScope::Public->value);
            $table->string('status', 20)->default(CourseStatus::Draft->value);
            $table->text('description')->nullable();
            $table->text('outcomes')->nullable();
            $table->text('requirements')->nullable();
            $table->longText('thumbnail')->nullable(); // data URL, capped by the request rules
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'deleted_at']);
            $table->index(['course_category_id', 'deleted_at']);
            $table->index('title');
        });

        // A batch takes many courses and a course runs for many batches; every student in the batch is enrolled.
        Schema::create('batch_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['batch_id', 'course_id']);
            $table->index('course_id');
        });

        Schema::create('course_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('Published'); // Draft modules are hidden from learners
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['course_id', 'position']);
        });

        Schema::create('course_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_chapter_id')->constrained('course_chapters')->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('duration_minutes')->default(10);
            $table->string('host', 30);
            $table->string('url', 500)->nullable();
            $table->longText('content')->nullable(); // rich text, for the Editor host
            $table->string('privacy', 30);
            $table->text('description')->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('file_size', 30)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['course_chapter_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_lessons');
        Schema::dropIfExists('course_chapters');
        Schema::dropIfExists('batch_course');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_categories');
    }
};
