<?php

namespace Tests\Feature;

use App\Enums\LessonHost;
use App\Models\Course;
use App\Models\CourseLesson;
use Database\Seeders\BatchSeeder;
use Database\Seeders\CourseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_courses_using_every_lesson_host_and_is_repeatable(): void
    {
        $this->seed(BatchSeeder::class);
        $this->seed(CourseSeeder::class);
        $this->seed(CourseSeeder::class);

        $this->assertSame(2, Course::count());
        $this->assertEqualsCanonicalizing(LessonHost::values(), CourseLesson::all()->map(fn ($l) => $l->host->value)->unique()->values()->all());
        $this->assertTrue(Course::where('title', 'Communication Skills')->first()->batches()->exists());
        $this->assertSame(1, CourseLesson::where('name', 'Your 60-second introduction')->count());
    }
}
