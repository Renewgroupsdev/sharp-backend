<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/web/learning';

    private Course $course;

    private User $enrolled;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $batch = Batch::create(['name' => 'Batch 1', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $other = Batch::create(['name' => 'Batch 2', 'start_date' => '2026-06-01', 'end_date' => '2026-12-01', 'seats' => 50]);
        $this->course = Course::create(['title' => 'Communication', 'course_category_id' => CourseCategory::create(['name' => 'Soft'])->id, 'hours' => 6]);
        $this->course->batches()->sync([$batch->id]);

        $this->enrolled = User::factory()->create(['name' => 'Priya R', 'batch_id' => $batch->id, 'org' => 'Fatima College']);
        $this->outsider = User::factory()->create(['name' => 'Arun', 'batch_id' => $other->id]);
    }

    private function actAs(Role $role): void
    {
        Sanctum::actingAs(User::factory()->role($role)->create());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['student_id' => $this->enrolled->public_id, 'course_id' => $this->course->id, 'progress' => 40, 'materials_completed' => 2], $overrides);
    }

    public function test_lists_only_students_enrolled_through_a_batch_with_zero_progress_by_default(): void
    {
        $this->actAs(Role::Admin);

        $this->getJson(self::BASE)->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.name', 'Priya R')
            ->assertJsonPath('records.0.course', 'Communication')
            ->assertJsonPath('records.0.progress', 0)
            ->assertJsonPath('records.0.status', 'Not started');
    }

    public function test_save_upserts_one_row_and_derives_the_status(): void
    {
        $this->actAs(Role::Admin);

        $this->putJson(self::BASE, $this->payload())->assertOk()
            ->assertJsonPath('record.progress', 40)->assertJsonPath('record.status', 'In progress');
        $this->putJson(self::BASE, $this->payload(['progress' => 100]))->assertOk()
            ->assertJsonPath('record.status', 'Completed');

        $this->assertDatabaseCount('learning_progress', 1);
        $this->getJson(self::BASE.'?status=Completed')->assertJsonCount(1, 'records');
        $this->getJson(self::BASE.'?status=In progress')->assertJsonCount(0, 'records');
        $this->getJson(self::BASE.'?q=priya&course_id='.$this->course->id)->assertJsonCount(1, 'records');
        $this->getJson(self::BASE.'?q=nobody')->assertJsonCount(0, 'records');
    }

    public function test_rejects_unenrolled_students_and_bad_percentages(): void
    {
        $this->actAs(Role::Admin);

        $this->putJson(self::BASE, $this->payload(['student_id' => $this->outsider->public_id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->putJson(self::BASE, $this->payload(['progress' => 101]))
            ->assertUnprocessable()->assertJsonValidationErrors(['progress']);
        $this->assertDatabaseCount('learning_progress', 0);
    }

    public function test_reset_clears_progress_but_keeps_the_enrolment(): void
    {
        $this->actAs(Role::Admin);
        $this->putJson(self::BASE, $this->payload())->assertOk();

        $this->deleteJson(self::BASE.'?student_id='.urlencode($this->enrolled->public_id).'&course_id='.$this->course->id)
            ->assertOk()->assertJsonPath('record.progress', 0)->assertJsonPath('record.status', 'Not started');

        $this->assertDatabaseCount('learning_progress', 0);
        $this->getJson(self::BASE)->assertJsonCount(1, 'records');
    }

    public function test_unmapping_the_batch_removes_the_student_from_the_list(): void
    {
        $this->actAs(Role::Admin);
        $this->course->batches()->sync([]);

        $this->getJson(self::BASE)->assertOk()->assertJsonCount(0, 'records');
    }

    public function test_access_is_limited_by_role(): void
    {
        $this->actAs(Role::Mentor);
        $this->getJson(self::BASE)->assertOk();
        $this->putJson(self::BASE, $this->payload())->assertForbidden();

        $this->actAs(Role::Student);
        $this->getJson(self::BASE)->assertForbidden();
    }
}
