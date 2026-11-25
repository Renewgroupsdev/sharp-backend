<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseLesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyLearningApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web';

    private Course $course;

    private User $student;

    /** @var list<CourseLesson> */
    private array $lessons = [];

    protected function setUp(): void
    {
        parent::setUp();

        $batch = Batch::create(['name' => 'Batch 1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6, 'status' => 'Published']);
        $this->course->batches()->sync([$batch->id]);

        $m1 = $this->course->chapters()->create(['name' => 'Module 1', 'status' => 'Published', 'position' => 0]);
        $m2 = $this->course->chapters()->create(['name' => 'Module 2', 'status' => 'Published', 'position' => 1]);
        $draft = $this->course->chapters()->create(['name' => 'Draft module', 'status' => 'Draft', 'position' => 2]);

        $make = fn ($chapter, $name, $privacy = 'Enrolled students only') => $chapter->lessons()->create(['name' => $name, 'duration_minutes' => 5, 'host' => 'Editor', 'content' => '<p>x</p>', 'privacy' => $privacy]);
        $this->lessons = [$make($m1, 'L1'), $make($m1, 'L2'), $make($m2, 'L3'), $make($m2, 'L4')];
        $make($m2, 'Staff only', 'Private');
        $make($draft, 'Hidden');

        $this->student = User::factory()->create(['batch_id' => $batch->id]);
        Sanctum::actingAs($this->student);
    }

    public function test_student_sees_only_courses_mapped_to_their_batch_with_zero_progress(): void
    {
        $other = Course::create(['title' => 'Other', 'course_category_id' => $this->course->course_category_id, 'hours' => 1, 'status' => 'Published']);

        $this->getJson(self::BASE.'/my/courses')->assertOk()
            ->assertJsonCount(1, 'courses')
            ->assertJsonPath('courses.0.title', 'Communication')
            ->assertJsonPath('courses.0.lessons_total', 4) // private lesson and draft module are not counted
            ->assertJsonPath('courses.0.progress', 0);

        $this->getJson(self::BASE."/my/courses/{$other->id}")->assertNotFound();
    }

    public function test_completing_lessons_recomputes_progress_and_is_idempotent(): void
    {
        $this->postJson(self::BASE."/my/lessons/{$this->lessons[0]->id}/complete")->assertOk()
            ->assertJsonPath('progress.progress', 25)->assertJsonPath('progress.status', 'In progress');
        $this->postJson(self::BASE."/my/lessons/{$this->lessons[0]->id}/complete")->assertOk()
            ->assertJsonPath('progress.lessons_done', 1);
        $this->postJson(self::BASE."/my/lessons/{$this->lessons[1]->id}/open")->assertOk();

        foreach (array_slice($this->lessons, 1) as $lesson) {
            $this->postJson(self::BASE."/my/lessons/{$lesson->id}/complete")->assertOk();
        }
        $this->getJson(self::BASE."/my/courses/{$this->course->id}")->assertOk()
            ->assertJsonPath('progress.progress', 100)->assertJsonPath('progress.status', 'Completed')
            ->assertJsonCount(4, 'completed_lesson_ids')
            ->assertJsonCount(2, 'course.chapters') // draft module hidden
            ->assertJsonCount(2, 'course.chapters.1.lessons'); // private lesson hidden

        $this->assertDatabaseHas('learning_progress', ['user_id' => $this->student->id, 'course_id' => $this->course->id, 'progress' => 100, 'materials_completed' => 4]);
    }

    public function test_lessons_outside_the_course_or_hidden_cannot_be_completed(): void
    {
        $hidden = CourseLesson::where('name', 'Hidden')->first();
        $staffOnly = CourseLesson::where('name', 'Staff only')->first();

        $this->postJson(self::BASE."/my/lessons/{$hidden->id}/complete")->assertNotFound();
        $this->postJson(self::BASE."/my/lessons/{$staffOnly->id}/complete")->assertNotFound();
    }

    public function test_admin_list_and_module_report_reflect_the_student_progress(): void
    {
        $this->postJson(self::BASE."/my/lessons/{$this->lessons[0]->id}/complete")->assertOk();
        $this->postJson(self::BASE."/my/lessons/{$this->lessons[1]->id}/complete")->assertOk();

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

        $this->getJson(self::BASE.'/learning')->assertOk()
            ->assertJsonPath('records.0.progress', 50)->assertJsonPath('records.0.materials', 2);

        $this->getJson(self::BASE.'/learning/report?student_id='.urlencode($this->student->public_id).'&course_id='.$this->course->id)->assertOk()
            ->assertJsonCount(2, 'modules')
            ->assertJsonPath('modules.0.pct', 100)->assertJsonPath('modules.0.status', 'Completed')
            ->assertJsonPath('modules.1.done', 0)->assertJsonPath('modules.1.total', 2);
    }

    public function test_only_students_use_the_my_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

        $this->getJson(self::BASE.'/my/courses')->assertForbidden();
    }
}
